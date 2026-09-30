<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\StagedState;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;
use Porta\Ui\Tooltip;
use Porta\Widget\Render\FieldBox;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * What a changes-list row says under the pointer, cell by cell: its box what
 * a click does, its status letter what it means — `R` and `C` are git's
 * shorthand, and an untracked file shows as `A` — and its path, when the row
 * cut it, in full.
 *
 * The row is ` [box] L path`: the box from column 1, the letter one column
 * after it, the path two after that. {@see ChangesListWidget::entryRow()}
 * draws it; this reads it back.
 */
final class ChangeTooltip
{
    /** The tooltip at column $col of the row $row that draws $entry, $width columns wide. */
    public static function of(ChangeEntry $entry, int $row, int $col, int $width, string $stageKey, Translator $translator): ?Tooltip
    {
        $letter = 1 + FieldBox::CHECKBOX_WIDTH + 1;
        $path = $entry->path.(null !== $entry->renamedFrom ? ' '.Glyphs::RENAME_FROM.' '.$entry->renamedFrom : '');

        return match (true) {
            $col >= 1 && $col < ($letter - 1) => new Tooltip(
                'git:box:'.$entry->path,
                (StagedState::Full === $entry->stagedState() ? $translator->trans('Unstage this file') : $translator->trans('Stage this file'))."\t".$stageKey,
                $row,
                1,
                $letter - 1,
            ),
            $col === $letter => self::status($entry, $translator, $row, $letter),
            $col > $letter && AnsiUtils::visibleWidth($path) > ($width - $letter - 2) => new Tooltip('git:path:'.$entry->path, $path, $row, $letter + 2, $width),
            default => null,
        };
    }

    private static function status(ChangeEntry $entry, Translator $translator, int $row, int $col): ?Tooltip
    {
        $what = match (true) {
            $entry->isUntracked() => $translator->trans('Untracked: new to git'),
            default => match ($entry->displayStatus()) {
                'A' => $translator->trans('Added'),
                'M' => $translator->trans('Modified'),
                'D' => $translator->trans('Deleted'),
                'R' => $translator->trans('Renamed from %path%', ['%path%' => (string) $entry->renamedFrom]),
                'C' => $translator->trans('Copied from %path%', ['%path%' => (string) $entry->renamedFrom]),
                'U' => $translator->trans('Unmerged: a conflict to resolve'),
                'T' => $translator->trans('Type changed'),
                default => null,
            },
        };
        if (null === $what) {
            return null;
        }
        $staged = match ($entry->stagedState()) {
            StagedState::Full => $translator->trans('Staged'),
            StagedState::Partial => $translator->trans('Partly staged'),
            StagedState::None => $translator->trans('Not staged'),
        };

        return new Tooltip('git:status:'.$entry->path, $what."\n".$staged, $row, $col, $col + 1);
    }
}
