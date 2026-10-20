<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Parse;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitOperation;
use Porta\GitPlugin\OperationState;
use Porta\GitPlugin\Parse\OperationReader;
use Porta\Tests\Support\TempProject;

/** Which operation git is in the middle of, from the files it keeps — laid out by hand here. */
final class OperationReaderTest extends TestCase
{
    private TempProject $gitDir;

    /** @param array<string, string> $files under the git dir */
    private function read(array $files): ?OperationState
    {
        $this->gitDir = new TempProject($files);

        return OperationReader::read(\array_combine(OperationReader::FILES, \array_map($this->gitDir->path(...), OperationReader::FILES)));
    }

    public function testNothingInProgressIsNull(): void
    {
        $this->assertNull($this->read(['HEAD' => "ref: refs/heads/main\n"]));
    }

    public function testAMergeIsItsHead(): void
    {
        $this->assertEquals(new OperationState(GitOperation::Merging), $this->read(['MERGE_HEAD' => "abc\n"]));
    }

    public function testACherryPickAndARevertAreTheirHeads(): void
    {
        $this->assertEquals(new OperationState(GitOperation::CherryPicking), $this->read(['CHERRY_PICK_HEAD' => "abc\n"]));
        $this->assertEquals(new OperationState(GitOperation::Reverting), $this->read(['REVERT_HEAD' => "abc\n"]));
    }

    public function testARebaseCountsItsSteps(): void
    {
        $this->assertEquals(new OperationState(GitOperation::Rebasing, 3, 7), $this->read(['rebase-merge/msgnum' => "3\n", 'rebase-merge/end' => "7\n"]), 'the merge backend');
        $this->assertEquals(
            new OperationState(GitOperation::Rebasing, 2, 5),
            $this->read(['rebase-apply/rebasing' => '', 'rebase-apply/next' => "2\n", 'rebase-apply/last' => "5\n"]),
            'the apply backend',
        );
    }

    public function testARebaseIsAskedBeforeThePickItStoppedOn(): void
    {
        $this->assertSame(GitOperation::Rebasing, $this->read(['rebase-merge/msgnum' => '1', 'rebase-merge/end' => '1', 'CHERRY_PICK_HEAD' => 'abc'])?->operation);
    }

    public function testAStepCountMissingOrGarbledIsNone(): void
    {
        $this->assertEquals(new OperationState(GitOperation::Rebasing), $this->read(['rebase-merge/end' => "x\n", 'rebase-merge/interactive' => '']));
    }

    public function testGitAmIsNoneOfThese(): void
    {
        // `git am` keeps rebase-apply/ too, without `rebasing` in it.
        $this->assertNull($this->read(['rebase-apply/applying' => '', 'rebase-apply/next' => '1', 'rebase-apply/last' => '2']));
    }

    public function testNoPathsAtAllIsNull(): void
    {
        $this->assertNull(OperationReader::read([]));
    }

    public function testTheStateCountsItsConflictsApart(): void
    {
        $state = new OperationState(GitOperation::Rebasing, 1, 2);

        $this->assertEquals(new OperationState(GitOperation::Rebasing, 1, 2, 3), $state->withConflicts(3));
        $this->assertSame(0, $state->conflicts, 'the original is untouched');
    }
}
