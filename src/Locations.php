<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * Where a repository lives, all absolute: the working tree's top folder, the
 * common git directory (the main worktree's `.git`, which names it), and this
 * worktree's own FETCH_HEAD.
 */
final readonly class Locations
{
    public function __construct(
        public string $top,
        public string $common,
        public string $fetchHead,
    ) {}
}
