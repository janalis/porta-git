<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\View\GitViewMode;

/**
 * Show the Changes or History side of the git view.
 *
 * One event for what was three spellings of the same intent: the changes pane's
 * "show history" key, the history pane's "show changes" key, and clicking the
 * tab strip.
 */
final readonly class GitViewModeRequested
{
    public function __construct(
        public GitViewMode $mode,
    ) {}
}
