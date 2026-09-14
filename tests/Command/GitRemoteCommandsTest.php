<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porta\Event\GitRemoteCompleted;
use Porta\GitPlugin\Command\GitRemoteCommands;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Process\GitRemote;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class GitRemoteCommandsTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;
    use StatusReads;

    private StatusBarWidget $status;
    private CodeEditorWidget $editor;

    /** @var list<GitRemoteCompleted> */
    private array $events = [];

    private function commands(GitRemote $remote): GitRemoteCommands
    {
        $this->status = new StatusBarWidget();
        $this->events = [];
        $tui = TestTui::create(100, 24);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(GitRemoteCompleted::class, function (GitRemoteCompleted $event): void {
            $this->events[] = $event;
        });

        return new GitRemoteCommands(
            $remote,
            GitDoubles::flash(new Notifier($this->status, $tui)),
            GitDoubles::translate(new Translator('en')),
            $dispatcher,
            GitDoubles::modals($this->editor = new CodeEditorWidget()),
            $tui->requestRender(...),
        );
    }

    /** A repo whose origin is itself — every remote operation completes fast. */
    private function selfRemoteRepo(): GitRemote
    {
        $this->skipWithoutGit();
        $project = new TempProject(['a.txt' => "x\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->git('remote', 'add', 'origin', $project->dir);
        $this->project = $project; // keep the TempProject alive through the async run

        return new GitRemote($project->dir);
    }

    private TempProject $project;

    public function testFetchFlashesStartThenSuccessAndDispatches(): void
    {
        $commands = $this->commands($this->selfRemoteRepo());

        $commands->fetch();
        $this->assertStringContainsString('Fetching…', $this->statusRow($this->status), 'the start flash is up while the operation runs');

        $this->await(fn (): bool => [] !== $this->events);
        $this->assertStringContainsString('Fetched', $this->statusRow($this->status));
        $this->assertSame('fetch', $this->events[0]->operation);
        $this->assertTrue($this->events[0]->ok);
    }

    public function testFailureFlashesGitsFirstErrorLine(): void
    {
        $remote = $this->selfRemoteRepo();
        $this->project->git('remote', 'set-url', 'origin', '/nonexistent-origin');
        $commands = $this->commands($remote);

        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);

        $this->assertStringContainsString('Pull failed:', $this->statusRow($this->status));
        $this->assertFalse($this->events[0]->ok);
        $this->assertSame('pull', $this->events[0]->operation);
    }

    public function testBusyRefusalAndRunOperationRouting(): void
    {
        $remote = $this->selfRemoteRepo();
        $commands = $this->commands($remote);

        $commands->runOperation(GitRemoteOperation::Push);
        $this->assertStringContainsString('Pushing…', $this->statusRow($this->status), 'runOperation routed to push');
        $commands->runOperation(GitRemoteOperation::Fetch);
        $this->assertStringContainsString('already running', $this->statusRow($this->status), 'a second operation is refused while one runs');
        $commands->runOperation(GitRemoteOperation::Pull); // routed, then refused by the busy guard
        // (there is no 'unknown' case any more — the enum is the routing table)

        $this->await(fn (): bool => [] !== $this->events);
        $this->assertCount(1, $this->events, 'only the first operation completed');
    }

    public function testPushSetUpstreamReportsAsAPush(): void
    {
        $commands = $this->commands($this->selfRemoteRepo());

        $commands->pushSetUpstream();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertSame('push', $this->events[0]->operation);
    }

    public function testUnavailableRepositoryFlashesInsteadOfRunning(): void
    {
        $this->skipWithoutGit();
        $plain = new TempProject();
        $commands = $this->commands(new GitRemote($plain->dir));

        $commands->fetch();
        $this->assertStringContainsString('Not a git repository', $this->statusRow($this->status));
        $this->assertSame([], $this->events, 'nothing ran, nothing dispatched');
    }

    /** A diverged bare+clone pair whose next plain pull must fail --ff-only. */
    private function divergedRepo(): GitRemote
    {
        $this->skipWithoutGit();
        $bare = new TempProject();
        $other = new TempProject();
        $mine = new TempProject();
        $this->fixtures = [$bare, $other, $mine];
        $bare->git('init', '-q', '--bare', '.');
        foreach ([$other, $mine] as $clone) {
            $clone->git('clone', '-q', $bare->dir, '.');
            $clone->git('config', 'user.email', 't@t');
            $clone->git('config', 'user.name', 't');
            $clone->git('config', 'commit.gpgsign', 'false');
            $clone->git('config', 'push.autoSetupRemote', 'false');
            $clone->git('config', 'push.default', 'simple');
        }
        \file_put_contents($other->path('a.txt'), data: "one\n");
        $other->git('add', '-A');
        $other->git('commit', '-q', '-m', 'init');
        $other->git('push', '-q', '-u', 'origin', 'HEAD');
        $mine->git('pull', '-q', 'origin');
        $mine->git('branch', '-q', '--set-upstream-to', 'origin/'.$mine->git('rev-parse', '--abbrev-ref', 'HEAD'));
        // Diverge both sides on different files so a rebase/merge is clean.
        \file_put_contents($other->path('remote.txt'), data: "remote\n");
        $other->git('add', '-A');
        $other->git('commit', '-q', '-m', 'remote');
        $other->git('push', '-q', 'origin', 'HEAD');
        \file_put_contents($mine->path('local.txt'), data: "local\n");
        $mine->git('add', '-A');
        $mine->git('commit', '-q', '-m', 'local');
        $this->mine = $mine;

        return new GitRemote($mine->dir);
    }

    /** @var list<TempProject> */
    private array $fixtures = [];
    private TempProject $mine;

    public function testDivergedPullOffersRebaseAndRebasing(): void
    {
        $commands = $this->commands($this->divergedRepo());

        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertTrue(EditorState::pickerOpen($this->editor), 'the diverged pull opened the choice overlay');

        // Pick "Rebase" (the first row).
        $this->editor->handleInput("\r");
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->assertTrue($this->events[1]->ok, 'the rebase pull succeeded');
        $this->assertStringContainsString('Pulled', $this->statusRow($this->status));
        $this->assertFileExists($this->mine->path('remote.txt'));
    }

    public function testDivergedPullCancelThenMerge(): void
    {
        $commands = $this->commands($this->divergedRepo());

        // Round 1: pick "Cancel" (the third row) — nothing runs.
        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r");
        $this->assertFalse(EditorState::pickerOpen($this->editor));
        $this->assertCount(1, $this->events, 'cancelling the choice starts no second operation');

        // Round 2: pick "Merge" (the second row).
        $commands->pull();
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r");
        $this->await(fn (): bool => \count($this->events) >= 3);
        $this->assertTrue($this->events[2]->ok, 'the merge pull succeeded');
        $subject = $this->mine->git('log', '-1', '--format=%s');
        $this->assertStringContainsString('Merge', $subject);
    }

    public function testNonUpstreamPushFailureKeepsTheGenericFlash(): void
    {
        // A diverged push is rejected for a non-upstream reason: the
        // interceptor declines and the generic failure flash appears.
        $commands = $this->commands($this->divergedRepo());

        $commands->push();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertFalse(EditorState::pickerOpen($this->editor), 'a rejected push is not an upstream offer');
        $this->assertStringContainsString('Push failed:', $this->statusRow($this->status));
    }

    public function testPushWithoutUpstreamOffersSetUpstream(): void
    {
        $remote = $this->divergedRepo();
        $this->mine->git('checkout', '-q', '-b', 'feature');
        $commands = $this->commands($remote);

        $commands->push();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertTrue(EditorState::pickerOpen($this->editor), 'the missing-upstream push opened the confirm overlay');

        $this->editor->handleInput("\r"); // "Push with --set-upstream …"
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->assertTrue($this->events[1]->ok, 'the set-upstream push succeeded');
    }

    public function testCancelOperationIdleAndBusy(): void
    {
        $remote = $this->selfRemoteRepo();
        $commands = $this->commands($remote);

        $commands->cancelOperation();
        $this->assertStringContainsString('No git remote operation is running', $this->statusRow($this->status));

        $commands->fetch();
        $this->assertTrue($remote->isBusy());
        $commands->cancelOperation();
        $this->assertFalse($remote->isBusy());
        $this->assertStringContainsString('Git remote operation cancelled', $this->statusRow($this->status));
        $this->assertSame([], $this->events, 'no completion event is synthesized for a cancel');
    }
}
