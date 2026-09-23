<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;
use Porta\Layout\ViewRegion;
use Porta\Widget\SeparatorWidget;
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

    private readonly SeparatorWidget $separator;

    public function __construct(
        private readonly HitTester $hit,
        private readonly ViewHost $host,
        private readonly ChangesListWidget $changes,
        private readonly DiffViewWidget $diff,
        private readonly CommitBoxWidget $commitBox,
        private readonly HistoryListWidget $history,
        private readonly GitTabsWidget $tabs,
        ViewRegion $region,
    ) {
        // Held for the divider hover tint only — the RECTS are core's now: it
        // mints this region, so it hit-tests its own widgets for resize arming.
        $this->separator = $region->separator();
    }

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
        if (WheelAxis::Horizontal === $gesture->axis) {
            return false; // only the diff pane scrolls sideways
        }
        // The lists scroll their selection, so the changes list reloads its
        // diff on the way — the wheel is their arrow keys (ArrowScrollTrait).
        foreach ([$this->changes, $this->history] as $list) {
            if (null === $this->hit->hit($list, $mouse->row, $mouse->col)) {
                continue;
            }

            $list->scrollBy($gesture->step);

            return true;
        }

        return false;
    }

    /**
     * Extend the diff row-range selection while the left button drags over the
     * diff pane; the release keeps the range armed for a follow-up copy.
     */
    public function continueDrag(MouseEvent $mouse): bool
    {
        if (!$this->diffSelecting || !$mouse->isLeftDrag() && !$mouse->isLeftRelease()) {
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
        if (null !== ($rel = $this->hit->hit($this->tabs, $mouse->row, $mouse->col))) {
            $mode = $this->tabs->modeAtColumn($rel['col']);
            $this->tabs->handleClick($rel['col']);
            // A strip click focuses the pane it reveals — this was the only
            // git pane click that left the focus wherever it happened to be.
            if (null !== $mode) {
                $this->host->focus(GitViewMode::History === $mode ? $this->history : $this->changes);
            }
            $this->host->invalidate();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->changes, $mouse->row, $mouse->col))) {
            $this->host->focus($this->changes);
            $this->changes->handleClick($rel['row'], $rel['col']);
            $this->host->invalidate();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->history, $mouse->row, $mouse->col))) {
            $this->host->focus($this->history);
            $this->history->handleClick($rel['row']);
            $this->host->invalidate();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->commitBox, $mouse->row, $mouse->col))) {
            $this->host->focus($this->commitBox);
            $this->commitBox->handleClick($rel['row']);
            $this->host->invalidate();

            return true;
        }
        if (null !== ($rel = $this->hit->hit($this->diff, $mouse->row, $mouse->col))) {
            $this->host->focus($this->diff);
            $this->diff->handleClick($rel['row'], $rel['col']);
            // A body-row press arms a drag selection (rows join the range as
            // the pointer moves; ctrl+c then copies the highlighted lines).
            $this->diffSelecting = $rel['row'] > 0;
            $this->host->invalidate();

            return true;
        }

        return false;
    }

    /**
     * Hover tints the row under the pointer in whichever list is attached; a
     * miss clears the tint (pointer over another pane). Called after the menu
     * bar has had its turn.
     */
    public function hover(MouseEvent $mouse): void
    {
        $changed = $this->hit->hover($mouse->row, $mouse->col, $this->changes, $this->history, $this->commitBox);
        // A staging box is its own target apart from its row, so it answers the pointer too.
        $changesRel = $this->hit->hit($this->changes, $mouse->row, $mouse->col);
        $boxChanged = $this->changes->hoverBoxAt($changesRel['row'] ?? null, $changesRel['col'] ?? 0);

        $tabsRect = $this->hit->rect($this->tabs);
        $onTabs = null !== $tabsRect && $tabsRect->contains($mouse->row, $mouse->col);
        $tabsChanged = $this->tabs->hoverMode($onTabs ? $this->tabs->modeAtColumn($mouse->col - $tabsRect->col) : null);

        // The diff pane's toolbar toggles are clickable, so they tint too.
        $diffRel = $this->hit->hit($this->diff, $mouse->row, $mouse->col);
        $diffChanged = null !== $diffRel ? $this->diff->hoverHeader($diffRel['row'], $diffRel['col']) : $this->diff->clearHeaderHover();

        $sepChanged = $this->hit->hoverDivider($mouse->row, $mouse->col, $this->separator);

        if ($changed || $boxChanged || $tabsChanged || $diffChanged || $sepChanged) {
            $this->host->invalidate();
        }
    }
}
