<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

/**
 * Parses `git branch --all --format='%(refname)%09%(HEAD)'` output: one full
 * refname per line, a tab, then `*` for the checked-out branch. Local
 * branches come first, then remote branches whose short name no local branch
 * shadows; the symbolic `refs/remotes/<remote>/HEAD` entries are skipped.
 */
final class BranchParser
{
    /** @return list<array{name: string, current: bool, remote: bool}> */
    public static function parse(string $output): array
    {
        $locals = [];
        $localNames = [];
        $remotes = [];
        foreach (\explode("\n", $output) as $line) {
            $line = \trim($line, characters: "\r");
            if ('' === \trim($line)) {
                continue;
            }
            [$ref, $head] = \array_pad(\explode("\t", $line, limit: 2), length: 2, value: '');
            $ref = \trim($ref);
            if (\str_starts_with($ref, 'refs/heads/')) {
                $name = \substr($ref, \strlen('refs/heads/'));
                $locals[] = ['name' => $name, 'current' => '*' === \trim($head), 'remote' => false];
                $localNames[$name] = true;

                continue;
            }
            if (!\str_starts_with($ref, 'refs/remotes/')) {
                continue;
            }
            $short = \substr($ref, \strlen('refs/remotes/'));
            // origin/HEAD is a symbolic pointer, not a branch.
            if (\str_ends_with($short, '/HEAD')) {
                continue;
            }
            $remotes[] = ['name' => $short, 'current' => false, 'remote' => true];
        }

        // A remote branch shadowed by a local of the same short name is noise.
        $remotes = \array_values(\array_filter($remotes, static function (array $branch) use ($localNames): bool {
            $slash = \strpos($branch['name'], needle: '/');
            $short = false === $slash ? $branch['name'] : \substr($branch['name'], $slash + 1);

            return null === ($localNames[$short] ?? null);
        }));

        return [...$locals, ...$remotes];
    }
}
