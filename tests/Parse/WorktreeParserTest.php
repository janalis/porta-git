<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Parse;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Parse\WorktreeParser;
use Porta\GitPlugin\Worktree;

/**
 * Fixtures are what git 2.54 printed for `worktree list --porcelain -z` over
 * a scratch repository, NULs and all — not what the documentation implies.
 */
final class WorktreeParserTest extends TestCase
{
    public function testEachBlockIsAWorktreeAndTheFirstIsTheMainOne(): void
    {
        $out =
            "worktree /w/main\0HEAD eef54d01c5b2ec8385489dbdff1d45f1f748b74c\0branch refs/heads/main\0\0"
            ."worktree /w/feat\0HEAD 1111111111111111111111111111111111111111\0branch refs/heads/feature/x\0\0";

        $worktrees = WorktreeParser::parse($out);

        $this->assertEquals(
            [
                new Worktree('/w/main', 'eef54d01c5b2ec8385489dbdff1d45f1f748b74c', 'main', main: true),
                new Worktree('/w/feat', '1111111111111111111111111111111111111111', 'feature/x', main: false),
            ],
            $worktrees,
        );
        $this->assertSame(['main', 'feat'], \array_map(static fn (Worktree $w): string => $w->name(), $worktrees));
        $this->assertSame('feature/x', $worktrees[1]->description(), 'a branch, short');
    }

    public function testADetachedLockedWorktreeKeepsItsReasonWhole(): void
    {
        // -z writes a lock reason raw: a newline in it is just a newline.
        $out =
            "worktree /w/main\0HEAD aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\0branch refs/heads/main\0\0"
            ."worktree /w/det\0HEAD eef54d01c5b2ec8385489dbdff1d45f1f748b74c\0detached\0locked two\nlines\0\0";

        $det = WorktreeParser::parse($out)[1];

        $this->assertNull($det->branch);
        $this->assertSame('eef54d0', $det->description(), 'a detached HEAD reads as its short commit');
        $this->assertSame("two\nlines", $det->lockReason);
        $this->assertTrue($det->locked());
        $this->assertFalse($det->prunable);
    }

    public function testLockedWithoutAReasonIsStillLocked(): void
    {
        $out = "worktree /w/main\0HEAD a\0branch refs/heads/main\0\0worktree /w/l\0HEAD a\0branch refs/heads/l\0locked\0\0";

        $locked = WorktreeParser::parse($out)[1];

        $this->assertSame('', $locked->lockReason);
        $this->assertTrue($locked->locked());
        $this->assertFalse(WorktreeParser::parse($out)[0]->locked());
    }

    public function testAWorktreeWhoseFolderIsGoneIsPrunable(): void
    {
        $out = "worktree /w/main\0HEAD a\0branch refs/heads/main\0\0worktree /w/gone/x\0HEAD a\0branch refs/heads/g\0prunable gitdir file points to non-existent location\0\0";

        $this->assertTrue(WorktreeParser::parse($out)[1]->prunable);
    }

    public function testAPathWithANewlineInItIsOnePath(): void
    {
        // The reason for -z: without it git printed this path raw, as two lines.
        $out = "worktree /w/main\0HEAD a\0branch refs/heads/main\0\0worktree /w/new\nline\0HEAD a\0branch refs/heads/nl\0\0";

        $this->assertSame("/w/new\nline", WorktreeParser::parse($out)[1]->path);
    }

    public function testABareRepositoryIsMainButNoWorktree(): void
    {
        // Measured on `git clone --bare` with one worktree added: the bare
        // repository leads the list, so the worktree after it is not the main one.
        $out = "worktree /w/bare.git\0bare\0\0worktree /w/bare-wt\0HEAD a\0branch refs/heads/main\0\0";

        $worktrees = WorktreeParser::parse($out);

        $this->assertCount(1, $worktrees);
        $this->assertSame('/w/bare-wt', $worktrees[0]->path);
        $this->assertFalse($worktrees[0]->main);
    }

    public function testABranchOutsideRefsHeadsIsKeptWhole(): void
    {
        $out = "worktree /w/main\0HEAD a\0branch refs/remotes/origin/x\0\0";

        $this->assertSame('refs/remotes/origin/x', WorktreeParser::parse($out)[0]->branch);
    }

    public function testNothingParsesToNothing(): void
    {
        $this->assertSame([], WorktreeParser::parse(''));
    }
}
