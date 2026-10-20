<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;

/**
 * The widgets the git view drives: the toolbar across the top, the
 * changes/history lists that share the left column (via the tabs), the commit
 * box beneath them, the selected commit's header and files beside the history, and the
 * diff pane. Bundled so {@see GitViewController}
 * takes one pane group instead of a widget argument each.
 */
final readonly class GitPanes
{
    public function __construct(
        public ChangesListWidget $changes,
        public DiffViewWidget $diffView,
        public CommitBoxWidget $commitBox,
        public HistoryListWidget $history,
        public GitTabsWidget $tabs,
        public GitToolbarWidget $toolbar,
        public CommitDetailsWidget $details,
        public CommitHeaderWidget $header,
    ) {}
}
