<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;

/**
 * Mouse routing for the git view's panes — the changes list, commit box,
 * history browser, tabs and diff pane — plus the diff pane's row-range drag
 * selection. Owns the git widgets and the diff-drag flag so {@see MouseRouter}
 * (which keeps the load-bearing guard order and the menu/editor logic) no longer
 * has to. Its panes are live only while the git view is swapped into the main
 * area, so its hit tests miss under the normal editor layout.
 */
final class GitMouseRouter implements ViewPointer
{
    /** A press on a diff body row arms a row-range drag (rows join as the pointer moves). */
    private bool $diffSelecting = false;

    public function __construct(
        private readonly HitTester $hit,
        private readonly ViewHost $host,
        private readonly ChangesListWidget $changes,
        private readonly DiffViewWidget $diff,
        private readonly CommitBoxWidget $commitBox,
        private readonly HistoryListWidget $history,
        private readonly GitTabsWidget $tabs,
        private readonly ?GitToolbarWidget $toolbar = null,
        private readonly ?CommitDetailsWidget $details = null,
        private readonly ?CommitHeaderWidget $header = null,
    ) {} // the region's divider is core's to tint and to drag, as for every view

    /** Clear the diff-drag arming on a fresh press (mirrors the other drag resets). */
    public function cancelDrag(): void
    {
        $this->diffSelecting = false;
    }

    public function wheel(WheelGesture $gesture): bool
    {
        $mouse = $gesture->mouse;
        if (null !== $this->hit->hit($this->diff, $mouse->row, $mouse->col)) {
            WheelAxis::Horizontal === $gesture->axis
                ? $this->diff->scrollHBy($gesture->step)
                : $this->diff->scrollBy($gesture->step);

            return true;
        }
        // Only the diff pane scrolls sideways. The lists scroll their selection,
        // so the changes list reloads its diff on the way — the wheel is their
        // arrow keys (ArrowScrollTrait).
        $list = WheelAxis::Horizontal === $gesture->axis
            ? null
            : \array_find(
                \array_filter([$this->changes, $this->history, $this->details]),
                fn (ChangesListWidget|HistoryListWidget|CommitDetailsWidget $list): bool => null !== $this->hit->hit($list, $mouse->row, $mouse->col),
            );
        $list?->scrollBy($gesture->step);

        return null !== $list;
    }

    /**
     * Extend the diff row-range selection while the left button drags over the
     * diff pane; the release keeps the range armed for a follow-up copy.
     */
    public function continueDrag(MouseEvent $mouse): bool
    {
        if (!$this->diffSelecting || (!$mouse->isLeftDrag() && !$mouse->isLeftRelease())) {
            return false;
        }
        // Only genuine motion extends the range — a plain click's release
        // must not arm an anchor on the row it already selected.
        if ($mouse->isLeftDrag() && null !== ($rel = $this->hit->hit($this->diff, $mouse->row, $mouse->col)) && $rel['row'] > 0) {
            $this->diff->dragSelectTo($rel['row'], $rel['col']);
            $this->host->invalidate();
        }
        if ($mouse->isLeftRelease()) {
            $this->diffSelecting = false;
        }

        return true;
    }

    /** A left press on a git pane (rects exist only while the view is swapped in). */
    public function press(MouseEvent $mouse): bool
    {
        // Which pane, then what a press does there — one method each, so
        // this stays the table of panes it reads as.
        return match (true) {
            null !== $this->toolbar && null !== ($rel = $this->hit->hit($this->toolbar, $mouse->row, $mouse->col)) => $this->pressToolbar($this->toolbar, $rel['col']),
            null !== ($rel = $this->hit->hit($this->tabs, $mouse->row, $mouse->col)) => $this->pressTabs($rel['col']),
            null !== ($rel = $this->hit->hit($this->changes, $mouse->row, $mouse->col)) => $this->pressChanges($rel['row'], $rel['col']),
            null !== ($rel = $this->hit->hit($this->history, $mouse->row, $mouse->col)) => $this->pressHistory($rel['row']),
            null !== ($at = $this->detailsAt($mouse)) => $this->pressDetails(...$at),
            null !== ($at = $this->headerAt($mouse)) => $this->pressHeader(...$at),
            null !== ($rel = $this->hit->hit($this->commitBox, $mouse->row, $mouse->col)) => $this->pressCommitBox($rel['row']),
            null !== ($rel = $this->hit->hit($this->diff, $mouse->row, $mouse->col)) => $this->pressDiff($rel['row'], $rel['col']),
            default => false,
        };
    }

    private function pressToolbar(GitToolbarWidget $toolbar, int $col): bool
    {
        $toolbar->handleClick($col);
        $this->host->invalidate();

        return true;
    }

    private function pressTabs(int $col): bool
    {
        $mode = $this->tabs->modeAtColumn($col);
        $this->tabs->handleClick($col);
        // A strip click focuses the pane it reveals — this was the only
        // git pane click that left the focus wherever it happened to be.
        if (null !== $mode) {
            $this->host->focus(GitViewMode::History === $mode ? $this->history : $this->changes);
        }
        $this->host->invalidate();

        return true;
    }

    private function pressChanges(int $row, int $col): bool
    {
        $this->host->focus($this->changes);
        $this->changes->handleClick($row, $col);
        $this->host->invalidate();

        return true;
    }

    private function pressHistory(int $row): bool
    {
        $this->host->focus($this->history);
        $this->history->handleClick($row);
        $this->host->invalidate();

        return true;
    }

    /**
     * The commit details pane and the cell of it under the pointer, or null
     * off it — and while History is not showing it.
     *
     * @return array{CommitDetailsWidget, int}|null
     */
    private function detailsAt(MouseEvent $mouse): ?array
    {
        $rel = null === $this->details ? null : $this->hit->hit($this->details, $mouse->row, $mouse->col);

        return null === $rel || null === $this->details ? null : [$this->details, $rel['row']];
    }

    private function pressDetails(CommitDetailsWidget $details, int $row): bool
    {
        $this->host->focus($details);
        $details->handleClick($row);
        $this->host->invalidate();

        return true;
    }

    /**
     * The commit header and the cell of it under the pointer, or null off it.
     *
     * @return array{CommitHeaderWidget, int, int}|null
     */
    private function headerAt(MouseEvent $mouse): ?array
    {
        $rel = null === $this->header ? null : $this->hit->hit($this->header, $mouse->row, $mouse->col);

        return null === $rel || null === $this->header ? null : [$this->header, $rel['row'], $rel['col']];
    }

    /** The commit header: its copy button, and nothing that takes the keys. */
    private function pressHeader(CommitHeaderWidget $header, int $row, int $col): bool
    {
        $header->handleClick($row, $col);
        $this->host->invalidate();

        return true;
    }

    private function pressCommitBox(int $row): bool
    {
        $this->host->focus($this->commitBox);
        $this->commitBox->handleClick($row);
        $this->host->invalidate();

        return true;
    }

    private function pressDiff(int $row, int $col): bool
    {
        $this->host->focus($this->diff);
        $this->diff->handleClick($row, $col);
        // A body-row press arms a drag selection (rows join the range as
        // the pointer moves; ctrl+c then copies the highlighted lines).
        $this->diffSelecting = $row > 0;
        $this->host->invalidate();

        return true;
    }

    /**
     * Hover tints the row under the pointer in whichever list is attached; a
     * miss clears the tint (pointer over another pane). Called after the menu
     * bar has had its turn.
     */
    public function hover(MouseEvent $mouse): void
    {
        $changed = $this->hit->hover($mouse->row, $mouse->col, ...\array_filter([$this->changes, $this->history, $this->commitBox, $this->details]));
        // A staging box is its own target apart from its row, so it answers the pointer too.
        $changesRel = $this->hit->hit($this->changes, $mouse->row, $mouse->col);
        $boxChanged = $this->changes->hoverBoxAt($changesRel['row'] ?? null, $changesRel['col'] ?? 0);

        $tabsRect = $this->hit->rect($this->tabs);
        $onTabs = null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col);
        $tabsChanged = $this->tabs->hoverMode($onTabs ? $this->tabs->modeAtColumn($mouse->col - $tabsRect->col) : null);

        // The diff pane's toolbar toggles are clickable, so they tint too.
        $diffRel = $this->hit->hit($this->diff, $mouse->row, $mouse->col);
        $diffChanged = null !== $diffRel ? $this->diff->hoverHeader($diffRel['row'], $diffRel['col']) : $this->diff->clearHeaderHover();

        // The toolbar's cells are buttons: the one under the pointer tints.
        $toolbarRel = null === $this->toolbar ? null : $this->hit->hit($this->toolbar, $mouse->row, $mouse->col);
        $toolbarChanged = $this->toolbar?->hoverAt($toolbarRel['col'] ?? null) ?? false;

        if ($changed || $boxChanged || $tabsChanged || $diffChanged || $toolbarChanged) {
            $this->host->invalidate();
        }
    }
}
