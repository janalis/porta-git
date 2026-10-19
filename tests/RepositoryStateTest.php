<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\Process\PullRequestLookup;
use Porta\GitPlugin\RefreshScope;
use Porta\GitPlugin\RepositoryState;
use Porta\GitPlugin\Tracking;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * What the git view's toolbar reads: the repository's name, the branch and
 * its distance from upstream, the last fetch, and the pull request — each
 * against a real repository, and `gh` stood in for by a script.
 */
final class RepositoryStateTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;

    private ProcessLane $lane;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $this->project = new TempProject(['a.txt' => "one\n"]);
        $this->project->initGit('main');
        $this->project->commitAll('first');
        $this->lane = new ProcessLane();
    }

    /** A state whose `gh` is a script answering $answer with $code, or no `gh` at all. */
    private function state(?string $answer = null, int $code = 0, ?string $root = null): RepositoryState
    {
        $gh = null;
        if (null !== $answer) {
            $this->project->write('bin/gh', "#!/bin/sh\necho '{$answer}'\nexit {$code}\n");
            \chmod($this->project->path('bin/gh'), permissions: 0o755);
            $gh = $this->project->path('bin/gh');
        }
        $root ??= $this->project->dir;

        return new RepositoryState(new GitInfo($root), new PullRequestLookup($root, static fn (): ?string => $gh, $this->lane), $root);
    }

    private function settle(RepositoryState $state, RefreshScope $scope = RefreshScope::Local): int
    {
        $repaints = 0;
        $state->onChange(static function () use (&$repaints): void {
            ++$repaints;
        });
        $state->refresh($scope);
        $lane = $this->lane;
        $this->await(static function () use (&$repaints, $lane): bool {
            return 0 === $lane->inFlight() && $repaints >= 3;
        }, what: 'every answer');
        $this->pump(0.05);

        return $repaints;
    }

    public function testTheBranchItsUpstreamAndThePullRequestAreRead(): void
    {
        $this->project->git('branch', 'base');
        $this->project->git('branch', '--set-upstream-to=base');
        $this->project->write('a.txt', "two\n");
        $this->project->commitAll('second');
        $state = $this->state('446');

        $this->settle($state);

        $this->assertSame(\basename($this->project->dir), $state->name());
        $this->assertSame('main', $state->branch());
        $this->assertEquals(new Tracking(1, 0), $state->tracking());
        $this->assertSame(446, $state->pullRequest());
        $this->assertNull($state->lastFetched(), 'never fetched');
    }

    public function testBehindCountsTheUpstreamsOwnCommits(): void
    {
        $this->project->git('checkout', '-q', '-b', 'base');
        $this->project->write('b.txt', "b\n");
        $this->project->commitAll('on base');
        $this->project->git('checkout', '-q', 'main');
        $this->project->git('branch', '--set-upstream-to=base');
        $state = $this->state();

        $this->settle($state);

        $this->assertEquals(new Tracking(0, 1), $state->tracking());
        $this->assertNull($state->pullRequest(), 'no gh, no number');
    }

    public function testABranchWithNoUpstreamHasNoTracking(): void
    {
        $state = $this->state();

        $this->settle($state);

        $this->assertNull($state->tracking());
    }

    public function testTheLastFetchIsWhenFetchHeadWasWritten(): void
    {
        $this->project->write('.git/FETCH_HEAD', '');
        \touch($this->project->path('.git/FETCH_HEAD'), mtime: 1_700_000_000);
        $state = $this->state();

        $this->settle($state);

        $this->assertSame(1_700_000_000, $state->lastFetched());
    }

    /** A linked worktree is a checkout of the repository: the main folder names it, and its fetch is the main one's. */
    public function testALinkedWorktreeIsNamedForItsRepository(): void
    {
        $linked = $this->project->path('elsewhere');
        $this->project->git('worktree', 'add', '-q', '-b', 'side', $linked);
        $this->project->write('.git/FETCH_HEAD', '');
        \touch($this->project->path('.git/FETCH_HEAD'), mtime: 1_700_000_000);
        $state = $this->state(root: $linked);

        $this->settle($state);

        $this->assertSame(\basename($this->project->dir), $state->name());
        $this->assertSame('side', $state->branch());
        $this->assertSame(1_700_000_000, $state->lastFetched());
    }

    public function testAFailedOrStrangeAnswerFromGhIsNoNumber(): void
    {
        $failed = $this->state('446', code: 1);
        $this->settle($failed);
        $this->assertNull($failed->pullRequest(), 'gh failed: no pull request for this branch');

        $strange = $this->state('no pull requests found');
        $this->settle($strange);
        $this->assertNull($strange->pullRequest());
    }

    public function testAPullRequestIsAskedAgainOnlyWhenTheBranchMovesOrAfterAFetch(): void
    {
        $state = $this->state('446');
        $this->settle($state);
        $this->assertSame(446, $state->pullRequest());

        $this->project->write('bin/gh', "#!/bin/sh\necho 512\n");
        $this->settle($state);
        $this->assertSame(446, $state->pullRequest(), 'same branch: gh is not asked again');

        $this->settle($state, RefreshScope::Remote);
        $this->assertSame(512, $state->pullRequest(), 'a fetch asks again');

        $this->project->git('checkout', '-q', '-b', 'feature');
        $this->project->write('bin/gh', "#!/bin/sh\necho 600\n");
        $this->settle($state);
        $this->assertSame('feature', $state->branch());
        $this->assertSame(600, $state->pullRequest(), 'a new branch asks again');
    }

    /** gh is slow and the branch moves on meanwhile: its answer was for the branch left behind. */
    public function testAnAnswerForABranchLeftBehindIsDropped(): void
    {
        // Two scripts, not one rewritten: sh reads its script as it runs.
        $this->project->write('bin/slow', "#!/bin/sh\nsleep 1\necho 111\n");
        $this->project->write('bin/fast', "#!/bin/sh\necho 222\n");
        \chmod($this->project->path('bin/slow'), permissions: 0o755);
        \chmod($this->project->path('bin/fast'), permissions: 0o755);
        $gh = $this->project->path('bin/slow');
        $state = new RepositoryState(
            new GitInfo($this->project->dir),
            new PullRequestLookup(
                $this->project->dir,
                // @mago-expect lint:prefer-arrow-function -- by reference: the path is swapped mid-test, and an arrow fn would keep the first
                static function () use (&$gh): string {
                    return $gh;
                },
                $this->lane,
            ),
            $this->project->dir,
        );
        $state->refresh();
        $this->await(static function () use ($state): bool {
            return 'main' === $state->branch();
        }, what: 'the first branch');

        $gh = $this->project->path('bin/fast');
        $this->project->git('checkout', '-q', '-b', 'feature');
        $this->settle($state);

        $this->assertSame('feature', $state->branch());
        $this->assertSame(222, $state->pullRequest(), 'the slow answer for main did not overwrite it');
    }

    /** Leaving the branch for a detached HEAD drops its pull request: there is no branch to have one. */
    public function testADetachedHeadHasNoPullRequest(): void
    {
        $state = $this->state('446');
        $this->settle($state);
        $this->assertSame(446, $state->pullRequest());

        $this->project->git('checkout', '-q', '--detach');
        $this->settle($state);

        $this->assertNull($state->branch());
        $this->assertNull($state->pullRequest());
    }

    public function testOutsideARepositoryOnlyTheFolderNamesIt(): void
    {
        $plain = new TempProject(['a.txt' => "x\n"]);
        $state = new RepositoryState(new GitInfo($plain->dir), new PullRequestLookup($plain->dir, static fn (): ?string => null, $this->lane), $plain->dir);
        $state->refresh();

        $this->assertSame(\basename($plain->dir), $state->name());
        $this->assertNull($state->branch());
        $this->assertNull($state->tracking());
        $this->assertNull($state->lastFetched());
    }
}
