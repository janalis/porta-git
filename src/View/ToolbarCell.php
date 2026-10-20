<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

/**
 * The git view toolbar's cells, GitHub Desktop's: the repository, the
 * worktree open when there are several, the branch, and the remote — fetch,
 * or pull, push or publish when there is something to move.
 */
enum ToolbarCell
{
    case Repository;
    case Worktree;
    case Branch;
    case Remote;
}
