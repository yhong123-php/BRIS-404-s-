<?php

namespace App\Services\Assistant;

/**
 * The output of one approved query function, split by audience:
 *
 *  - figures / tables / notes / missing: shown to the user under
 *    "From BRIS records". Names in tables come from the database, never from
 *    the model (SDD §4.22 step 6).
 *  - facts: aggregated, non-identifying values — the ONLY part of the result
 *    sent to the language model (SPMP §5.5.3 data minimization).
 */
class QueryResult
{
    public array $figures = [];

    public array $tables = [];

    public array $notes = [];

    public array $facts = [];

    public ?string $missing = null;

    /** Labelled figures a recommendation may cite, by id (community analysis only). */
    public array $evidence = [];

    public string $personalDataTypes = 'counts only';

    public int $rowCount = 0;

    public function __construct(
        public readonly string $function,
        public readonly string $category,
        public readonly array $parameters,
    ) {}

    public static function make(string $function, string $category, array $parameters): self
    {
        return new self($function, $category, $parameters);
    }

    public function figure(string $label, int|float|string $value): self
    {
        $this->figures[] = ['label' => $label, 'value' => $value];

        return $this;
    }

    public function table(string $title, array $columns, array $rows, ?int $total = null): self
    {
        $total ??= count($rows);
        $this->tables[] = [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
        ];
        $this->rowCount = max($this->rowCount, $total);

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function fact(string $key, mixed $value): self
    {
        $this->facts[$key] = $value;

        return $this;
    }

    public function evidence(string $label, int|float|string $value): self
    {
        $this->evidence['E'.(count($this->evidence) + 1)] = "{$label}: {$value}";

        return $this;
    }

    public function missing(string $message): self
    {
        $this->missing = $message;

        return $this;
    }

    public function dataTypes(string $types): self
    {
        $this->personalDataTypes = $types;

        return $this;
    }

    /** The minimized payload for the model: aggregated facts, notes, and the missing-data statement. No names. */
    public function forModel(): array
    {
        return array_filter([
            'function' => $this->function,
            'facts' => $this->facts,
            'notes' => $this->notes,
            'missing_data' => $this->missing,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** The "From BRIS records" payload shown in the chat. */
    public function forDisplay(): array
    {
        return [
            'function' => $this->function,
            'figures' => $this->figures,
            'tables' => $this->tables,
            'notes' => $this->notes,
            'missing' => $this->missing,
        ];
    }
}
