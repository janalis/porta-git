<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\Support\PathExecutables;

/**
 * Resolves the git binary {@see GitProcessRunner} and {@see GitRemote} both
 * need to spawn: an explicit path (the "Git executable path" setting) wins
 * over PATH discovery.
 */
final class GitBinary
{
    public static function resolve(?string $gitPath): ?string
    {
        if (null !== $gitPath && '' !== $gitPath) {
            return $gitPath;
        }

        return PathExecutables::here()->find('git');
    }
}
