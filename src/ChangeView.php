<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\DiffHunk;
use Porta\GitPlugin\Diff\DiffLine;
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
 * buffer edited since would have the wrong lines replaced.
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
            $items = \array_map(
                // What it removed is greyed and struck through, what it added
                // is plain: the list reads as the change without colour.
                static fn (DiffLine $line): array => ['label' => ('-' === $line->origin ? '− ' : '+ ').$line->text, 'hint' => '', 'value' => '', 'disabled' => '-' === $line->origin],
                \array_values(\array_filter($hunk->lines, static fn (DiffLine $line): bool => '-' === $line->origin || '+' === $line->origin)),
            );
            $items[] = ['label' => $this->api->trans('Revert this change'), 'hint' => '', 'value' => 'revert'];
            $this->api->pick($this->api->trans('Change at line %line%', ['%line%' => $caret['line'] + 1]), $items, function (string $choice) use ($hunk): void {
                if ('revert' === $choice) {
                    $this->revert($hunk);
                }
            });
        });
    }

    /** Whether the change covers a 0-based buffer line — a deletion covers the line the gutter marks for it. */
    private static function covers(DiffHunk $hunk, int $line): bool
    {
        return 0 === $hunk->newCount
            ? \max(0, $hunk->newStart - 1) === $line
            : $line >= ($hunk->newStart - 1) && $line < ($hunk->newStart - 1 + $hunk->newCount);
    }

    /** Put back what the change removed in place of what it added — one undo step, in the buffer. */
    private function revert(DiffHunk $hunk): void
    {
        $lines = \explode("\n", (string) $this->api->documentText());
        $removed = \array_values(\array_map(static fn (DiffLine $line): string => $line->text, \array_filter($hunk->lines, static fn (DiffLine $line): bool => '-' === $line->origin)));
        $added = \array_values(\array_map(static fn (DiffLine $line): string => $line->text, \array_filter($hunk->lines, static fn (DiffLine $line): bool => '+' === $line->origin)));
        $at = 0 === $hunk->newCount ? $hunk->newStart : $hunk->newStart - 1;
        if (\array_slice($lines, $at, \count($added)) !== $added) {
            $this->api->notify($this->api->trans('The buffer has changed since; save it and try again'), ToastSeverity::Warn);

            return;
        }
        \array_splice($lines, $at, \count($added), $removed);
        $this->api->replaceDocument(\implode("\n", $lines));
        $this->api->notify($this->api->trans('Change reverted'), ToastSeverity::Success);
    }
}
