<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\Layout\LayoutController;
use Porta\Layout\ResizableSidebar;

/**
 * The git view's left column as one resizable unit: the four stacked widgets
 * each render their own width, so a drag on the git separator fans the new
 * width out to all of them (see {@see LayoutController}) — and to the
 * toolbar over them, whose first cell is as wide.
 */
final readonly class GitSidebar implements ResizableSidebar
{
    public function __construct(
        private GitTabsWidget $tabs,
        private ChangesListWidget $changes,
        private CommitBoxWidget $commitBox,
        private HistoryListWidget $history,
        private ?GitToolbarWidget $toolbar = null,
    ) {}

    public function setWidth(int $width): void
    {
        $this->tabs->setWidth($width);
        $this->changes->setWidth($width);
        $this->commitBox->setWidth($width);
        $this->history->setWidth($width);
        $this->toolbar?->invalidate();
    }

    public function width(): int
    {
        return $this->changes->width();
    }

    // The four stack to one width, so the tab strip's bounds are the column's.
    public function minWidth(): int
    {
        return $this->tabs->minWidth();
    }

    public function maxWidth(): int
    {
        return $this->tabs->maxWidth();
    }

    public function maxRatio(): float
    {
        return $this->tabs->maxRatio();
    }
}
