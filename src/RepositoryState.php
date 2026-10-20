<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Process\PullRequestLookup;

/**
 * What the git view's toolbar says about the repository: its name, the branch
 * and how far it is from its upstream, when it was last fetched, and the pull
 * request open for the branch.
 *
 * Refreshed on demand rather than polled — the view asks when it opens and
 * after anything that can move these (a commit, a fetch, a branch switch).
 * The answers arrive one by one, and each repaints, so a slow `gh` never holds
 * the branch back.
 */
final class RepositoryState
{
    private string $name;

    /** The open working tree's folder: the clone's, or a linked worktree's. */
    private string $worktree;

    private ?string $branch = null;

    /** Whether git has said which branch: until it has, a null branch is not yet known, not detached. */
    private bool $branchRead = false;

    private ?Tracking $tracking = null;

    private ?int $lastFetched = null;

    private ?int $pullRequest = null;

    /** The remote the branch tracks, or null when it is unpublished — or detached. */
    private ?string $remote = null;

    /** How many working trees the repository has: one, unless worktrees were added. */
    private int $worktrees = 1;

    /** The branch the pull request number was last asked for, so a refresh asks again only when it moved. */
    private ?string $askedFor = null;

    /**
     * Issue order for refreshes: a pull and a branch switch can each start
     * one while another is out, and their answers come back in any order —
     * an older one landing last would paint a stale branch or count.
     */
    private int $generation = 0;

    /** @var \Closure(): void */
    private \Closure $repaint;

    public function __construct(
        private readonly GitInfo $git,
        private readonly PullRequestLookup $pullRequests,
        string $rootDir,
    ) {
        $this->name = \basename($rootDir);
        $this->worktree = $this->name;
        $this->repaint = static function (): void {};
    }

    /** @param \Closure(): void $repaint asks for a frame when an answer lands */
    public function onChange(\Closure $repaint): void
    {
        $this->repaint = $repaint;
    }

    /**
     * Ask git again. A fetch passes $remote: the branch may be the same, but a
     * pull request opened on the server since is news only the remote has.
     */
    public function refresh(RefreshScope $scope = RefreshScope::Local): void
    {
        $generation = ++$this->generation;
        $this->git->branch(function (?string $branch) use ($scope, $generation): void {
            if ($generation !== $this->generation) {
                return; // a newer refresh is out
            }
            $this->branch = $branch;
            $this->branchRead = true;
            if (RefreshScope::Remote === $scope || $branch !== $this->askedFor) {
                $this->askPullRequest($branch);
            }
            $this->askRemote($branch, $generation);
            ($this->repaint)();
        });
        $this->git->tracking(function (?Tracking $tracking) use ($generation): void {
            if ($generation !== $this->generation) {
                return;
            }
            $this->tracking = $tracking;
            ($this->repaint)();
        });
        $this->git->locations(function (?Locations $at) use ($generation): void {
            if (null !== $at && $generation === $this->generation) {
                $this->located($at);
            }
        });
        $this->git->worktrees(function (?array $worktrees) use ($generation): void {
            if (null !== $worktrees && $generation === $this->generation) {
                $this->worktrees = \max(1, \count($worktrees));
                ($this->repaint)();
            }
        });
    }

    /** The remote the branch tracks, asked once git has said which branch. */
    private function askRemote(?string $branch, int $generation): void
    {
        if (null === $branch) {
            $this->remote = null;

            return;
        }
        $this->git->remoteOf($branch, function (?string $remote) use ($generation): void {
            if ($generation === $this->generation) {
                $this->remote = $remote;
                ($this->repaint)();
            }
        });
    }

    private function located(Locations $at): void
    {
        // The main worktree's folder names the repository, as GitHub
        // Desktop does: a linked worktree is a checkout OF it.
        $this->name = '.git' === \basename($at->common) ? \basename(\dirname($at->common)) : \basename($at->top);
        $this->worktree = \basename($at->top);
        \clearstatcache(clear_realpath_cache: true, filename: $at->fetchHead);
        $fetched = \is_file($at->fetchHead) ? \filemtime($at->fetchHead) : false;
        $this->lastFetched = false === $fetched ? null : $fetched;
        ($this->repaint)();
    }

    private function askPullRequest(?string $branch): void
    {
        // A new branch's number is not the old one's; the same branch keeps
        // its badge while gh is asked again, rather than blanking for the call.
        if ($branch !== $this->askedFor) {
            $this->pullRequest = null;
        }
        $this->askedFor = $branch;
        if (null === $branch) {
            return; // a detached HEAD has no pull request
        }
        $this->pullRequests->number(function (?int $number) use ($branch): void {
            if ($branch !== $this->branch) {
                return; // the branch moved on while gh was asked
            }
            $this->pullRequest = $number;
            ($this->repaint)();
        });
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The branch, or null on a detached HEAD — or before git has answered; see {@see branchRead()}. */
    public function branch(): ?string
    {
        return $this->branch;
    }

    public function branchRead(): bool
    {
        return $this->branchRead;
    }

    /** Null without an upstream. */
    public function tracking(): ?Tracking
    {
        return $this->tracking;
    }

    /** When the last fetch finished (a Unix timestamp), or null when the repository was never fetched. */
    public function lastFetched(): ?int
    {
        return $this->lastFetched;
    }

    public function pullRequest(): ?int
    {
        return $this->pullRequest;
    }

    /** The remote the branch tracks — `origin`, usually — or null. */
    public function remote(): ?string
    {
        return $this->remote;
    }

    /** The open working tree's folder name. */
    public function worktree(): string
    {
        return $this->worktree;
    }

    /** Whether worktrees were added beside the clone: then the toolbar names the one open. */
    public function hasWorktrees(): bool
    {
        return $this->worktrees > 1;
    }
}
