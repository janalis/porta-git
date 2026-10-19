<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\GitPlugin\BranchMenu;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\ViewContextMenu;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ViewHost;

/**
 * The git view's context menus, for the mouse and for Ctrl+F10.
 *
 * These were spread across three core classes: `GitMouseRouter` exposed
 * `changesRowAt()`/`historyCommitAt()` so `ContextMenuRouter` could pull a
 * selected row, `ContextMenuBuilder` turned that row into items, and
 * `ContextMenuOpener` compared focus against two git widgets by identity and
 * built the same items again with its own anchor arithmetic. Selecting a row,
 * knowing what its menu offers, and knowing where to anchor it are one thing,
 * and it is git's.
 */
final readonly class GitContextMenus implements ViewMenuSource
{
    public function __construct(
        private HitTester $hit,
        private ViewHost $host,
        private ChangesListWidget $changes,
        private HistoryListWidget $history,
        private GitCommands $commands,
        private Translator $translator,
        private ?GitToolbarWidget $toolbar = null,
        private ?BranchMenu $branchMenu = null,
        private ?DiffViewWidget $diff = null,
    ) {}

    /**
     * Drop the diff's display settings under its ⚙.
     *
     * @param list<MenuItem> $items
     */
    public function openDiffSettings(array $items): void
    {
        $anchor = $this->diff?->settingsAnchor();
        $rect = null === $this->diff ? null : $this->hit->rect($this->diff);
        if (null !== $anchor && null !== $rect) {
            $this->host->openMenu($items, $rect->row + $anchor[1], $rect->col + $anchor[0]);
        }
    }

    /**
     * Drop a toolbar cell's menu under it: the repository's worktrees, or the
     * branch verbs. The remote cell has none — it runs its operation.
     */
    public function openToolbarMenu(ToolbarCell $cell): void
    {
        $toolbar = $this->toolbar;
        $items = match ($cell) {
            ToolbarCell::Repository => $this->branchMenu?->worktrees(),
            ToolbarCell::Branch => $this->branchMenu?->branches(),
            ToolbarCell::Remote => null,
        };
        if (null === $toolbar || null === $items) {
            return; // no menu there
        }
        $anchor = $this->anchorUnder($toolbar, $cell);
        if (null !== $anchor) {
            $this->host->openMenu($items, $anchor[0], $anchor[1]);
        }
    }

    /**
     * The screen cell under a toolbar cell's first column, or null when the
     * toolbar or that cell was not drawn: nothing to hang from.
     *
     * @return array{int, int}|null
     */
    private function anchorUnder(GitToolbarWidget $toolbar, ToolbarCell $cell): ?array
    {
        $rect = $this->hit->rect($toolbar);
        $span = $toolbar->span($cell);
        if (null === $rect) {
            return null;
        }

        return null === $span ? null : [$rect->row + $toolbar->drawnRows(), $rect->col + $span[0]];
    }

    public function contextItemsAt(int $row, int $col): ?array
    {
        $rel = $this->hit->hit($this->changes, $row, $col);
        if (null !== $rel) {
            return $this->changeItemsAtRow($rel['row']);
        }

        $rel = $this->hit->hit($this->history, $row, $col);

        return null === $rel ? null : $this->commitItemsAtRow($rel['row']);
    }

    public function focusedContextMenu(): ?ViewContextMenu
    {
        $focus = $this->host->focused();

        return match (true) {
            $focus === $this->changes => $this->changesContextMenu(),
            $focus === $this->history => $this->historyContextMenu(),
            default => null,
        };
    }

    /** @return list<MenuItem>|null */
    private function changeItemsAtRow(int $row): ?array
    {
        $entry = $this->changes->selectRowAt($row);
        if (null === $entry) {
            return null;
        }
        $this->host->focus($this->changes);

        return $this->changeItems($entry);
    }

    /** @return list<MenuItem>|null */
    private function commitItemsAtRow(int $row): ?array
    {
        $commit = $this->history->selectCommitAt($row);
        if (null === $commit) {
            return null;
        }
        $this->host->focus($this->history);

        return $this->commitItems($commit);
    }

    private function changesContextMenu(): ?ViewContextMenu
    {
        $entry = $this->changes->selectedEntry();
        $rect = $this->hit->rect($this->changes);

        return null === $entry || null === $rect
            ? null
            : new ViewContextMenu($this->changeItems($entry), $rect->row + $this->changes->selectedRow() + 1, $rect->col + 2);
    }

    private function historyContextMenu(): ?ViewContextMenu
    {
        $commit = $this->history->selectedCommit();
        $rect = $this->hit->rect($this->history);

        return null === $commit || null === $rect
            ? null
            : new ViewContextMenu($this->commitItems($commit), $rect->row + $this->history->selectedCommitRow() + 1, $rect->col + 2);
    }

    /** @return list<MenuItem> */
    private function changeItems(ChangeEntry $entry): array
    {
        $stageLabel = StagedState::Full === $entry->stagedState() ? 'Unstage' : 'Stage';

        return [
            new MenuItem($this->translator->trans($stageLabel), '', fn () => $this->changes->toggleStageSelected()),
            new MenuItem($this->translator->trans('Discard…'), '', fn () => $this->changes->armDiscardSelected()),
            new MenuItem($this->translator->trans('File History'), '', fn () => $this->changes->showHistory()),
        ];
    }

    /**
     * Thin triggers over {@see GitCommands}, so the copy/branch/cherry-pick/
     * revert/reset flows stay single-sourced.
     *
     * @return list<MenuItem>
     */
    private function commitItems(LogEntry $commit): array
    {
        return [
            new MenuItem($this->translator->trans('Copy Hash'), '', fn () => $this->commands->copyCommitHash($commit)),
            new MenuItem($this->translator->trans('Create Branch from Commit…'), '', fn () => $this->commands->branchFromCommit($commit)),
            new MenuItem($this->translator->trans('Cherry-pick'), '', fn () => $this->commands->cherryPick($commit)),
            new MenuItem($this->translator->trans('Revert Commit'), '', fn () => $this->commands->revertCommit($commit)),
            new MenuItem($this->translator->trans('Reset to Commit…'), '', fn () => $this->commands->resetToCommit($commit)),
        ];
    }
}
