<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * One changed file from `git status --porcelain=v1`: the repo-relative path
 * and the two status letters (index side, worktree side). The changes list
 * derives its checkbox from {@see stagedState()}: GitHub-Desktop-style,
 * checked means the file's changes are fully staged for the next commit.
 */
final readonly class ChangeEntry
{
    public function __construct(
        public string $path,
        public string $indexStatus,
        public string $worktreeStatus,
        public ?string $renamedFrom = null,
    ) {}

    public function isUntracked(): bool
    {
        return '?' === $this->indexStatus && '?' === $this->worktreeStatus;
    }

    /** The checkbox state: fully staged, partially staged, or not staged. */
    public function stagedState(): StagedState
    {
        if ($this->isUntracked() || ' ' === $this->indexStatus) {
            return StagedState::None;
        }

        return ' ' === $this->worktreeStatus ? StagedState::Full : StagedState::Partial;
    }

    /** The single status letter shown in the changes list (worktree side wins). */
    public function displayStatus(): string
    {
        if ($this->isUntracked()) {
            return 'A';
        }

        return ' ' !== $this->worktreeStatus ? $this->worktreeStatus : $this->indexStatus;
    }
}
