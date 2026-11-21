<?php

namespace App\Services\Assistant;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiQueryLog;
use App\Models\AssistantAccess;
use App\Models\Purok;
use App\Models\User;
use Throwable;

/**
 * AssistantService (SDD §4.22). Implements the request flow of Figure 4.8:
 *
 *  2. checks which query categories the user's role may use (assistant_access)
 *     and the permission behind each approved function;
 *  3. sends the question, the previous question for follow-ups, and the
 *     function catalogue (names and parameter types only — no resident data)
 *     to the model. Its reply is constrained to JSON naming ONE whitelisted
 *     function and its arguments;
 *  4. validates the function against the whitelist and the arguments against
 *     their types and allowed values — anything else is refused;
 *  5. runs the approved query function (counts computed by MySQL);
 *  6. sends only the minimized, aggregated result back to the model to word
 *     the answer. Wording that uses a number not shown in the BRIS figures is
 *     discarded and replaced by a summary built from the database figures;
 *  7. returns the answer labelled "From BRIS records" / "AI-generated
 *     recommendation" and writes ai_query_logs.
 */
class AssistantService
{
    public const CATEGORY_LABELS = [
        'residents' => 'resident and household information',
        'social_assistance' => '4Ps and social assistance records',
        'employment_skills' => 'employment and skills',
        'education' => 'educational attainment',
        'community_analysis' => 'community analysis and recommendations',
    ];

    /** Worked examples shown to the model; only those for allowed functions are used. */
    private const EXAMPLES = [
        ['How many residents are registered in our barangay?', ['function' => 'countResidents']],
        ['Show residents according to their purok.', ['function' => 'listResidents']],
        ['How many households are registered in Purok 4?', ['function' => 'countHouseholds']],
        ['Find female residents who are married and aged 60 or older.', ['function' => 'findResidentsByProfile', 'sex' => 'female', 'civil_status' => 'married', 'min_age' => 60]],
        ['How many 4Ps beneficiaries are registered?', ['function' => 'listSectorMembers', 'sector' => '4ps']],
        ['List the senior citizens in Purok 3.', ['function' => 'listSectorMembers', 'sector' => 'senior_citizen']],
        ['How many indigent families are recorded?', ['function' => 'listSectorMembers', 'sector' => 'indigent']],
        ['Show residents who received livelihood assistance.', ['function' => 'listAssistanceRecipients', 'assistance_type' => 'livelihood']],
        ['Summarize the distribution of social assistance beneficiaries.', ['function' => 'summarizeAssistance']],
        ['Which purok has the highest unemployment rate?', ['function' => 'employmentSummary']],
        ['Give me the list of unemployed residents.', ['function' => 'listUnemployed']],
        ['Find residents with welding skills.', ['function' => 'findResidentsBySkill', 'skill' => 'welding']],
        ['What are the most common occupations and skills?', ['function' => 'topOccupationsAndSkills']],
        ['How many residents are college graduates?', ['function' => 'educationSummary']],
        ['List residents who are high school graduates.', ['function' => 'listByEducation', 'level' => 'high_school_graduate']],
        ['What livelihood programs or skills training should the barangay prioritize?', ['function' => 'communityProfile']],
        ['Which purok has the most residents?', ['function' => 'listResidents']],
        ['What programs would help our senior citizens?', ['function' => 'communityProfile']],
        ['Anong programa ang makakatulong sa kabataan?', ['function' => 'communityProfile']],
        ['Unsa nga programa ang angay para sa among barangay?', ['function' => 'communityProfile']],
        ['Ilan ang senior citizen sa Purok 2?', ['function' => 'listSectorMembers', 'sector' => 'senior_citizen']],
        ['Pila ka tawo ang walay trabaho?', ['function' => 'listUnemployed']],
        ['Kinsa ang mga 4Ps sa among barangay?', ['function' => 'listSectorMembers', 'sector' => '4ps']],
        ['Sino ang marunong mag-karpintero?', ['function' => 'findResidentsBySkill', 'skill' => 'carpentry']],
        ['Ilan ang babae sa barangay?', ['function' => 'findResidentsByProfile', 'sex' => 'female']],
        ['Hello!', ['function' => 'none']],
    ];

    public function __construct(
        private readonly OllamaGateway $gateway,
        private readonly ApprovedQueryFunctions $functions,
        private readonly PurokMatcher $puroks,
    ) {}

    public function currentConversation(User $user): AiConversation
    {
        return AiConversation::where('user_id', $user->id)
            ->where('delete_after', '>', now())
            ->latest('last_message_at')
            ->first()
            ?? $this->startConversation($user);
    }

    public function startConversation(User $user): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'started_at' => now(),
            'last_message_at' => now(),
            'delete_after' => now()->addDays((int) config('services.ollama.retention_days')),
        ]);
    }

    /** Query categories enabled for the user's role by the Captain (assistant_access). */
    public function allowedCategories(User $user): array
    {
        return AssistantAccess::enabledCategoriesFor($user->role_id);
    }

    public function ask(User $user, AiConversation $conversation, string $question): AiMessage
    {
        $previous = $this->previousExchange($conversation);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'sender' => 'user', 'content' => $question]);

        $allowed = $this->allowedCategories($user);
        $functions = $this->allowedFunctions($user, $allowed);
        if ($functions === []) {
            $this->log($user, $conversation, $question, null, null, 'refused');

            return $this->reply($conversation, 'The AI-Powered Barangay Assistant is not enabled for your role. Ask the Barangay Captain to configure assistant access.', ['kind' => 'refused']);
        }

        // Requests to change records are answered plainly, before the model is involved.
        if ($this->isWriteRequest($question)) {
            $this->log($user, $conversation, $question, null, null, 'refused');

            return $this->reply($conversation, "I can only read BRIS records — I can't add, change, or delete anything. To change a record, open it from Residents & Households (or the matching module) and edit it there.", ['kind' => 'refused']);
        }

        // Contact and identity details are never listed through the assistant.
        if ($this->asksForPersonalDetails($question)) {
            $this->log($user, $conversation, $question, null, null, 'refused');

            return $this->reply($conversation, "I don't list personal details such as birth dates, addresses, or contact numbers. Open the resident's record in Residents & Households to see them — that access is logged.", ['kind' => 'refused']);
        }

        // Purok scope is read from the question by text matching, not by the model.
        $mentions = $this->puroks->scan($question);
        if ($mentions['unknown'] !== [] && $mentions['found']->isEmpty()) {
            $this->log($user, $conversation, $question, null, null, 'refused');

            return $this->reply($conversation, $this->unknownPurokText($mentions['unknown']), ['kind' => 'refused']);
        }

        $followUp = $this->followUpCall($question, $previous, $mentions);

        try {
            // Follow-ups ("who are they?", "how about Purok 3?") reuse the previous
            // query in code; the small model was unreliable at this. Otherwise the
            // model identifies the request against the full catalogue (names and
            // parameter types only), so a question outside the user's access is
            // recognised and refused by runCall() with the right reason.
            $call = $followUp ?? $this->selectFunction(ApprovedQueryFunctions::definitions(), $previous, $question);
        } catch (AiServiceUnavailable $e) {
            // The user sees a plain "temporarily unavailable"; the cause goes to the server log only (SDD §5.4).
            \Illuminate\Support\Facades\Log::warning('AI assistant unavailable: '.$e->getMessage());
            $this->log($user, $conversation, $question, null, null, 'unavailable');

            return $this->reply($conversation, 'The AI-Powered Barangay Assistant is temporarily unavailable. Please try again later — all other BRIS functions continue to work.', ['kind' => 'unavailable']);
        }

        if ($call === null) {
            // No approved function chosen: the model never answers about data from memory.
            $this->log($user, $conversation, $question, null, null, 'answered');

            return $this->reply($conversation, $this->helpText($allowed), ['kind' => 'help']);
        }

        $outcome = $this->runCall($user, $allowed, $call, $question, $mentions, $previous, $followUp !== null || $this->soundsLikeFollowUp($question));
        if (is_string($outcome)) {
            $this->log($user, $conversation, $question, null, $call, 'refused');

            return $this->reply($conversation, $outcome, ['kind' => 'refused', 'calls' => [['function' => $call['name'], 'parameters' => $call['arguments']]]]);
        }

        $this->log($user, $conversation, $question, $outcome, $call, $outcome->missing ? 'no_data' : 'answered');

        $wording = $this->wordAnswer($question, $outcome);
        if ($outcome->scopeWarning) {
            $wording['summary'] = $outcome->scopeWarning.' '.$wording['summary'];
        }

        return $this->reply($conversation, $wording['summary'], [
            'kind' => 'answer',
            'summary_source' => $wording['summary_source'],
            'results' => [$outcome->forDisplay()],
            'recommendations' => $wording['recommendations'],
            'calls' => [['function' => $outcome->function, 'parameters' => $this->printableParams($outcome->parameters)]],
        ], $outcome->function);
    }

    // ------------------------------------------------------- function choice

    /** @return array<string, array> allowed function name => definition */
    private function allowedFunctions(User $user, array $allowed): array
    {
        return array_filter(
            ApprovedQueryFunctions::definitions(),
            fn ($d) => in_array($d['category'], $allowed, true) && $user->hasPermission($d['permission'])
        );
    }

    /**
     * Step 3: the model identifies the request. Its output is constrained by a
     * JSON schema whose "function" field can only be a whitelisted name.
     *
     * @return array{name: string, arguments: array}|null
     */
    private function selectFunction(array $functions, ?array $previous, string $question): ?array
    {
        $catalogue = collect($functions)->map(function ($d, $name) {
            $params = collect($d['params'])->map(function ($spec, $param) {
                $type = isset($spec['enum']) ? implode('|', $spec['enum']) : $spec['type'];

                return $param.(empty($spec['required']) ? '?' : '').": {$type}";
            })->implode(', ');

            return "- {$name}({$params}): {$d['description']}";
        })->implode("\n");

        $examples = collect(self::EXAMPLES)
            ->filter(fn ($e) => $e[1]['function'] === 'none' || isset($functions[$e[1]['function']]))
            ->map(fn ($e) => "Q: {$e[0]}\nA: ".json_encode($e[1]))
            ->implode("\n");

        $system = <<<PROMPT
        You route questions for the Barangay Resident Information System (BRIS) of Barangay San Isidro to ONE approved query function.
        Reply only with JSON: {"function": <name or "none">, plus that function's arguments}.

        Functions:
        {$catalogue}

        Rules:
        - Pick the single function that answers the question. Use "none" only for greetings or questions unrelated to barangay records.
        - Include an argument only when the user stated it. Do not include "purok" at all; the system reads it from the question.
        - Do not send min_age or max_age unless the user gave an age.
        - For a follow-up question ("how about Purok 2?", "list them", "there"), reuse the previous function and arguments, changing only what the user changed.

        Examples:
        {$examples}
        PROMPT;

        $user = $previous
            ? "Previous question: {$previous['question']}\nPrevious call: ".json_encode(['function' => $previous['function']] + $previous['parameters'])."\nPrevious answer: {$previous['answer']}\n\nQuestion: {$question}"
            : "Question: {$question}";

        $properties = ['function' => ['type' => 'string', 'enum' => array_merge(array_keys($functions), ['none'])]];
        foreach ($functions as $d) {
            foreach ($d['params'] as $param => $spec) {
                $properties[$param] ??= array_filter(['type' => $spec['type'], 'enum' => $spec['enum'] ?? null]);
                if (isset($properties[$param]['enum'], $spec['enum']) && $properties[$param]['enum'] !== $spec['enum']) {
                    $properties[$param]['enum'] = array_values(array_unique(array_merge($properties[$param]['enum'], $spec['enum'])));
                }
            }
        }

        $reply = $this->gateway->chat(
            [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            [],
            ['type' => 'object', 'properties' => $properties, 'required' => ['function']],
        );

        $parsed = json_decode($reply['content'], true);
        $name = is_array($parsed) ? ($parsed['function'] ?? 'none') : 'none';
        if (! is_string($name) || $name === 'none' || $name === '') {
            return null;
        }

        unset($parsed['function']);

        return ['name' => $name, 'arguments' => $parsed];
    }

    /** @return QueryResult|string the result, or the refusal message */
    private function runCall(User $user, array $allowed, array $call, string $question, array $mentions, ?array $previous, bool $isFollowUp): QueryResult|string
    {
        $definitions = ApprovedQueryFunctions::definitions();
        $name = $call['name'];

        // Step 4: whitelist and permission check — the model never decides who sees what.
        if (! isset($definitions[$name])) {
            return 'That request is not one of the approved assistant queries, so it was not run.';
        }
        $definition = $definitions[$name];
        if (! in_array($definition['category'], $allowed, true) || ! $user->hasPermission($definition['permission'])) {
            return 'Your role does not have access to '.self::CATEGORY_LABELS[$definition['category']].' through the assistant.';
        }

        // The purok is never taken from the model: it either dropped a purok the
        // user named or invented one. It comes from the question text, or — for a
        // follow-up that names none — from the question being followed up.
        $arguments = $call['arguments'];
        unset($arguments['purok']);

        try {
            $params = $this->functions->validate($name, $arguments);
        } catch (InvalidQueryArguments $e) {
            return $e->getMessage();
        }

        // Numbers (year, age, limit) are kept only when the user said them; the
        // model invented "year 2023" for "What programs would help our seniors?".
        $spoken = $this->numbersIn([$question, $isFollowUp ? ($previous['parameters'] ?? []) : []]);
        foreach ($params as $param => $value) {
            if (is_int($value) && ($definition['params'][$param]['type'] ?? null) === 'integer' && ! isset($spoken[(string) $value])) {
                unset($params[$param]);
            }
        }

        // Filters the model added that the question never mentions (it added
        // "registered voters" to "How many female residents are in Purok 2?").
        if (! $isFollowUp) {
            foreach ($params as $param => $value) {
                $pattern = self::FILTER_EVIDENCE[$param][$value] ?? null;
                if ($pattern !== null && ! preg_match('/'.$pattern.'/iu', $question)) {
                    unset($params[$param]);
                }
            }
        }

        $found = $mentions['found'];
        $acceptsPurok = isset($definition['params']['purok']);
        $scopeWarning = null;

        if ($found->count() > 1) {
            return 'Please ask about one purok at a time (you mentioned '.$found->pluck('name')->implode(' and ').'). To compare all puroks, ask for example "Show residents according to their purok".';
        }
        if ($found->count() === 1) {
            if ($acceptsPurok) {
                $params['purok'] = $found->first();
            } else {
                // Answer, but never let a barangay-wide figure pass as the purok's.
                $scopeWarning = "This can't be filtered by purok, so the figures are for the whole barangay, not only {$found->first()->name}.";
            }
        } elseif ($acceptsPurok && $isFollowUp && ($previousPurok = $previous['parameters']['purok'] ?? null)) {
            if ($purok = $this->puroks->byName((string) $previousPurok)) {
                $params['purok'] = $purok;
            }
        }

        $result = $this->functions->run($name, $params, $user);
        $result->scopeWarning = $scopeWarning;

        return $result;
    }

    /**
     * Words (English, Filipino, Cebuano) that must appear in the question for a
     * filter value chosen by the model to be applied.
     */
    private const FILTER_EVIDENCE = [
        'sex' => [
            'female' => '\\b(female|females|wom[ae]n|girls?|ladies|lady|babae|babaye|kababaihan|bayi|mga babae)\\b',
            'male' => '\\b(male|males|m[ae]n|boys?|lalaki|lalake|kalalakihan|mga lalaki)\\b',
        ],
        'civil_status' => [
            'single' => '\\b(single|unmarried|dalaga|binata|ulitawo|walang asawa|walay asawa)\\b',
            'married' => '\\b(married|kasal|minyo|may asawa|naay asawa|asawa)\\b',
            'widowed' => '\\b(widow|widows|widowed|widower|biyuda|byuda|biyudo|balo)\\b',
            'separated' => '\\b(separated|hiwalay|bulag)\\b',
            'divorced' => '\\b(divorced?)\\b',
            'annulled' => '\\b(annull?ed|annulment)\\b',
        ],
        'voter_status' => [
            'registered' => '\\b(voters?|botante|registered|rehistrado|nakarehistro)\\b',
            'not_registered' => '\\b(voters?|botante|registered|rehistrado|nakarehistro)\\b',
        ],
        'assistance_type' => [
            'medical' => '\\b(medical|medikal|health|hospital|medicine|gamot|tambal|ospital)\\b',
            'educational' => '\\b(educational|education|school|scholar\\w*|eskwela|tuition|edukasyon)\\b',
            'livelihood' => '\\b(livelihood|kabuhayan|panginabuhian|negosyo|business|capital|puhunan)\\b',
            'other' => '\\b(other|iba|uban)\\b',
        ],
    ];

    // ------------------------------------------------------------- follow-ups

    /** Count queries whose natural "who are they?" follow-up is a different (list) query. */
    private const LIST_FOLLOW_UP = [
        'countResidents' => 'listResidents',
        'employmentSummary' => 'listUnemployed',
    ];

    /** Words that may surround a purok in "how about Purok 3?" (English, Filipino, Cebuano). */
    private const FILLER_WORDS = ['how', 'about', 'what', 'and', 'in', 'at', 'for', 'the', 'then', 'there', 'sa', 'ang', 'man', 'kumusta', 'kamusta',
        'ug', 'unsa', 'paano', 'naman', 'po', 'pud', 'sad', 'usab', 'daw', 'diay', 'ha', 'na', 'nga', 'kay', 'ba', 'kana', 'ito', 'yung', 'yon', 'sana'];

    /** Whole questions that mean "list the people from the previous answer". */
    private const LIST_PHRASES = [
        'who are they', 'who are those', 'who are these', 'who are they all', 'who', 'list them', 'list those', 'list them all', 'list',
        'show them', 'show me them', 'show the list', 'show me the list', 'show names', 'show me their names', 'their names', 'names',
        'what are their names', 'give me the names', 'give me the list', 'can i see them', 'sino sila', 'sinu sino sila', 'sinu sino', 'sino sino',
        'sino sino sila', 'sino ang mga ito', 'kinsa sila', 'kinsa kinsa', 'kinsa kinsa sila', 'unsa ilang ngalan', 'unsay ilang ngalan',
        'ipakita', 'ipakita sila', 'pakita', 'ipakita ang listahan', 'listahan', 'ilista',
    ];

    /**
     * A follow-up resolved without the model: "who are they?" lists the previous
     * result; "how about Purok 3?" repeats the previous query for that purok.
     *
     * @return array{name: string, arguments: array}|null
     */
    private function followUpCall(string $question, ?array $previous, array $mentions): ?array
    {
        if (! $previous || ! $previous['function']) {
            return null;
        }
        $arguments = $previous['parameters'];

        if ($mentions['found']->isEmpty() && in_array(implode(' ', $this->words($question)), self::LIST_PHRASES, true)) {
            return ['name' => self::LIST_FOLLOW_UP[$previous['function']] ?? $previous['function'], 'arguments' => $arguments];
        }

        if ($mentions['found']->count() === 1) {
            $rest = $this->words(str_ireplace($mentions['spans'], ' ', $question));
            if (array_diff($rest, self::FILLER_WORDS) === []) {
                return ['name' => $previous['function'], 'arguments' => $arguments];
            }
        }

        return null;
    }

    /** Short questions leaning on the previous one ("and the seniors there?", "how about them?"). */
    private function soundsLikeFollowUp(string $question): bool
    {
        $words = $this->words($question);

        return count($words) <= 8 && array_intersect($words, ['them', 'they', 'those', 'these', 'there', 'their', 'sila', 'nila', 'didto', 'diha', 'doon', 'dun', 'dito', 'dinhi']) !== [];
    }

    /** @return list<string> lowercase words, punctuation removed */
    private function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [], fn ($w) => $w !== ''));
    }

    // --------------------------------------------------------- plain answers

    /** "Delete…", "Add…", "Burahin…", "Tangtanga…": requests to change records. */
    private function isWriteRequest(string $question): bool
    {
        $verbs = 'delete|remove|erase|edit|update|change|modify|add|register|create|archive|restore|merge|transfer|insert|encode|save|'
            .'burahin|tanggalin|alisin|palitan|baguhin|idagdag|irehistro|i-?delete|i-?update|i-?edit|i-?add|i-?register|i-?archive|'
            .'papasa|tangtanga|tangtangon|wagtanga|ilisi|ilisan|usba|usbon|idugang|dugangi';

        return (bool) preg_match('/^\s*(?:please\s+|pls\s+|paki-?\s*|palihug\s+|can you\s+|could you\s+|i want to\s+|i need to\s+)?(?:'.$verbs.')\b(?!\s+(?:in|of|over|rate|history|log)\b)/iu', $question)
            || (bool) preg_match('/\b(?:i-delete|i-update|i-edit|burahin|tanggalin|tangtangon|tangtanga)\b/iu', $question);
    }

    /** "Show everyone's birth dates and addresses", "ibigay ang address ng lahat"… */
    private function asksForPersonalDetails(string $question): bool
    {
        $field = '(?:birth\s*-?\s*dates?|birthdays?|dates? of birth|addresses|address|phone|mobile|contact\s*(?:numbers?|details|info)?|cellphone|email|kaarawan|petsa ng kapanganakan|adres|numero|selpon)';
        $listing = '(?:show|list|give|send|export|display|all|everyone|everybody|each|every|ipakita|ibigay|lahat|tanan|tanang|ilista)';

        return (bool) preg_match('/\b'.$field.'\b/iu', $question) && (bool) preg_match('/\b'.$listing.'\b/iu', $question);
    }

    private function unknownPurokText(array $unknown): string
    {
        $first = $unknown[0];
        if ($first['suggestions'] !== []) {
            return 'Which one do you mean: '.implode(' or ', $first['suggestions']).'? Please ask again with the full purok name.';
        }

        return 'There is no '.ucfirst($first['text']).' in BRIS. The puroks are: '.$this->puroks->puroks()->pluck('name')->implode(', ').'.';
    }

    private function previousExchange(AiConversation $conversation): ?array
    {
        $lastAnswer = $conversation->messages()->where('sender', 'assistant')->reorder('id', 'desc')->first();
        $call = $lastAnswer?->records['calls'][0] ?? null;
        if (! $call) {
            return null;
        }

        $lastQuestion = $conversation->messages()->where('sender', 'user')->where('id', '<', $lastAnswer->id)->reorder('id', 'desc')->first();

        return [
            'question' => $lastQuestion?->content ?? '',
            'answer' => mb_substr($lastAnswer->content, 0, 400),
            'function' => $call['function'],
            'parameters' => $call['parameters'] ?? [],
        ];
    }

    // ---------------------------------------------------------------- wording

    /**
     * Step 6: the model sees only the minimized facts. Its summary is kept only
     * when every number in it is one of the figures shown "From BRIS records",
     * it states at least one of them, and it names no purok other than the one
     * queried; otherwise the system writes the summary from those figures.
     * Results with more than two figures, and community analysis, always use the
     * system summary. Recommendations come from rules; the model only picks
     * which ones fit the question.
     */
    private function wordAnswer(string $question, QueryResult $result): array
    {
        $fallback = $this->plainSummary($result);
        $plain = ['summary' => $fallback, 'summary_source' => 'system', 'recommendations' => []];

        if ($result->missing && $result->figures === []) {
            return $plain;
        }

        if ($result->category === 'community_analysis') {
            return ['recommendations' => $this->chooseRecommendations($question, $result)] + $plain;
        }

        // With several figures, the small model mixed them up ("4 residents, all
        // aged 60+") in ways the number check cannot see; state them plainly.
        $numericFigures = array_filter($result->figures, fn ($f) => preg_match('/\d/', (string) $f['value']));
        if (count($numericFigures) > 2) {
            return $plain;
        }

        $instructions = <<<PROMPT
        You write the answer for the BRIS assistant using ONLY the data given. Reply with JSON {"summary": "..."}. Rules:
        - "summary": 1 to 3 short sentences answering the question. Use the numbers exactly as they appear under "figures". Do not compute, round, or invent numbers.
        - Never write a person's name. Names are shown to the user in a separate table.
        - If "missing_data" is present, say clearly what is not recorded.
        - If 4Ps is mentioned, say the information is based on barangay records, not the official DSWD 4Ps database.
        - Do not describe figures with words like high, low, many, few, or most; state the figures.
        - "scope" says where the figures are from. Say it in the summary ("in Purok 2" or "in the whole barangay"). Never name any other purok.
        PROMPT;

        $data = ['scope' => $this->scopeOf($result), 'figures' => array_map(fn ($f) => "{$f['label']}: {$f['value']}", $result->figures)] + $result->forModel();

        try {
            $reply = $this->gateway->chat([
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Question: {$question}\n\nData:\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            ], [], [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
            ]);
            $parsed = json_decode($reply['content'], true);
        } catch (Throwable) {
            $parsed = null;
        }

        $figureNumbers = $this->numbersIn([array_column($result->figures, 'value'), array_column($result->figures, 'label'), $question, $result->missing, $result->notes]);

        $summary = is_array($parsed) ? trim((string) ($parsed['summary'] ?? '')) : '';
        if ($summary === '' || ! $this->onlyUsesNumbers($summary, $figureNumbers) || ! $this->statesHeadline($summary, $result) || ! $this->keepsScope($summary, $result)) {
            return $plain;
        }

        return ['summary' => $summary, 'summary_source' => 'ai', 'recommendations' => []];
    }

    private function scopeOf(QueryResult $result): string
    {
        $purok = $result->parameters['purok'] ?? null;

        return $purok instanceof Purok ? $purok->name : 'whole barangay';
    }

    /**
     * The wording may name only the purok the query was actually filtered by.
     * (The number check alone let "2 female residents in Purok 2" through when
     * 2 was the barangay-wide count.)
     */
    private function keepsScope(string $summary, QueryResult $result): bool
    {
        $mentions = $this->puroks->scan($summary);
        if ($mentions['unknown'] !== []) {
            return false;
        }
        $applied = $result->parameters['purok'] ?? null;
        if ($applied instanceof Purok && preg_match('/\b(whole|entire|all of the|buong|tibuok)\s+barangay\b|\bbarangay-?wide\b/iu', $summary)) {
            return false;
        }
        // Puroks named in the figures themselves ("Purok with the most residents:
        // Purok 1-A (4)") may be repeated; any other purok is a scope claim.
        $inFigures = $this->puroks->scan(json_encode($result->figures, JSON_UNESCAPED_UNICODE))['found']->pluck('id')->all();

        return $mentions['found']->every(fn (Purok $p) => ($applied instanceof Purok && $p->id === $applied->id) || in_array($p->id, $inFigures, true));
    }

    /**
     * The model chooses up to four of the rule-based options, most relevant to
     * the question first. If it is unavailable or returns nothing usable, the
     * options are shown in rule order.
     *
     * @return list<array{text: string, based_on: list<string>}>
     */
    private function chooseRecommendations(string $question, QueryResult $result): array
    {
        if ($result->options === []) {
            return [];
        }

        $chosen = [];
        try {
            $reply = $this->gateway->chat([
                ['role' => 'system', 'content' => "Choose the recommendations that best answer the Barangay Captain's question, most relevant first (at most 4). Reply only with JSON: {\"chosen\": [ids]}. Use only the ids given."],
                ['role' => 'user', 'content' => "Question: {$question}\n\nOptions:\n".collect($result->options)->map(fn ($o, $id) => "{$id}: {$o['text']}")->implode("\n")],
            ], [], [
                'type' => 'object',
                'properties' => ['chosen' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($result->options)]]],
                'required' => ['chosen'],
            ]);
            $parsed = json_decode($reply['content'], true);
            $chosen = array_values(array_unique(array_filter((array) ($parsed['chosen'] ?? []), fn ($id) => is_string($id) && isset($result->options[$id]))));
        } catch (Throwable) {
            $chosen = [];
        }
        if ($chosen === []) {
            $chosen = array_keys($result->options);
        }

        return array_map(fn ($id) => $result->options[$id], array_slice($chosen, 0, 4));
    }

    /**
     * The summary must state at least one figure, so it actually answers. (Not
     * necessarily the first: for "which purok has the most?" the answer is the
     * top-purok figure, not the barangay total.)
     */
    private function statesHeadline(string $summary, QueryResult $result): bool
    {
        $stated = $this->numbersIn(str_ireplace($this->puroks->scan($summary)['spans'], ' ', $summary));
        $any = false;
        foreach ($result->figures as $figure) {
            if (preg_match_all('/\d+(?:\.\d+)?/', (string) $figure['value'], $m)) {
                $any = true;
                foreach ($m[0] as $number) {
                    if (isset($stated[$this->normalizeNumber($number)])) {
                        return true;
                    }
                }
            }
        }

        return ! $any;
    }

    private function plainSummary(QueryResult $result): string
    {
        $parts = [];
        if ($result->missing) {
            $parts[] = $result->missing;
        }
        foreach ($result->figures as $figure) {
            $parts[] = "{$figure['label']}: {$figure['value']}.";
        }
        $text = trim(implode(' ', $parts)) ?: 'Here is what BRIS records show.';

        // Always say where the figures are from, unless the labels already do.
        $scope = $this->scopeOf($result);
        if ($result->figures !== [] && ! str_contains($text, $scope === 'whole barangay' ? 'whole barangay' : $scope)) {
            $text = ($scope === 'whole barangay' ? 'Whole barangay' : $scope).' — '.$text;
        }

        return $text;
    }

    /** All numbers (normalized) that appear anywhere in the data. */
    private function numbersIn(mixed $data): array
    {
        $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $m);

        return array_flip(array_map(fn ($n) => $this->normalizeNumber(str_replace(',', '', $n)), $m[0]));
    }

    private function onlyUsesNumbers(string $text, array $allowed): bool
    {
        foreach (array_keys($this->numbersIn($text)) as $number) {
            if (! isset($allowed[$number])) {
                return false;
            }
        }

        return true;
    }

    private function normalizeNumber(string $n): string
    {
        if (str_contains($n, '.')) {
            return rtrim(rtrim($n, '0'), '.');
        }

        return ltrim($n, '0') ?: '0';
    }

    private function helpText(array $allowed): string
    {
        $list = implode(', ', array_map(fn ($c) => self::CATEGORY_LABELS[$c], $allowed));

        return "I answer questions from BRIS records about {$list}. For example: \"How many residents are registered?\", \"List the senior citizens in Purok 2\", \"Which purok has the highest unemployment rate?\", or \"What livelihood programs do our records suggest?\"";
    }

    // -------------------------------------------------------- persistence / log

    private function reply(AiConversation $conversation, string $content, array $records, ?string $function = null): AiMessage
    {
        $conversation->update(['last_message_at' => now()]);

        return AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'sender' => 'assistant',
            'content' => $content,
            'records' => $records,
            'function_called' => $function,
        ]);
    }

    private function log(User $user, AiConversation $conversation, string $question, ?QueryResult $result, ?array $call, string $outcome): void
    {
        $definition = $call ? (ApprovedQueryFunctions::definitions()[$call['name']] ?? null) : null;

        AiQueryLog::create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversation->id,
            'question' => mb_substr($question, 0, 1000),
            'query_category' => $result?->category ?? $definition['category'] ?? null,
            'function_called' => $call ? mb_substr($call['name'], 0, 255) : null,
            'parameters' => $result ? $this->printableParams($result->parameters) : ($call['arguments'] ?? null),
            'personal_data_types' => $result?->personalDataTypes,
            'row_count' => $result?->rowCount ?? 0,
            'sent_to_model' => $result ? 'aggregated' : 'none',
            'outcome' => $outcome,
        ]);
    }

    private function printableParams(array $params): array
    {
        return array_map(fn ($v) => $v instanceof Purok ? $v->name : $v, $params);
    }
}
