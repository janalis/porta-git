<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Parse\OperationReader;

/**
 * Owns the branch indicator shown in the status bar, refreshed asynchronously
 * through {@see GitInfo} on the shared event loop — and, beside it, the merge,
 * rebase, cherry-pick or revert git is in the middle of.
 *
 * Change marks used to live here too, and so did the branch cell's place in the
 * status bar. Both are this plugin's now: the marks go out through
 * `PluginApi::setChangeMarks()`, and the cell is an ordinary contributed
 * segment. What is left here is when to ask git again — on a save, a commit, a
 * completed remote operation, or anything that moved the working tree.
 */
final class GitRefresher
{
    private ?string $branch = null;

    private ?OperationState $operation = null;

    /**
     * Where git keeps an operation's files, asked the first time and kept:
     * null until then, an empty list when git would not say.
     *
     * @var array<string, string>|null
     */
    private ?array $operationPaths = null;

    /** @param \Closure(): void $repaint tells the status bar its segment is stale */
    public function __construct(
        private readonly GitInfo $git,
        private readonly \Closure $repaint,
    ) {}

    /** The branch indicator for the status bar (refreshed asynchronously). */
    public function branch(): ?string
    {
        return $this->branch;
    }

    /** The operation in progress and its conflicts left, or null when git is in the middle of none. */
    public function operation(): ?OperationState
    {
        return $this->operation;
    }

    public function refreshBranch(): void
    {
        if (!$this->git->isAvailable()) {
            return;
        }
        $this->git->branch(function (?string $branch): void {
            $this->branch = $branch;
            ($this->repaint)();
        });
        $this->refreshOperation();
    }

    /**
     * Read the operation off its files — no process while there is none —
     * and, while there is one, ask how many paths are still conflicted.
     */
    private function refreshOperation(): void
    {
        if (null === $this->operationPaths) {
            $this->git->operationPaths(
                /** @param array<string, string>|null $paths */
                function (?array $paths): void {
                    $this->operationPaths = $paths ?? [];
                    $this->readOperation();
                },
            );

            return;
        }
        $this->readOperation();
    }

    private function readOperation(): void
    {
        $operation = OperationReader::read($this->operationPaths ?? []);
        if (null === $operation) {
            if (null !== $this->operation) {
                $this->operation = null;
                ($this->repaint)();
            }

            return;
        }
        $this->git->conflictCount(function (int $count) use ($operation): void {
            $this->operation = $operation->withConflicts($count);
            ($this->repaint)();
        });
    }

    public function onGitRemoteCompleted(GitRemoteCompleted $event): void
    {
        // A fetch updates the remote-tracking state the branch segment reflects;
        // a pull can move HEAD outright.
        $this->refreshBranch();
    }

    /**
     * Anything moved the working tree — a stash, a stage, an undo, and whoever
     * did it. Core refreshes its own branch indicator rather than asking the
     * mover to remember to, which is what lets a producer core knows nothing
     * about keep the status bar honest.
     */
    public function onWorkingTreeChanged(WorkingTreeChanged $event): void
    {
        $this->refreshBranch();
    }

    public function onGitCommitted(GitCommitted $event): void
    {
        $this->refreshBranch();
    }
}
