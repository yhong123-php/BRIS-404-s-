<?php

namespace App\Services\Assistant;

use App\Models\Purok;
use Illuminate\Support\Collection;

/**
 * Finds purok mentions in free text without the language model.
 *
 * The small local model often drops or invents the purok argument, which made
 * the assistant answer for the whole barangay while the user asked about one
 * purok. Purok scope is therefore decided here, by plain text matching:
 * "Purok 1-A", "purok 1a", "prk. 2", "sa Purok 2", "Purok No. 3", or a purok's
 * full name. A mention that matches no purok is reported as unknown, with
 * suggestions when the number is ambiguous ("Purok 1" → 1-A or 1-B).
 */
class PurokMatcher
{
    private const PATTERN = '/\b(?:purok|prk)\.?\s*(?:no\.?|number|num\.?|#)?\s*0*(\d{1,3})(?:\s*-?\s*([a-z])(?![a-z]))?/iu';

    /** @var Collection<int, Purok>|null */
    private ?Collection $puroks = null;

    /**
     * @return array{found: Collection<int, Purok>, unknown: list<array{text: string, suggestions: list<string>}>, spans: list<string>}
     */
    public function scan(string $text): array
    {
        $found = collect();
        $unknown = [];
        $spans = [];
        $byKey = $this->puroks()->keyBy(fn (Purok $p) => $this->key($p));

        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $spans[] = $m[0];
                $key = $m[1].strtolower($m[2] ?? '');
                if ($purok = $byKey->get($key)) {
                    $found->push($purok);

                    continue;
                }
                // "purok 2 a…" may have swallowed the start of a word; retry without the letter.
                if (isset($m[2]) && $m[2] !== '' && ($purok = $byKey->get($m[1]))) {
                    $found->push($purok);

                    continue;
                }
                $suggestions = $this->puroks()
                    ->filter(fn (Purok $p) => preg_match('/^'.preg_quote($m[1], '/').'[a-z]$/', $this->key($p)))
                    ->pluck('name')->values()->all();
                $unknown[] = ['text' => trim($m[0]), 'suggestions' => $suggestions];
            }
        }

        // Puroks with a name rather than a number ("Purok Malipayon").
        foreach ($this->puroks() as $purok) {
            if (! preg_match('/\d/', $purok->name) && mb_stripos($text, $purok->name) !== false) {
                $found->push($purok);
                $spans[] = $purok->name;
            }
        }

        return ['found' => $found->unique('id')->values(), 'unknown' => $unknown, 'spans' => $spans];
    }

    /** @return Collection<int, Purok> */
    public function puroks(): Collection
    {
        return $this->puroks ??= Purok::orderBy('name')->get();
    }

    public function byName(string $name): ?Purok
    {
        return $this->puroks()->first(fn (Purok $p) => strcasecmp($p->name, $name) === 0);
    }

    /** "Purok 1-A" / purok_number "1-A" → "1a"; "Purok 2" → "2". */
    private function key(Purok $purok): string
    {
        $source = $purok->purok_number !== null && $purok->purok_number !== '' ? (string) $purok->purok_number : $purok->name;
        $source = preg_replace('/^\s*(?:purok|prk)\.?\s*/i', '', $source);

        return strtolower(ltrim(preg_replace('/[^0-9a-z]/i', '', $source), '0')) ?: '0';
    }
}
