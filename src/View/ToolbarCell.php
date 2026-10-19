<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

/**
 * The git view toolbar's three cells, GitHub Desktop's: the repository, the
 * branch, and the remote — fetch, or pull, push or publish when there is
 * something to move.
 */
enum ToolbarCell
{
    case Repository;
    case Branch;
    case Remote;
}
