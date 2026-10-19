<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\View\ToolbarCell;

/** A press on a toolbar cell that drops a menu: the repository's worktrees, or the branch verbs. */
final readonly class GitToolbarMenuRequested
{
    public function __construct(
        public ToolbarCell $cell,
    ) {}
}
