<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

use Porta\GitPlugin\ChangeEntry;

/**
 * Parses `git status --porcelain=v1 -z` output: NUL-separated records of
 * `XY path`, where a rename/copy record (X of R or C) is followed by one
 * extra NUL-separated token carrying the original path.
 */
final class StatusParser
{
    /** @return list<ChangeEntry> */
    public static function parse(string $raw): array
    {
        $entries = [];
        $tokens = explode("\0", $raw);
        for ($i = 0, $count = \count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\strlen($token) < 4 || ' ' !== $token[2]) {
                continue; // trailing empty token or malformed record
            }
            $index = $token[0];
            $worktree = $token[1];
            $path = substr($token, offset: 3);

            $renamedFrom = null;
            if (('R' === $index || 'C' === $index) && ($tokens[$i + 1] ?? null) !== null && '' !== $tokens[$i + 1]) {
                $renamedFrom = $tokens[++$i];
            }

            $entries[] = new ChangeEntry($path, $index, $worktree, $renamedFrom);
        }

        return $entries;
    }
}
