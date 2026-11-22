<?php

namespace App\Services\Transfers;

/**
 * One data row after cell-level parsing (types, whitespace, formula-safety unescape) but before
 * semantic validation against the database.
 */
final class ParsedRow
{
    /** @var list<array{field: string|null, code: string, params?: array<string, scalar|null>}> */
    public array $errors = [];

    /** @var list<string> warning codes */
    public array $warnings = [];

    public ?int $providerId = null;

    public ?string $locale = null;

    /** Raw (trimmed) provider_type key as written in the file. */
    public ?string $typeKey = null;

    public ?bool $isActive = null;

    /**
     * Normalized values for the columns present in the file: field => string|null (null = blank cell).
     * Contains scalar columns and the translation fields of this row's locale.
     *
     * @var array<string, string|null>
     */
    public array $values = [];

    public function __construct(public readonly int $rowNumber) {}

    /** @param array<string, scalar|null> $params */
    public function error(?string $field, string $code, array $params = []): void
    {
        $this->errors[] = ['field' => $field, 'code' => $code] + ($params !== [] ? ['params' => $params] : []);
    }

    public function hasColumn(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function value(string $field): ?string
    {
        return $this->values[$field] ?? null;
    }

    /** Whether any translation field of this row carries a value. */
    public function hasTranslationContent(): bool
    {
        foreach (TemplateSchema::translationFields() as $field) {
            if (($this->values[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
