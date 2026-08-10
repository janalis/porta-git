<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * Builds the partial patches behind line/hunk staging: given a file's diff
 * hunks and a per-hunk set of selected ± lines, emit a valid unified diff
 * containing only those changes.
 *
 * The recount invariant: the OLD side is preserved exactly — every context
 * and deletion line emits exactly one old-side line (an unselected deletion
 * demotes to context, an unselected addition drops), so `-oldStart,oldCount`
 * never changes and only the new side is recounted. Staging applies the
 * result with `git apply --cached`; unstaging builds from the INVERTED cached
 * diff ({@see invertHunk()}) and applies it the same way — one recount path,
 * no `-R` special case.
 */
final class PatchBuilder
{
    /**
     * @param list<DiffHunk>               $hunks
     * @param array<int, array<int, true>> $selected hunk index => set of selected line indices
     *
     * @return string|null the patch, or null when no ± line is selected
     */
    public static function build(string $relPath, array $hunks, array $selected): ?string
    {
        $out = [];
        $delta = 0; // accumulated new-side drift across emitted hunks, in file order

        foreach ($hunks as $h => $hunk) {
            $sel = $selected[$h] ?? [];
            if (!self::hasSelectedChange($hunk, $sel)) {
                continue;
            }

            ['body' => $body, 'newCount' => $newCount] = self::renderHunkBody($hunk, $sel);

            $newStart = (0 === $hunk->oldCount ? $hunk->oldStart + 1 : $hunk->oldStart) + $delta - (0 === $newCount ? 1 : 0);
            $heading = '' === $hunk->heading ? '' : ' '.$hunk->heading;
            $out[] = \sprintf('@@ -%d,%d +%d,%d @@%s', $hunk->oldStart, $hunk->oldCount, $newStart, $newCount, $heading);
            $out = [...$out, ...$body];
            $delta += $newCount - $hunk->oldCount;
        }

        if ([] === $out) {
            return null;
        }

        $envelope = \sprintf("diff --git a/%s b/%s\n--- a/%s\n+++ b/%s\n", $relPath, $relPath, $relPath, $relPath);

        return $envelope.implode("\n", $out)."\n";
    }

    /**
     * Emit one hunk's body lines under $sel, preserving the old side exactly: an
     * unselected deletion demotes to context (' '), an unselected addition drops,
     * and a no-newline marker follows its line's fate. Returns the body lines and
     * the recounted new-side length.
     *
     * @param array<int, true> $sel selected line indices within the hunk
     *
     * @return array{body: list<string>, newCount: int}
     */
    private static function renderHunkBody(DiffHunk $hunk, array $sel): array
    {
        $body = [];
        $newCount = 0;
        $prevKeptOriginal = false; // whether the previous source line was emitted with its own origin
        foreach ($hunk->lines as $i => $line) {
            $isSelected = ($sel[$i] ?? null) !== null;
            switch ($line->origin) {
                case ' ':
                    $body[] = ' '.$line->text;
                    ++$newCount;
                    $prevKeptOriginal = true;
                    break;
                case '-':
                    if ($isSelected) {
                        $body[] = '-'.$line->text;
                        $prevKeptOriginal = true;

                        break;
                    }
                    // Unselected deletion: the line still exists in the preimage.
                    $body[] = ' '.$line->text;
                    ++$newCount;
                    $prevKeptOriginal = false;
                    break;
                case '+':
                    if ($isSelected) {
                        $body[] = '+'.$line->text;
                        ++$newCount;
                        $prevKeptOriginal = true;

                        break;
                    }
                    // Unselected addition: absent from the preimage, dropped.
                    $prevKeptOriginal = false;
                    break;
                default: // '\' No-newline marker follows its line's fate.
                    if ($prevKeptOriginal) {
                        $body[] = '\\'.$line->text;
                    }
            }
        }

        return ['body' => $body, 'newCount' => $newCount];
    }

    /**
     * The mirror image of a hunk: ± swapped, sides swapped. Building a forward
     * patch from an inverted cached (HEAD→index) diff yields an index→"index
     * minus the selection" patch — exactly what unstaging applies.
     */
    public static function invertHunk(DiffHunk $hunk): DiffHunk
    {
        $lines = array_map(static fn (DiffLine $line): DiffLine => new DiffLine(
            match ($line->origin) {
                '+' => '-',
                '-' => '+',
                default => $line->origin,
            },
            $line->text,
            $line->newNo,
            $line->oldNo,
        ), $hunk->lines);

        return new DiffHunk($hunk->newStart, $hunk->newCount, $hunk->oldStart, $hunk->oldCount, $hunk->heading, $lines);
    }

    /** @param array<int, true> $sel */
    private static function hasSelectedChange(DiffHunk $hunk, array $sel): bool
    {
        foreach ($hunk->lines as $i => $line) {
            if (($sel[$i] ?? null) !== null && ('+' === $line->origin || '-' === $line->origin)) {
                return true;
            }
        }

        return false;
    }
}
