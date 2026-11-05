<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\DiffHunk;
use Porta\DiffPlugin\DiffLine;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;

/**
 * One of a file's changes against HEAD read from the editor — what it
 * removed, struck through, and what it added — with a revert: the verb a
 * change mark's gutter click runs. (Walking between the changes is core's,
 * over the gutter's own marks.)
 *
 * The revert works in the BUFFER, as one undo step, and only when the buffer
 * still holds what the change added: the hunks are the file on disk's, and a
 * buffer edited since would have the wrong lines replaced. A pure deletion
 * added nothing to check, so the lines either side of it are checked against
 * the disk instead — or the removed lines would go back wherever the edits
 * had moved that line number to.
 */
final readonly class ChangeView
{
    public function __construct(
        private GitInfo $git,
        private PluginApi $api,
    ) {}

    /** Show the change at the caret: its lines, and a revert. */
    public function show(): void
    {
        $path = $this->api->activeFile();
        $caret = $this->api->caretPosition();
        if (null === $path || null === $caret) {
            return;
        }
        $this->git->headDiff($path, function (string $raw) use ($path, $caret): void {
            $hunk = \array_find(DiffDocument::parse($raw)->hunks, static fn (DiffHunk $hunk): bool => self::covers($hunk, $caret['line']));
            if (null === $hunk || $this->api->activeFile() !== $path) {
                $this->api->flash($this->api->trans('No change here'));

                return;
            }
            // The verb first, where the selection starts — the rows below
            // are the change to read, not choices; a revert is one undo step.
            $items = [['label' => $this->api->trans('Revert this change'), 'hint' => '', 'value' => 'revert']];
            $lines = \array_map(
                // What it removed is greyed and struck through, what it added
                // is plain: the list reads as the change without colour.
                static fn (DiffLine $line): array => ['label' => ('-' === $line->origin ? '− ' : '+ ').$line->text, 'hint' => '', 'value' => '', 'disabled' => '-' === $line->origin],
                \array_values(\array_filter($hunk->lines, static fn (DiffLine $line): bool => '-' === $line->origin || '+' === $line->origin)),
            );
            $items = [...$items, ...$lines];
            $this->api->pick($this->api->trans('Change at line %line%', ['%line%' => $caret['line'] + 1]), $items, function (string $choice) use ($hunk, $path): void {
                if ('revert' === $choice) {
                    $this->revert($hunk, $path);
                }
            });
        });
    }

    /**
     * The hunk's lines of one origin, as the buffer holds them: without the
     * `\r` a CRLF file's lines keep in git's output — the ending is the writer's.
     *
     * @return list<string>
     */
    private static function texts(DiffHunk $hunk, string $origin): array
    {
        $lines = \array_filter($hunk->lines, static fn (DiffLine $line): bool => $origin === $line->origin);

        return \array_values(\array_map(static fn (DiffLine $line): string => \rtrim($line->text, characters: "\r"), $lines));
    }

    /**
     * The line a deletion follows and the one after it.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function around(array $lines, int $at): array
    {
        return \array_slice($lines, \max(0, $at - 1), length: 2);
    }

    /**
     * The file on disk's lines as a buffer holds them: no byte-order mark, no
     * line ending.
     *
     * @return list<string>
     */
    private static function diskLines(string $path): array
    {
        $bytes = \is_file($path) ? (string) \file_get_contents($path) : '';
        $bytes = \str_starts_with($bytes, "\u{FEFF}") ? \substr($bytes, offset: 3) : $bytes;

        return \explode("\n", \str_replace(["\r\n", "\r"], replace: "\n", subject: $bytes));
    }

    /** Whether the change covers a 0-based buffer line — a deletion covers the line the gutter marks for it. */
    private static function covers(DiffHunk $hunk, int $line): bool
    {
        return 0 === $hunk->newCount
            ? \max(0, $hunk->newStart - 1) === $line
            : $line >= ($hunk->newStart - 1) && $line < ($hunk->newStart - 1 + $hunk->newCount);
    }

    /** Put back what the change removed in place of what it added — one undo step, in the buffer. */
    private function revert(DiffHunk $hunk, string $path): void
    {
        $lines = \explode("\n", (string) $this->api->documentText());
        $removed = self::texts($hunk, '-');
        $added = self::texts($hunk, '+');
        $at = 0 === $hunk->newCount ? $hunk->newStart : $hunk->newStart - 1;
        $held = [] === $added
            ? self::around($lines, $at) === self::around(self::diskLines($path), $at)
            : \array_slice($lines, $at, \count($added)) === $added;
        if (!$held) {
            $this->api->notify($this->api->trans('The buffer has changed since; save it and try again'), ToastSeverity::Warn);

            return;
        }
        \array_splice($lines, $at, \count($added), $removed);
        $this->api->replaceDocument(\implode("\n", $lines));
        $this->api->notify($this->api->trans('Change reverted'), ToastSeverity::Success);
    }
}
