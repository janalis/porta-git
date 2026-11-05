<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

/**
 * The working tree a project folder sits inside without being its root —
 * `~/code/app/frontend` opened from the repository `~/code/app`.
 *
 * Git stays off there: everything git prints is relative to the repository's
 * root, and every path the plugin hands back would have to be translated by
 * the folder between. What it can do is say where git would work, rather
 * than "not a git repository" about a folder that is in one.
 */
final class RepositoryAbove
{
    /** The nearest parent holding a `.git` (a folder, or a worktree's file), or null. */
    public static function of(string $root): ?string
    {
        $dir = \dirname($root);
        $found = null;
        while (null === $found && \dirname($dir) !== $dir) {
            $found = \file_exists($dir.'/.git') ? $dir : null;
            $dir = \dirname($dir);
        }

        return $found;
    }
}
