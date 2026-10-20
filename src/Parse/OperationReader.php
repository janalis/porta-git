<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

use Porta\GitPlugin\GitOperation;
use Porta\GitPlugin\OperationState;

/**
 * Which operation git is in the middle of, read from the files it keeps while
 * one runs — the same ones its own prompt reads. Pure file checks on paths
 * git named once ({@see \Porta\GitPlugin\GitInfo::operationPaths()}), so a
 * refresh with nothing in progress costs no process at all.
 *
 * A rebase is asked first: one that stops on a conflict is mid-pick too.
 * `git am` keeps `rebase-apply/` as well, without `rebasing` in it, and is
 * none of these.
 */
final class OperationReader
{
    /** The paths, in the order {@see \Porta\GitPlugin\GitInfo::operationPaths()} asks for them. */
    public const array FILES = ['MERGE_HEAD', 'rebase-merge', 'rebase-apply', 'CHERRY_PICK_HEAD', 'REVERT_HEAD'];

    /** @param array<string, string> $paths each of {@see FILES} => its absolute path */
    public static function read(array $paths): ?OperationState
    {
        $merge = $paths['rebase-merge'] ?? '';
        $apply = $paths['rebase-apply'] ?? '';

        return match (true) {
            \is_dir($merge) => new OperationState(GitOperation::Rebasing, self::number($merge.'/msgnum'), self::number($merge.'/end')),
            \is_file($apply.'/rebasing') => new OperationState(GitOperation::Rebasing, self::number($apply.'/next'), self::number($apply.'/last')),
            \is_file($paths['CHERRY_PICK_HEAD'] ?? '') => new OperationState(GitOperation::CherryPicking),
            \is_file($paths['REVERT_HEAD'] ?? '') => new OperationState(GitOperation::Reverting),
            \is_file($paths['MERGE_HEAD'] ?? '') => new OperationState(GitOperation::Merging),
            default => null,
        };
    }

    /** A counter file's number, or null when it is missing or holds none. */
    private static function number(string $file): ?int
    {
        $text = \is_file($file) ? \trim((string) \file_get_contents($file)) : '';

        return \ctype_digit($text) ? (int) $text : null;
    }
}
