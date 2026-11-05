<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\RepositoryAbove;
use Porta\Tests\Support\TempProject;

final class RepositoryAboveTest extends TestCase
{
    public function testTheNearestParentWithAGitIsTheRepository(): void
    {
        $project = new TempProject(['.git/HEAD' => "ref: refs/heads/main\n", 'app/web/.keep' => '', 'app/.git' => "gitdir: ../.git/worktrees/app\n"]);

        $this->assertSame($project->path('app'), RepositoryAbove::of($project->path('app/web')), 'a worktree\'s .git is a file');
        $this->assertSame($project->dir, RepositoryAbove::of($project->path('app')), 'its own .git is not above it');
    }

    public function testTheFilesystemRootEndsTheWalk(): void
    {
        $this->assertNull(RepositoryAbove::of('/'));
    }
}
