<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

use Porta\GitPlugin\Worktree;

/**
 * Parses `git worktree list --porcelain -z`: one block per worktree, the
 * main one first, each ended by an empty field. A block's fields are
 * `worktree <path>`, `HEAD <sha>`, then `branch <ref>`, `detached` or `bare`,
 * then `locked [<reason>]` and `prunable <reason>` when they apply.
 *
 * `-z` because without it git writes a path with a newline in it raw, which
 * splits it into a field of its own — and NUL is the one byte a path cannot
 * hold. A bare repository's own entry is no working tree, and is left out.
 */
final class WorktreeParser
{
    /**
     * @param string $separator NUL for `-z`; a newline for an older git's plain form
     *
     * @return list<Worktree>
     */
    public static function parse(string $output, string $separator = "\0"): array
    {
        $worktrees = [];
        // The FIRST block is the main worktree, even when it is a bare
        // repository's and so is not listed.
        foreach (\explode($separator.$separator, $output) as $index => $block) {
            $fields = [];
            foreach (\explode($separator, $block) as $field) {
                [$key, $value] = \array_pad(\explode(' ', $field, limit: 2), length: 2, value: '');
                $fields[$key] = $value;
            }
            if (!\array_key_exists('worktree', $fields) || \array_key_exists('bare', $fields)) {
                continue;
            }
            $branch = $fields['branch'] ?? null;
            $worktrees[] = new Worktree(
                $fields['worktree'],
                $fields['HEAD'] ?? '',
                null === $branch || !\str_starts_with($branch, 'refs/heads/') ? $branch : \substr($branch, \strlen('refs/heads/')),
                0 === $index,
                $fields['locked'] ?? null,
                \array_key_exists('prunable', $fields),
            );
        }

        return $worktrees;
    }
}
