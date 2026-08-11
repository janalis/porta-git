<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;

/**
 * The five widgets the git view drives: the changes/history lists that share
 * the left column (via the tabs), the commit box beneath them, and the diff
 * pane. Bundled so {@see GitViewController} takes one pane group instead of
 * five separate widget arguments.
 */
final readonly class GitPanes
{
    public function __construct(
        public ChangesListWidget $changes,
        public DiffViewWidget $diffView,
        public CommitBoxWidget $commitBox,
        public HistoryListWidget $history,
        public GitTabsWidget $tabs,
    ) {}
}
