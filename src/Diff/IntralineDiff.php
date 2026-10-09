<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Diff;

/**
 * Word-level highlighting for paired diff lines: the i-th deletion of a ±
 * run pairs with the i-th addition, and the changed middle (after stripping
 * the common byte prefix/suffix, UTF-8-safe) is emphasized. Prefix/suffix
 * covers the dominant single-edit-per-line case; a full LCS word diff is a
 * deliberate non-goal.
 */
final class IntralineDiff
{
    /**
     * The changed byte ranges of $old and $new, or null when the lines are
     * identical or share nothing (whole-line change carries no highlight).
     *
     * @return array{array{int, int}, array{int, int}}|null [oldStart, oldEnd], [newStart, newEnd]
     */
    public static function changedRanges(string $old, string $new): ?array
    {
        return $old === $new ? null : self::differingRanges($old, $new);
    }

    /**
     * changedRanges() for two lines known to differ.
     *
     * @return array{array{int, int}, array{int, int}}|null
     */
    private static function differingRanges(string $old, string $new): ?array
    {
        $prefix = 0;
        $max = \min(\strlen($old), \strlen($new));
        while ($prefix < $max && $old[$prefix] === $new[$prefix]) {
            ++$prefix;
        }
        // Back off to a UTF-8 boundary (never split inside a multibyte char).
        while ($prefix > 0 && (self::isContinuation($old, $prefix) || self::isContinuation($new, $prefix))) {
            --$prefix;
        }

        $suffix = 0;
        $maxSuffix = $max - $prefix;
        while ($suffix < $maxSuffix && $old[\strlen($old) - 1 - $suffix] === $new[\strlen($new) - 1 - $suffix]) {
            ++$suffix;
        }
        while ($suffix > 0 && (self::isContinuation($old, \strlen($old) - $suffix) || self::isContinuation($new, \strlen($new) - $suffix))) {
            --$suffix;
        }

        if (0 === $prefix && 0 === $suffix) {
            return null; // nothing in common: emphasis would cover everything
        }

        return [
            [$prefix, \strlen($old) - $suffix],
            [$prefix, \strlen($new) - $suffix],
        ];
    }

    /** Whether the byte at $offset is a UTF-8 continuation byte (splitting there breaks a char). */
    private static function isContinuation(string $s, int $offset): bool
    {
        return null !== ($s[$offset] ?? null) && 0x80 === (\ord($s[$offset]) & 0xC0);
    }
}
