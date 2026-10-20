<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\Event\GitDiffContextCycleRequested;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitViewRig;
use Porta\GitPlugin\View\ToolbarCell;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

/**
 * The git view's GitHub Desktop half: the toolbar that follows the branch,
 * its upstream and the clock, and the History panes that must not show a
 * stale answer.
 */
final class GitViewToolbarTest extends TestCase
{
    use GitViewRig;

    /**
     * The toolbar reads git when the view opens and again when the working
     * tree moves under it — a branch switch from its own menu is one — but a
     * closed view does not keep asking.
     */
    public function testTheToolbarFollowsTheBranchWhileTheViewIsOpen(): void
    {
        $controller = $this->controller();
        $branch = \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->toolbarText(), $branch), what: 'the branch');

        $this->project->git('checkout', '-q', '-b', 'feature');
        $controller->onWorkingTreeChanged(new WorkingTreeChanged());
        $this->await(fn (): bool => \str_contains($this->toolbarText(), 'feature'), what: 'the switched branch');

        $controller->close();
        $this->project->git('checkout', '-q', '-b', 'later');
        $controller->onWorkingTreeChanged(new WorkingTreeChanged());
        $this->pump(0.3);
        $this->assertStringContainsString('feature', $this->toolbarText(), 'closed, it asks nothing');
    }

    /** A fetch moves what the toolbar says; so does a commit, one more ahead of the upstream. */
    public function testAFetchAndACommitRecountTheUpstream(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->toolbarText(), 'Publish branch'), what: 'no upstream yet');

        $this->project->git('branch', 'base');
        $this->project->git('branch', '--set-upstream-to=base');
        $controller->onRemoteCompleted(new GitRemoteCompleted('fetch', true));
        $this->await(fn (): bool => \str_contains($this->toolbarText(), 'Fetch'), what: 'the upstream after the fetch');

        $this->project->write('a.txt', "ahead\ntwo\n");
        $this->project->git('add', 'a.txt');
        Probe::invoke($controller, 'commit', 'Ahead');
        $this->await(fn (): bool => \str_contains($this->toolbarText(), '⇡1'), what: 'one commit ahead');
        $this->assertStringContainsString('↻ Push', $this->toolbarText(), 'and the remote button offers to push it');
    }

    /** The commit header's copy button puts the full sha on the clipboard and says the short one. */
    public function testTheCommitHeaderCopiesTheFullSha(): void
    {
        $controller = $this->controller(Presence::Without);
        $commit = new LogEntry('f00dfacecafebeef0123456789abcdef01234567', 'f00dfac', 'Alice', 1_700_000_000, 'Subject');

        $controller->onCommitHashCopyRequested(new GitCommitHashCopyRequested($commit));

        $this->assertStringContainsString("\x1b]52;c;".\base64_encode($commit->sha), $this->tui->getTerminal()->getOutput());
        $this->assertStringContainsString('Copied f00dfac', $this->statusRow($this->statusBar));
    }

    /**
     * "3 minutes ago" is worked out at paint time, and a widget repaints only
     * once marked stale: each poll tick marks the toolbar and the commit list,
     * or their times would never age.
     */
    public function testEachPollTickAgesTheRelativeTimes(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $toolbar = $this->toolbar->getRenderRevision();
        $history = $this->history->getRenderRevision();

        $this->pump(2.3); // past one tick of the default two-second poll

        $this->assertGreaterThan($toolbar, $this->toolbar->getRenderRevision());
        $this->assertGreaterThan($history, $this->history->getRenderRevision());
    }

    /** A comparison is two files, not the repository: no toolbar while it shows, and the view's own comes back. */
    public function testAComparisonTakesTheToolbarDownAndTheViewPutsItBack(): void
    {
        $controller = $this->controller();
        $left = $this->project->write('.porta/l.txt', "one\n");
        $right = $this->project->write('.porta/r.txt', "two\n");

        $controller->openComparison($left, $right, static function (): void {});
        $this->assertSame([], $this->region->header()->all());

        $controller->close();
        $controller->open();
        $this->assertSame([$this->toolbar], $this->region->header()->all());
    }

    /** History shows a commit: a context cycle asked for there must not load the working tree's last file. */
    public function testAContextCycleInHistoryLeavesTheCommitsPatch(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "changed\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+changed'), what: 'the working tree diff');
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+.porta/'), what: "the commit's patch");

        $controller->onDiffContextCycled(new GitDiffContextCycleRequested());
        $this->pump(0.3);

        $this->assertStringNotContainsString('+changed', $this->diffText());
        $this->assertStringNotContainsString('Diff context', $this->statusRow($this->statusBar));
    }

    /** A checkout run in the terminal tells the view nothing: every few poll ticks the toolbar asks git again. */
    public function testThePollRereadsTheToolbarEveryFewTicks(): void
    {
        $controller = $this->controller();
        $controller->open();
        $branch = \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD'));
        $this->await(fn (): bool => \str_contains($this->toolbarText(), $branch), what: 'the branch');
        $this->project->git('checkout', '-q', '-b', 'from-the-terminal');
        Probe::set($controller, 'ticks', 4); // the next tick is a re-read

        $this->await(fn (): bool => \str_contains($this->toolbarText(), 'from-the-terminal'), timeout: 5.0, what: 'the re-read');
        $this->addToAssertionCount(1);
    }

    /**
     * Arrowing through a commit's files starts a diff per step: an older ask
     * answering last — a large file — must not show beside the newer one.
     */
    public function testAStaleCommitFileDiffCannotReplaceANewerOne(): void
    {
        $this->skipWithoutGit();
        $useShim = false;
        $shim = null;
        // By reference: the shim is assigned below and switched on for one call.
        $controller = $this->controller(gitPath: static function () use (&$useShim, &$shim): ?string {
            return $useShim ? $shim : null;
        });
        $release = $this->gitHoldingItsAnswer($shim);
        $controller->open();
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+.porta/'), what: 'the commit\'s first file');
        $this->awaitLanes([$this->lane()]);
        $this->freezeStatusPoll($controller);
        $commit = $this->history->selectedCommit();
        $this->assertInstanceOf(LogEntry::class, $commit);

        $useShim = true; // the older ask, held mid-call
        $controller->onCommitFileSelected(new GitCommitFileSelected($commit, '.gitignore'));
        $useShim = false;
        $this->awaitCapture();
        $controller->onCommitFileSelected(new GitCommitFileSelected($commit, 'a.txt'));
        $this->await(fn (): bool => \str_contains($this->diffText(), '+two'), what: 'the newer file');

        $release();
        $this->awaitLanes([$this->lane()]);

        $this->assertStringContainsString('+two', $this->diffText(), 'the older answer was dropped');
    }

    /**
     * A remote whose upload-pack waits $seconds before answering, so a fetch
     * from it is still running while the test looks.
     */
    private function slowOrigin(int $seconds = 1): void
    {
        $bare = new TempProject([]);
        \exec('git clone -q --bare '.\escapeshellarg($this->project->dir).' '.\escapeshellarg($bare->dir.'/origin.git'));
        $this->project->git('remote', 'add', 'origin', $bare->dir.'/origin.git');
        $this->project->git('config', 'remote.origin.uploadpack', 'sleep '.$seconds.'; git-upload-pack');
    }

    /** The remote icon in the toolbar's top row: the cell's first column after its space. */
    private function remoteIcon(): string
    {
        $top = \explode("\n", $this->toolbarText())[0];
        $span = $this->toolbar->span(ToolbarCell::Remote);

        return null === $span ? '' : \mb_substr($top, start: $span[0] + 1, length: 1);
    }

    /** GitHub Desktop's spinning arrows: the remote icon turns while the fetch runs, and rests when it is over. */
    public function testTheRemoteIconTurnsWhileAFetchRunsAndRestsAfter(): void
    {
        $controller = $this->controller();
        $this->slowOrigin();
        $controller->open();
        $seen = [];

        $controller->onRemoteRequested(new GitRemoteRequested(GitRemoteOperation::Fetch));
        $this->await(function () use (&$seen): bool {
            $seen[$this->remoteIcon()] = true;

            return \count(\array_intersect_key($seen, ['◜' => 1, '◝' => 1, '◞' => 1])) >= 2;
        }, what: 'the icon to turn');
        $this->assertStringContainsString('Fetching…', $this->toolbarText());
        $this->await(fn (): bool => '↻' === $this->remoteIcon() && null === Probe::get($controller, 'spinner'), what: 'the icon to rest after the fetch');
        $this->assertStringNotContainsString('Fetching', $this->toolbarText());
    }

    /** An operation the palette started, with the view open: the next poll tick sees it and turns the icon. */
    public function testAnOperationStartedElsewhereIsSeenByThePoll(): void
    {
        $controller = $this->controller();
        $this->slowOrigin(seconds: 3); // past the first two-second tick
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->remoteCommands->fetch();
        $this->await(static fn (): bool => null !== Probe::get($controller, 'spinner'), what: 'the poll to see the fetch'); // the object, re-read each time
        $this->assertNotSame('↻', $this->remoteIcon());

        $controller->close();
        $this->assertNull(Probe::get($controller, 'spinner'), 'a closed view turns nothing');
        $this->assertSame('↻', $this->remoteIcon());
    }

    /** Opening the view while an operation runs turns the icon at once; a completion stops it at once. */
    public function testOpeningMidOperationTurnsTheIconAndACompletionStopsIt(): void
    {
        $controller = $this->controller();
        $this->slowOrigin();
        $this->remoteCommands->fetch();

        $controller->open();
        $this->assertNotNull(Probe::get($controller, 'spinner'));

        $controller->onRemoteCompleted(new GitRemoteCompleted('fetch', true));
        $this->assertNull(Probe::get($controller, 'spinner'));
        $this->assertSame('↻', $this->remoteIcon());
    }
}
