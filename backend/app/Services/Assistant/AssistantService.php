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
        ['How many households are registered in Purok 4?', ['function' => 'countHouseholds', 'purok' => 'Purok 4']],
        ['Find female residents who are married and aged 60 or older.', ['function' => 'findResidentsByProfile', 'sex' => 'female', 'civil_status' => 'married', 'min_age' => 60]],
        ['How many 4Ps beneficiaries are registered?', ['function' => 'listSectorMembers', 'sector' => '4ps']],
        ['List the senior citizens in Purok 3.', ['function' => 'listSectorMembers', 'sector' => 'senior_citizen', 'purok' => 'Purok 3']],
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
        ['Hello!', ['function' => 'none']],
    ];

    public function __construct(
        private readonly OllamaGateway $gateway,
        private readonly ApprovedQueryFunctions $functions,
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

        try {
            // The model identifies the request against the full catalogue (names and
            // parameter types only), so a question outside the user's access is
            // recognised and refused by runCall() with the right reason, instead of
            // being forced onto an unrelated function.
            $call = $this->selectFunction(ApprovedQueryFunctions::definitions(), $previous, $question);
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

        $outcome = $this->runCall($user, $allowed, $call, $question, $previous);
        if (is_string($outcome)) {
            $this->log($user, $conversation, $question, null, $call, 'refused');

            return $this->reply($conversation, $outcome, ['kind' => 'refused', 'calls' => [['function' => $call['name'], 'parameters' => $call['arguments']]]]);
        }

        $this->log($user, $conversation, $question, $outcome, $call, $outcome->missing ? 'no_data' : 'answered');

        $wording = $this->wordAnswer($question, $outcome);

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
        - Include an argument only when the user stated it. Never guess a purok.
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
    private function runCall(User $user, array $allowed, array $call, string $question, ?array $previous): QueryResult|string
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

        try {
            $params = $this->functions->validate($name, $call['arguments']);
        } catch (InvalidQueryArguments $e) {
            return $e->getMessage();
        }

        // A purok is only applied when the user actually named it (now or in the
        // question being followed up); otherwise the model invented it.
        if (isset($params['purok']) && ! $this->purokWasMentioned($params['purok'], $question, $previous)) {
            unset($params['purok']);
        }

        return $this->functions->run($name, $params, $user);
    }

    private function purokWasMentioned(Purok $purok, string $question, ?array $previous): bool
    {
        $texts = [$question];
        if ($previous) {
            $texts[] = $previous['question'];
            $texts[] = $previous['answer'];
            if (($previous['parameters']['purok'] ?? null) === $purok->name) {
                return true;
            }
        }

        $number = $purok->purok_number ?: preg_replace('/\D+/', '', $purok->name);

        foreach ($texts as $text) {
            if (mb_stripos($text, $purok->name) !== false) {
                return true;
            }
            if ($number !== '' && preg_match('/\bpurok\s*(?:no\.?|#)?\s*'.preg_quote((string) $number, '/').'\b/i', $text)) {
                return true;
            }
        }

        return false;
    }

    private function previousExchange(AiConversation $conversation): ?array
    {
        $lastAnswer = $conversation->messages()->where('sender', 'assistant')->latest('id')->first();
        $call = $lastAnswer?->records['calls'][0] ?? null;
        if (! $call) {
            return null;
        }

        $lastQuestion = $conversation->messages()->where('sender', 'user')->where('id', '<', $lastAnswer->id)->latest('id')->first();

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
     * when every number in it is one of the figures shown "From BRIS records"
     * (and it states the headline figure); otherwise the system writes the
     * summary from those figures. Recommendations are kept only when every
     * number they cite comes from the aggregated data.
     */
    private function wordAnswer(string $question, QueryResult $result): array
    {
        $fallback = $this->plainSummary($result);
        $wantsRecommendations = $result->category === 'community_analysis' && ! $result->missing;

        if ($result->missing && $result->figures === []) {
            return ['summary' => $fallback, 'summary_source' => 'system', 'recommendations' => []];
        }

        $instructions = <<<PROMPT
        You write the answer for the BRIS assistant using ONLY the data given. Rules:
        - "summary": 1 to 3 short sentences answering the question. Use the numbers exactly as they appear under "figures". Do not compute, round, or invent numbers.
        - Never write a person's name. Names are shown to the user in a separate table.
        - If "missing_data" is present, say clearly what is not recorded.
        - If 4Ps is mentioned, say the information is based on barangay records, not the official DSWD 4Ps database.
        PROMPT;
        $instructions .= $wantsRecommendations
            ? "\n- \"recommendations\": 2 to 4 suggestions for livelihood programs, skills training, educational support, or other community programs that the evidence supports. Each has \"text\" (one complete sentence saying what program to run and for which residents, with no numbers in it) and \"evidence\": the ids (E1, E2, ...) of the evidence items it is based on. Only recommend what the evidence supports. These are decision support for the Barangay Captain only."
            : "\n- \"recommendations\": an empty list.";

        $data = ['figures' => array_map(fn ($f) => "{$f['label']}: {$f['value']}", $result->figures)] + $result->forModel();
        if ($wantsRecommendations) {
            // The model reasons over labelled evidence items instead of raw facts.
            $data = ['evidence' => $result->evidence] + array_diff_key($data, ['facts' => true]);
        }

        try {
            $reply = $this->gateway->chat([
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Question: {$question}\n\nData:\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            ], [], [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'recommendations' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'evidence' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($result->evidence) ?: ['none']]],
                        ],
                        'required' => ['text', 'evidence'],
                    ]],
                ],
                'required' => ['summary', 'recommendations'],
            ]);
            $parsed = json_decode($reply['content'], true);
        } catch (Throwable) {
            $parsed = null;
        }

        $figureNumbers = $this->numbersIn([array_column($result->figures, 'value'), array_column($result->figures, 'label'), $question, $result->missing, $result->notes]);

        $summary = is_array($parsed) ? trim((string) ($parsed['summary'] ?? '')) : '';
        $summarySource = 'ai';
        if ($summary === '' || ! $this->onlyUsesNumbers($summary, $figureNumbers) || ! $this->statesHeadline($summary, $result)) {
            $summary = $fallback;
            $summarySource = 'system';
        }

        // A recommendation is kept only if its own text cites no numbers and it
        // points to at least one evidence item. The "based on" figures shown to
        // the user are the database values of those items (SRS §3.2.3.6).
        $recommendations = [];
        if ($wantsRecommendations && is_array($parsed)) {
            foreach (array_slice($parsed['recommendations'] ?? [], 0, 4) as $rec) {
                $text = trim((string) ($rec['text'] ?? ''));
                $ids = array_filter((array) ($rec['evidence'] ?? []), 'is_string');
                $evidence = array_values(array_intersect_key($result->evidence, array_flip($ids)));
                if ($text !== '' && $evidence !== [] && $this->numbersIn($text) === []) {
                    $recommendations[] = ['text' => $text, 'based_on' => $evidence];
                }
            }
        }

        return ['summary' => $summary, 'summary_source' => $summarySource, 'recommendations' => $recommendations];
    }

    /** The headline (first numeric) figure must appear in the AI's summary. */
    private function statesHeadline(string $summary, QueryResult $result): bool
    {
        foreach ($result->figures as $figure) {
            if (preg_match('/\d+(?:\.\d+)?/', (string) $figure['value'], $m)) {
                return isset($this->numbersIn($summary)[$this->normalizeNumber($m[0])]);
            }
        }

        return true;
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

        return trim(implode(' ', $parts)) ?: 'Here is what BRIS records show.';
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
