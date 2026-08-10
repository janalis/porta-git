<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * One body line of a unified-diff hunk: its origin marker, the text without
 * the marker, and the old/new line numbers it occupies (null on the side a
 * +/- line does not exist on; both null for the `\ No newline` marker).
 */
final readonly class DiffLine
{
    /** @param array{int, int}|null $changedRange emphasized byte range from the intraline pairing */
    public function __construct(
        public string $origin, // ' ' | '+' | '-' | '\\'
        public string $text,
        public ?int $oldNo,
        public ?int $newNo,
        public ?array $changedRange = null,
    ) {}
}
