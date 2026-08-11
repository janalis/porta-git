<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Diff;

/**
 * One `@@ -oldStart,oldCount +newStart,newCount @@ heading` hunk with its
 * body lines. Counts are the header's (defaulting to 1 when omitted); the
 * body is preserved verbatim so staging can rebuild patches from it.
 */
final readonly class DiffHunk
{
    /** @param list<DiffLine> $lines */
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
        public string $heading,
        public array $lines,
    ) {}

    /** The header line as rendered in the diff view. */
    public function header(): string
    {
        $heading = '' === $this->heading ? '' : ' '.$this->heading;

        return \sprintf('@@ -%d,%d +%d,%d @@%s', $this->oldStart, $this->oldCount, $this->newStart, $this->newCount, $heading);
    }
}
