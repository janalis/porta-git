<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Worktree;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\TempProject;

/**
 * Which `.git` makes a linked worktree. The gitdir shapes are what git 2.54
 * wrote: `…/.git/worktrees/<id>` for a worktree, `../.git/modules/<name>`
 * for a submodule.
 */
final class WorktreeTest extends TestCase
{
    use GitBinary;

    public function testALinkedWorktreeIsNamedByItsFolder(): void
    {
        $this->skipWithoutGit();
        $main = new TempProject(['a.txt' => "one\n"]);
        $main->initGit();
        $main->commitAll('init');
        $linked = new TempProject([]);
        $main->git('worktree', 'add', '-q', '-b', 'feat', $linked->dir);

        $this->assertSame(\basename($linked->dir), Worktree::linkedName($linked->dir));
        $this->assertNull(Worktree::linkedName($main->dir), 'the main one is the repository itself');
    }

    public function testASubmoduleIsNoWorktree(): void
    {
        $submodule = new TempProject(['.git' => "gitdir: ../.git/modules/mod\n"]);

        $this->assertNull(Worktree::linkedName($submodule->dir));
    }

    public function testASubmoduleOfALinkedWorktreeIsNoWorktreeEither(): void
    {
        $submodule = new TempProject(['.git' => "gitdir: /r/.git/worktrees/wt/modules/mod\n"]);

        $this->assertNull(Worktree::linkedName($submodule->dir));
    }

    public function testNoRepositoryAndAStrangeFileAreNoWorktree(): void
    {
        $none = new TempProject([]);
        $strange = new TempProject(['.git' => "not a gitdir line\n"]);

        $this->assertNull(Worktree::linkedName($none->dir));
        $this->assertNull(Worktree::linkedName($strange->dir));
    }
}
