<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Blame;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Blame\BlameAnnotations;
use Porta\GitPlugin\Blame\BlameClient;
use Porta\GitPlugin\Blame\BlameLine;
use Porta\GitPlugin\ChangeMarks;
use Porta\GitPlugin\Command\IndexCommands;
use Porta\GitPlugin\Command\Stash;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Tests\Support\GitPluginHarness;
use Porta\Plugin\Api\PluginApi;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * Blame driven through the real PluginApi against a real repository — the
 * seams it rides (setLineAnnotations, activeFile, caretPosition, flash) are
 * the point of the move, so faking them would test nothing.
 */
final class BlameTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;
    use StatusReads;

    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private Tui $tui;
    private TempProject $project;
    private EventDispatcher $events;
    private BufferManager $buffers;
    private RecordingChangeMarkSink $marks;

    private function api(): PluginApi
    {
        $harness = GitPluginHarness::build(['a.txt' => "one\ntwo\n"]);
        $this->project = $harness->project;
        $this->tui = $harness->tui;
        $this->status = $harness->status;
        $this->editor = $harness->editor;
        $this->events = $harness->events;
        $this->buffers = $harness->buffers;
        $this->marks = $harness->marks;

        return $harness->api;
    }

    /** A committed file, open in the editor, so blame has something to answer about. */
    private function openCommittedFile(): BlameClient
    {
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->buffers->open($this->project->path('a.txt'), "one\ntwo\n");
        $this->editor->showDocument($this->project->path('a.txt'), new Document("one\ntwo\n"));

        return new BlameClient($this->project->dir);
    }

    public function testStagingTheOpenFileAnnouncesAndNamesItRelatively(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");
        $this->buffers->open($this->project->path('a.txt'), "one\nCHANGED\n");

        $announced = 0;
        $this->events->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });

        new IndexCommands(new GitInfo($this->project->dir), $api)->stageCurrent();
        $this->await(static function () use (&$announced): bool {
            return $announced > 0;
        }, what: 'the stage to announce itself');

        $this->assertStringContainsString('a.txt', $this->flashText($this->status), 'the flash names the file');
        $this->assertStringNotContainsString($this->project->dir, $this->flashText($this->status), 'relative to the project, not absolute');
        $this->assertNotSame('', $this->project->git('diff', '--cached', '--name-only'), 'and it really staged');
    }

    public function testStagingWithNoFileOpenSaysSo(): void
    {
        $api = $this->api();
        // No buffer opened, so activeFile() is null.
        new IndexCommands(new GitInfo($this->project->dir), $api)->stageCurrent();

        $this->assertStringContainsString('Git not available', $this->flashText($this->status));
    }

    public function testStagingOutsideARepositorySaysSo(): void
    {
        // A file IS open, so activeFile() is not null and only the availability
        // check can refuse. Without this, deleting that check went unnoticed:
        // the no-file-open test above flashes the SAME words by the other branch.
        $api = $this->api();
        $this->buffers->open($this->project->path('a.txt'), "one\n");

        new IndexCommands(new GitInfo($this->project->dir), $api)->stageCurrent();

        $this->assertStringContainsString('Git not available', $this->flashText($this->status), 'refused before running anything');
    }

    public function testUndoingTheLastCommitSoftResetsAndAnnounces(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nsecond\n");
        $this->project->commitAll('second');

        $announced = 0;
        $this->events->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });

        $index = new IndexCommands(new GitInfo($this->project->dir), $api);
        $index->undoLastCommit();
        $this->await(static function () use (&$announced): bool {
            return $announced > 0;
        }, what: 'the undo to announce itself');
        $this->assertSame('init', \trim($this->project->git('log', '--format=%s')), 'the second commit is gone');
        $this->assertNotSame('', $this->project->git('diff', '--cached', '--name-only'), 'and its changes are still staged');
    }

    public function testUndoingWithNothingToUndoSaysSo(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();
        $this->project->commitAll('init'); // the root commit has no parent

        new IndexCommands(new GitInfo($this->project->dir), $api)->undoLastCommit();
        $this->await(fn (): bool => '' !== $this->flashText($this->status), what: 'the refusal to flash');
        $this->assertStringContainsString('Nothing to undo', $this->flashText($this->status));
    }

    public function testUndoingOutsideARepositorySaysSo(): void
    {
        $api = $this->api();
        // No initGit: the runner answers every run as a failure, so the verb
        // refuses before spawning anything.
        new IndexCommands(new GitInfo($this->project->dir), $api)->undoLastCommit();

        $this->assertStringContainsString('Git not available', $this->flashText($this->status));
    }

    public function testStashPushAndPopAnnounceTheWorkingTreeChanged(): void
    {
        // The verbs that proved emit(): they run git, flash git's own words,
        // and put WorkingTreeChanged on the bus so the gutter stripe — this
        // plugin's own, and anyone else's — knows the tree moved.
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");

        // Registered on the bus directly: on() is a REGISTRATION and refuses
        // after the plugin is committed, which the rig has already done.
        $announced = 0;
        $this->events->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });

        $stash = new Stash(new GitInfo($this->project->dir), $api);
        $stash->push();
        $this->await(static function () use (&$announced): bool {
            return $announced > 0;
        }, what: 'the stash to announce itself');
        $this->assertSame("one\ntwo\n", \file_get_contents($this->project->path('a.txt')), 'the working tree really went back to the committed content');

        $stash->pop();
        $this->await(static function () use (&$announced): bool {
            return $announced > 1;
        }, what: 'the pop to announce itself');
        $this->assertSame("one\nCHANGED\n", \file_get_contents($this->project->path('a.txt')), 'and really came back');
    }

    public function testStashWithoutARepositorySaysSo(): void
    {
        $api = $this->api();
        // No initGit: every run answers as a failure, so both verbs refuse.
        $stash = new Stash(new GitInfo($this->project->dir), $api);

        $stash->push();
        $this->assertStringContainsString('Git not available', $this->flashText($this->status));

        $stash->pop();
        $this->assertStringContainsString('Git not available', $this->flashText($this->status));
    }

    public function testAFailedPushAnnouncesNothing(): void
    {
        // A repo with no initial commit: git is "available" (binary + .git), so
        // the verb runs and fails outright — the one way to reach push's
        // failure branch, since stashing with nothing to stash exits 0.
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();
        $this->project->write('a.txt', "one\nCHANGED\n");

        $announced = 0;
        $this->events->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });

        new Stash(new GitInfo($this->project->dir), $api)->push();
        $this->await(fn (): bool => '' !== $this->flashText($this->status), what: 'the failure to flash');

        $this->assertStringContainsString('Stash failed', $this->flashText($this->status));
        $this->assertSame(0, $announced, 'nothing was stashed, so the tree did not move');
    }

    public function testAFailedPopReportsGitsOwnMessage(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();
        $this->project->commitAll('init');

        // Nothing stashed: git refuses and its stderr line is what shows.
        $announced = 0;
        $this->events->addListener(WorkingTreeChanged::class, static function () use (&$announced): void {
            ++$announced;
        });

        new Stash(new GitInfo($this->project->dir), $api)->pop();
        $this->await(fn (): bool => '' !== $this->flashText($this->status), what: 'the failure to flash');
        $this->assertStringNotContainsString('Stash popped', $this->flashText($this->status));
        $this->assertSame(0, $announced, 'a refused pop moved nothing, so it announced nothing');
    }

    public function testTheStripeSettingOffRunsNoDiffAtAll(): void
    {
        // The `gutterMarks` guard. The second half is the control: without it
        // this would pass just as well if the fixture were wrong and there were
        // no marks to find either way.
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");
        $this->buffers->open($this->project->path('a.txt'), "one\nCHANGED\n");

        new ChangeMarks(new GitInfo($this->project->dir), $api, enabled: false)->refresh();
        $this->pump(0.2); // a non-event: nothing is spawned, so there is nothing to await
        $this->assertSame([], $this->marks->published, 'the setting off publishes nothing');

        new ChangeMarks(new GitInfo($this->project->dir), $api, enabled: true)->refresh();
        $this->await(fn (): bool => [] !== $this->marks->published, what: 'the same fixture to publish once the setting is on');
    }

    public function testTheStripeSettingIsReReadPerRefreshSoTurningItOffStopsTheDiffs(): void
    {
        // The plugin hands a closure over the live holder. Baking the flag at
        // construction left a `git diff` spawning per buffer switch for a
        // feature the user had just switched off, until the next launch.
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");
        $this->buffers->open($this->project->path('a.txt'), "one\nCHANGED\n");

        $wanted = true;
        $marks = new ChangeMarks(new GitInfo($this->project->dir), $api, static function () use (&$wanted): bool {
            return $wanted;
        });
        $marks->refresh();
        $this->await(fn (): bool => [] !== $this->marks->published, what: 'the stripe while the setting is on');

        $wanted = false;
        $this->marks->published = [];
        $marks->refresh();
        $this->pump(0.2); // a non-event: nothing is spawned, so there is nothing to await

        $this->assertSame([], $this->marks->published, 'turning it off stopped the diff with no rebuild');
    }

    public function testARefreshHonoursThePathItIsGivenOverTheActiveFile(): void
    {
        // The reload hook hands over the file that changed on disk, which need
        // not be the one on screen. Publishing the ACTIVE file's marks instead
        // would leave the reloaded file's stripe stale and nothing would say so.
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->write('b.txt', "b one\n");
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");
        $this->buffers->open($this->project->path('b.txt'), "b one\n"); // b.txt is active

        new ChangeMarks(new GitInfo($this->project->dir), $api, enabled: true)->refresh($this->project->path('a.txt'));

        $this->await(fn (): bool => [] !== $this->marks->published, what: 'the diff for the named file to publish');
        $this->assertSame($this->project->path('a.txt'), $this->marks->published[0]['path'], 'published for the file it was handed');
    }

    public function testRefreshesLandingWhileADiffIsInFlightCoalesceIntoOneRerun(): void
    {
        // Holding Ctrl+Tab through the same buffers fired one `git diff` per
        // revisit. In-flight refreshes for a path now coalesce: one rerun
        // follows the answer (a plain skip would drop a save's fresh marks).
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\nCHANGED\n");
        $path = $this->project->path('a.txt');

        $marks = new ChangeMarks(new GitInfo($this->project->dir), $api, enabled: true);
        $marks->refresh($path); // spawns the diff
        $marks->refresh($path); // in flight: coalesces
        $marks->refresh($path); // still ONE pending rerun, not two

        $this->await(fn (): bool => \count($this->marks->published) >= 2, what: 'the run and its one coalesced rerun to publish');
        $this->pump(0.3); // room for a third publish that must not exist
        $this->assertCount(2, $this->marks->published, 'three refreshes while in flight = the run plus one rerun');
    }

    public function testBlameFileCoversCommittedDirtyAndMissingLines(): void
    {
        $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\ntwo\nthree\n"); // a dirty extra line
        $blame = new BlameClient($this->project->dir);

        $lines = null;
        $blame->file($this->project->path('a.txt'), static function (array $result) use (&$lines): void {
            $lines = $result;
        });
        $this->await(static function () use (&$lines): bool {
            return null !== $lines;
        });
        $this->assertCount(3, $lines);
        $this->assertSame('tester', $lines[0]['author']);
        $this->assertNotSame('', $lines[0]['date']);
        $this->assertSame('Not Committed Yet', $lines[2]['author'], 'the dirty line is uncommitted');

        $missing = null;
        $blame->file($this->project->path('nope.txt'), static function (array $result) use (&$missing): void {
            $missing = $result;
        });
        $this->await(static function () use (&$missing): bool {
            return null !== $missing;
        });
        $this->assertSame([], $missing, 'blame on a missing file degrades to empty');
    }

    public function testTogglingOnAnnotatesEveryLineAndTogglingOffClearsThem(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile();
        $annotations = new BlameAnnotations($blame, $api);

        $annotations->toggle();
        $this->await(fn (): bool => [] !== $this->editor->decorations->lineAnnotations, what: 'the batch blame resolved');

        $shown = $this->editor->decorations->lineAnnotations;
        $this->assertCount(2, $shown, 'one annotation per line');
        $this->assertStringContainsString('tester', $shown[0], 'the author is in the annotation');

        $annotations->toggle();
        $this->assertSame([], $this->editor->decorations->lineAnnotations, 'toggling off clears them');
    }

    public function testWithBlameOnASwitchOrSaveRepublishes(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile();
        $annotations = new BlameAnnotations($blame, $api, showByDefault: true);

        // The editor drops annotations on every document show, which is exactly
        // why the hooks exist: nothing is showing until one fires.
        $this->assertSame([], $this->editor->decorations->lineAnnotations);

        $annotations->refreshIfEnabled();
        $this->await(fn (): bool => [] !== $this->editor->decorations->lineAnnotations, what: 'the hook republished');
        $this->assertNotSame([], $this->editor->decorations->lineAnnotations);
    }

    public function testTheBlameDefaultSettingTurnsAnnotationsOnAndOffLive(): void
    {
        // The setting seeds a toggle whose live state lives HERE, so reaching
        // the GitSettings holder was never enough — setBlameDefault() had no
        // readers at all, which is why flipping the row did nothing until a
        // restart. Two directions, because only showing would pass with a
        // setter that ignores false.
        $api = $this->api();
        $blame = $this->openCommittedFile();
        $annotations = new BlameAnnotations($blame, $api);
        $this->assertSame([], $this->editor->decorations->lineAnnotations, 'off to begin with');

        $annotations->setEnabled(true);
        $this->await(fn (): bool => [] !== $this->editor->decorations->lineAnnotations, what: 'the setting to show annotations');

        $annotations->setEnabled(false);
        $this->assertSame([], $this->editor->decorations->lineAnnotations, 'and to hide them again');
    }

    public function testSettingBlameDefaultToTheStateItAlreadyHasIsANoOp(): void
    {
        // Every settings write on this plugin reaches the hook, so a setter
        // that acted unconditionally would spawn a `git blame` — and flash —
        // on each one. The sentinel asserts a POSITIVE: nothing overwrote it.
        $api = $this->api();
        $blame = $this->openCommittedFile();
        $annotations = new BlameAnnotations($blame, $api);
        $this->editor->decorations->setLineAnnotations(['sentinel']);

        $annotations->setEnabled(false);
        $this->pump(0.2); // a non-event: nothing is spawned, so there is nothing to await

        $this->assertSame(['sentinel'], $this->editor->decorations->lineAnnotations, 'already off: nothing ran');
    }

    public function testWithBlameOffTheHooksDoNothing(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile();

        // A sentinel, so this asserts a POSITIVE: nothing overwrote it. Asserting
        // the annotations stay EMPTY passed whether or not the run started —
        // an async answer that has not arrived yet looks exactly like one that
        // was never asked for.
        //
        // What this pins is that a disabled toggle never WRITES. It does not
        // pin the early return in refreshIfEnabled(), which is an optimisation:
        // deleting it leaves this green, because the callback's own !enabled
        // guard still drops the answer. See the note on that method.
        $api->setLineAnnotations([0 => 'sentinel']);
        new BlameAnnotations($blame, $api)->refreshIfEnabled();
        $this->pump(2.0); // ample: the enabled tests above resolve well inside this

        $this->assertSame([0 => 'sentinel'], $this->editor->decorations->lineAnnotations, 'disabled: no blame is run, so nothing replaced the sentinel');
    }

    public function testAStaleResponseAfterTogglingOffIsDropped(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile();
        $annotations = new BlameAnnotations($blame, $api);

        $annotations->toggle(); // on: the batch blame is now in flight
        $annotations->toggle(); // off, before it can answer
        $this->pump(0.5);

        $this->assertSame([], $this->editor->decorations->lineAnnotations, 'the answer to a cancelled run is dropped');
    }

    public function testEnabledWithNoActiveFileIsANoOp(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();
        // Enabled, but nothing is open — refresh has no path to blame.
        new BlameAnnotations(new BlameClient($this->project->dir), $api, showByDefault: true)->refreshIfEnabled();

        $this->pump(0.2);
        $this->assertSame([], $this->editor->decorations->lineAnnotations);
    }

    public function testTogglingOnWithoutARepositorySaysSo(): void
    {
        $api = $this->api();
        // No initGit: the runner answers every run as a failure, so the toggle
        // must refuse rather than turn on and show nothing.
        new BlameAnnotations(new BlameClient($this->project->dir), $api)->toggle();

        $this->assertStringContainsString('Git not available', $this->flashText($this->status));
    }

    public function testTheLineCommandFlashesTheCommitUnderTheCaret(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile();

        new BlameLine($blame, $api)->show();
        $this->await(fn (): bool => \str_contains($this->flashText($this->status), 'tester'), what: 'the single-line blame resolved');
        $this->assertStringContainsString('init', $this->flashText($this->status), 'the summary is the commit subject');
    }

    public function testTheLineCommandWithNoFileOpenSaysSo(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();

        new BlameLine(new BlameClient($this->project->dir), $api)->show();
        $this->assertStringContainsString('Git not available', $this->flashText($this->status));
    }

    public function testAnUncommittedLineHasNoBlame(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->project->write('b.txt', "fresh\n");
        $this->buffers->open($this->project->path('b.txt'), "fresh\n");
        $this->editor->showDocument($this->project->path('b.txt'), new Document("fresh\n"));

        new BlameLine(new BlameClient($this->project->dir), $api)->show();
        $this->await(fn (): bool => '' !== $this->flashText($this->status), what: 'the blame answered');
        $this->assertStringContainsString('No blame for this line', $this->flashText($this->status));
    }
}
