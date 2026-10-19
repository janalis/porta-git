<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\Process\PullRequestLookup;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * The pull request number asked of `gh`: a number when it answers with one,
 * nothing otherwise, and no prompt either way.
 */
final class PullRequestLookupTest extends TestCase
{
    use PumpsLoop;

    public function testWithoutGhThereIsNoNumberAndNothingRuns(): void
    {
        $project = new TempProject([]);
        $lane = new ProcessLane();
        $number = 0;

        new PullRequestLookup($project->dir, static fn (): ?string => null, $lane)->number(static function (?int $answer) use (&$number): void {
            $number = $answer;
        });

        $this->assertNull($number, 'answered at once');
        $this->assertSame(0, $lane->inFlight());
    }

    public function testGhIsAskedForTheBranchsPullRequestWithoutAPrompt(): void
    {
        // The script answers with what it was asked and how, so the test sees both.
        $project = new TempProject(['gh' => "#!/bin/sh\n[ \"\$GH_PROMPT_DISABLED\" = 1 ] || exit 3\n[ \"\$*\" = 'pr view --json number --jq .number' ] || exit 4\necho 446\n"]);
        \chmod($project->path('gh'), permissions: 0o755);

        $this->assertSame(446, $this->ask($project));
    }

    private function ask(TempProject $project): ?int
    {
        $lane = new ProcessLane();
        $number = -1;
        new PullRequestLookup($project->dir, static fn (): string => $project->path('gh'), $lane)->number(static function (?int $answer) use (&$number): void {
            $number = $answer;
        });
        $this->await(static function () use (&$number): bool {
            return -1 !== $number;
        }, what: 'gh');

        return $number;
    }

    public function testANonNumberIsNoNumber(): void
    {
        $project = new TempProject(['gh' => "#!/bin/sh\necho 'no pull requests found for branch \"main\"'\n"]);
        \chmod($project->path('gh'), permissions: 0o755);

        $this->assertNull($this->ask($project));
    }
}
