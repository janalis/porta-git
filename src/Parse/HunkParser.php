<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

/**
 * Parses `git diff --unified=0` hunk headers into the per-line change marks the
 * editor gutter paints. Joins the parser tier beside {@see BlameParser},
 * {@see BranchParser}, {@see LogParser} and {@see StatusParser}: pure, static,
 * and testable without a git binary.
 */
final class HunkParser
{
    /**
     * Parse `git diff --unified=0` output into per-line change marks.
     *
     * @return array<int, string> 0-based line => 'added'|'modified'|'deleted'
     */
    public static function parse(string $diff): array
    {
        $marks = [];
        foreach (explode("\n", $diff) as $line) {
            $m = [];
            if (!preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                continue;
            }
            $oldCount = '' === ($m[2] ?? '') ? 1 : (int) $m[2];
            $newStart = (int) $m[3];
            $newCount = '' === ($m[4] ?? '') ? 1 : (int) $m[4];

            if (0 === $newCount) {
                // Pure deletion: flag the surviving line the removal sits on.
                $marks[max(0, $newStart - 1)] = 'deleted';
                continue;
            }

            for ($i = 0; $i < $newCount; ++$i) {
                $index = $newStart - 1 + $i;
                // Lines beyond the overlap with the old hunk are new additions;
                // the overlapping ones are modifications.
                $marks[$index] = $i < $oldCount ? 'modified' : 'added';
            }
            if (0 === $oldCount) {
                for ($i = 0; $i < $newCount; ++$i) {
                    $marks[$newStart - 1 + $i] = 'added';
                }
            }
        }

        return $marks;
    }
}
