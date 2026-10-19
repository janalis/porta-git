<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * How far a {@see RepositoryState} refresh reaches: what git knows locally, or
 * that and what only the server knows (the pull request), after a fetch.
 */
enum RefreshScope
{
    case Local;
    case Remote;
}
