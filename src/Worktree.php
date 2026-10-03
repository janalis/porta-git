<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * One working tree of the repository, as `git worktree list` reports it.
 * The main one is the clone itself; the others are linked to it, each with
 * its own checkout of its own branch.
 */
final readonly class Worktree
{
    /**
     * @param string|null $branch     the short branch name, null when HEAD is detached
     * @param string|null $lockReason null when unlocked; '' when locked without a reason
     */
    public function __construct(
        public string $path,
        public string $head,
        public ?string $branch,
        public bool $main,
        public ?string $lockReason = null,
        public bool $prunable = false,
    ) {}

    /** What it is called: its folder's name. */
    public function name(): string
    {
        return \basename($this->path);
    }

    /** What it has checked out: the branch, or the commit when HEAD is detached. */
    public function description(): string
    {
        return $this->branch ?? \substr($this->head, offset: 0, length: 7);
    }

    public function locked(): bool
    {
        return null !== $this->lockReason;
    }

    /**
     * The folder name of the linked worktree at $root, or null for the main
     * one or no repository at all.
     *
     * A linked worktree's `.git` is a file naming `<repository>/.git/
     * worktrees/<id>`. A submodule's is a file too — `gitdir: ../.git/modules/
     * x` — which is why the file alone does not say "worktree".
     */
    public static function linkedName(string $root): ?string
    {
        $file = $root.'/.git';
        $text = \is_file($file) ? \file_get_contents($file) : false;
        if (false === $text || !\str_starts_with($text, 'gitdir: ')) {
            return null;
        }
        $gitdir = \rtrim(\substr($text, \strlen('gitdir: ')));

        return 'worktrees' === \basename(\dirname($gitdir)) ? \basename($root) : null;
    }
}
