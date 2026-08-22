<?php // api/FieldTypes.php
// The field-type vocabulary — SIGNATURE SHELL ONLY (field-types T01, split RED).
//
// This file carries the shape the plan's `## Interfaces` block names, and no
// table. `tests/Unit/FieldTypesTest.php` is the behavioural contract: 17 names,
// one sanitizer / schema / control / cell each, and a closed set that throws for
// everything else. The implementer fills the table until that suite is green,
// without weakening it.
defined('ABSPATH') || exit;

/**
 * One field type: what sanitizes it, what it publishes as, what draws it.
 */
final class NTDST_FieldType
{
    public function __construct(
        public readonly string $name,
        /** @var \Closure fn(mixed $value, array $config): mixed — idempotent */
        public readonly \Closure $sanitize,
        /** @var array<string, mixed>|null REST JSON schema for the leaf; null = no leaf shape */
        public readonly ?array $schema,
        /** admin input key: number|checkbox|text|textarea|html|email|url|date|select|media|relation|gallery|repeater */
        public readonly string $control,
        /** may render inside a repeater row */
        public readonly bool $cell,
    ) {
    }
}

/**
 * The one table. Closed set: no filter, no registration method.
 */
final class NTDST_FieldTypes
{
    public static function get(string $name): NTDST_FieldType
    {
        throw new InvalidArgumentException('not built');
    }

    /** @return list<string> */
    public static function names(): array
    {
        return [];
    }
}
