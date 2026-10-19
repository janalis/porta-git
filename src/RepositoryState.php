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

    private ?string $branch = null;

    /** Whether git has said which branch: until it has, a null branch is not yet known, not detached. */
    private bool $branchRead = false;

    private ?Tracking $tracking = null;

    private ?int $lastFetched = null;

    private ?int $pullRequest = null;

    /** The branch the pull request number was last asked for, so a refresh asks again only when it moved. */
    private ?string $askedFor = null;

    /** @var \Closure(): void */
    private \Closure $repaint;

    public function __construct(
        private readonly GitInfo $git,
        private readonly PullRequestLookup $pullRequests,
        string $rootDir,
    ) {
        $this->name = \basename($rootDir);
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
        $this->git->branch(function (?string $branch) use ($scope): void {
            $this->branch = $branch;
            $this->branchRead = true;
            if (RefreshScope::Remote === $scope || $branch !== $this->askedFor) {
                $this->askPullRequest($branch);
            }
            ($this->repaint)();
        });
        $this->git->tracking(function (?Tracking $tracking): void {
            $this->tracking = $tracking;
            ($this->repaint)();
        });
        $this->git->locations(function (?string $top, ?string $common): void {
            if (null === $top || null === $common) {
                return;
            }
            // The main worktree's folder names the repository, as GitHub
            // Desktop does: a linked worktree is a checkout OF it.
            $this->name = '.git' === \basename($common) ? \basename(\dirname($common)) : \basename($top);
            $fetchHead = $common.'/FETCH_HEAD';
            \clearstatcache(true, $fetchHead);
            $fetched = \is_file($fetchHead) ? \filemtime($fetchHead) : false;
            $this->lastFetched = false === $fetched ? null : $fetched;
            ($this->repaint)();
        });
    }

    private function askPullRequest(?string $branch): void
    {
        $this->askedFor = $branch;
        $this->pullRequest = null;
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
}
