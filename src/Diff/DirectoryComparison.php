<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Diff;

use Porta\GitPlugin\ChangeEntry;

/**
 * The files that differ between two directory trees — what `git difftool
 * --dir-diff` hands its tool: two temp directories holding only the changed
 * files, the working-tree side as SYMLINKS into the repository.
 *
 * Walked here rather than asked of `git diff --no-index`, because git compares
 * a symlink by its target text, so every working-tree file would read as a type
 * change. Symlinks are followed and contents compared; a path on one side only
 * is an addition or a deletion.
 */
final class DirectoryComparison
{
    /** @return list<ChangeEntry> relative paths, sorted, status A/D/M in the worktree column */
    public static function changes(string $left, string $right): array
    {
        $before = self::files($left);
        $after = self::files($right);
        $entries = [];
        foreach (\array_unique([...\array_keys($before), ...\array_keys($after)]) as $path) {
            $status = match (true) {
                !\array_key_exists($path, $after) => 'D',
                !\array_key_exists($path, $before) => 'A',
                \hash_file('xxh128', $before[$path]) !== \hash_file('xxh128', $after[$path]) => 'M',
                default => null,
            };
            if (null !== $status) {
                // @mago-expect analysis:redundant-cast -- PHP turns a numeric key into an int, so a file named `1` arrives as one.
                $entries[] = new ChangeEntry((string) $path, ' ', $status);
            }
        }
        \usort($entries, static fn (ChangeEntry $a, ChangeEntry $b): int => \strcmp($a->path, $b->path));

        return $entries;
    }

    /** The real file each side of $path stands for — a symlink resolved — or /dev/null where it does not exist. */
    public static function side(string $root, string $path): string
    {
        $file = \rtrim($root, characters: '/').'/'.$path;
        $real = \realpath($file);

        return false !== $real && \is_file($real) ? $real : '/dev/null';
    }

    /** @return array<string, string> relative path => absolute path, every regular file (followed through symlinks) */
    private static function files(string $root): array
    {
        $root = \rtrim($root, characters: '/');
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS));
        foreach ($walk as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $files[\substr($path, \strlen($root) + 1)] = $path;
        }

        return $files;
    }
}
