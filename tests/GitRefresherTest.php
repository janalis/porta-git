<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Real git repo, virtual terminal — mirrors GitViewControllerTest's setup. */
final class GitRefresherTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;

    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private function refresher(Presence $realRepo = Presence::With, ?GitSettings $gitSettings = null): GitRefresher
    {
        if (Presence::With === $realRepo && null === $this->whichGit()) {
            $this->markTestSkipped('git not available');
        }
        $this->project = new TempProject(['a.txt' => "one\ntwo\n"]);
        if (Presence::With === $realRepo) {
            $this->project->initGit(branch: 'trunk'); // 'trunk' is asserted as the branch
            $this->project->git('config', 'user.email', 't@t');
            $this->project->git('config', 'user.name', 't');
            $this->project->git('config', 'commit.gpgsign', 'false');
            $this->project->git('add', '-A');
            $this->project->git('commit', '-q', '-m', 'init');
        }
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new GitRefresher(new GitInfo($this->project->dir), static function (): void {});
    }

    public function testACommitRefreshesTheBranchThroughTheBus(): void
    {
        // The refresh verbs are tested directly above and below; what is not
        // tested anywhere is that a commit reaches them, which is the whole
        // job of the listener.
        $refresher = $this->refresher();
        $events = new EventDispatcher();
        $events->addListener(GitCommitted::class, $refresher->onGitCommitted(...));

        $events->dispatch(new GitCommitted());

        $this->await(static function () use (&$refresher): bool {
            return null !== $refresher->branch();
        }, what: 'the commit triggered the branch refresh');
        $this->assertSame('trunk', $refresher->branch());
    }

    public function testARemoteOperationRefreshesTheBranchThroughTheBus(): void
    {
        // A fetch updates remote-tracking state; a pull can move HEAD. The
        // MARKS half of this listener moved to the git plugin — what stayed is
        // the branch, which is still core's status-bar content.
        $refresher = $this->refresher();
        $events = new EventDispatcher();
        $events->addListener(GitRemoteCompleted::class, $refresher->onGitRemoteCompleted(...));

        $events->dispatch(new GitRemoteCompleted('pull', ok: true));

        $this->await(static function () use (&$refresher): bool {
            return null !== $refresher->branch();
        }, what: 'the remote completion triggered the branch refresh');
        $this->assertSame('trunk', $refresher->branch());
    }

    public function testAWorkingTreeChangeRefreshesTheBranchThroughTheBus(): void
    {
        // The listener that makes a producer core knows nothing about keep the
        // status bar honest: the git plugin's stash, stage and undo announce
        // WorkingTreeChanged and core refreshes its own indicator. The plugin
        // side proves the emit; without this, nothing proves anyone listens.
        $refresher = $this->refresher();
        $events = new EventDispatcher();
        $events->addListener(WorkingTreeChanged::class, $refresher->onWorkingTreeChanged(...));

        $events->dispatch(new WorkingTreeChanged());

        $this->await(static function () use (&$refresher): bool {
            return null !== $refresher->branch();
        }, what: 'the working-tree announcement triggered the branch refresh');
        $this->assertSame('trunk', $refresher->branch());
    }

    public function testOutsideARepositoryTheRefreshIsASilentNoOp(): void
    {
        // No git binary, or a directory that is not a repository: the branch
        // cell shows nothing, and — the part that matters — no subprocess is
        // started. This fires on every save and on every working-tree change,
        // so spawning one per event outside a repository would be a constant
        // cost for an answer that can never arrive.
        $refresher = $this->refresher(Presence::Without);

        $refresher->refreshBranch();

        $this->assertNull($refresher->branch());
    }

    public function testRefreshBranchExposesTheBranchForTheStatusBar(): void
    {
        $refresher = $this->refresher();
        $this->assertNull($refresher->branch(), 'no branch before the first refresh');

        $refresher->refreshBranch();
        $this->await(static function () use (&$refresher): bool {
            return null !== $refresher->branch();
        }, what: 'the async branch lookup resolved');

        $this->assertSame('trunk', $refresher->branch());
    }
}
