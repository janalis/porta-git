<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * Which tab owns the git view's left pane. Pure, like the view's other
 * axes ({@see CommitMode}, {@see RefreshMode}): the value never crosses a
 * persistence boundary, so there is nothing to back it with.
 */
enum GitViewMode
{
    case Changes;
    case History;
}
