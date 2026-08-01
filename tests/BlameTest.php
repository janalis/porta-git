<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\GitPlugin\BlameAnnotations;
use Porta\GitPlugin\BlameClient;
use Porta\GitPlugin\BlameLine;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
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
    private BufferManager $buffers;

    private function api(): PluginApi
    {
        $this->project = new TempProject(['a.txt' => "one\ntwo\n"]);
        $settings = new PluginSettings($this->project->dir, []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $this->tui = TestTui::create(120, 24);
        $this->status = new StatusBarWidget();
        $this->editor = new CodeEditorWidget();
        $manager->wireRuntime(new PluginRuntime(
            new Notifier($this->status, $this->tui),
            new Translator('en'),
            $this->editor,
            new RecordingFileOpener(),
            $this->tui,
            new RecordingDiagnosticsSink(),
            $this->editor,
            $this->buffers = new BufferManager(),
            TestDockTools::controller(),
            TestDockTools::replacer(),
            $this->editor,
            new MenuFileContext(),
        ));
        $api = new PluginApi('git', $manager, $this->project->dir, $settings);
        $manager->commit($api);

        return $api;
    }

    /** A committed file, open in the editor, so blame has something to answer about. */
    private function openCommittedFile(PluginApi $api): BlameClient
    {
        $this->skipWithoutGit();
        $this->project->initGit(name: 'tester', email: 't@t');
        $this->project->commitAll('init');
        $this->buffers->open($this->project->path('a.txt'), "one\ntwo\n");
        $this->editor->showDocument($this->project->path('a.txt'), new Document("one\ntwo\n"));

        return new BlameClient($this->project->dir);
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
        $blame = $this->openCommittedFile($api);
        $annotations = new BlameAnnotations($blame, $api);

        $annotations->toggle();
        $this->await(fn (): bool => [] !== Probe::get($this->editor, 'decorations')->lineAnnotations(), what: 'the batch blame resolved');

        $shown = Probe::get($this->editor, 'decorations')->lineAnnotations();
        $this->assertCount(2, $shown, 'one annotation per line');
        $this->assertStringContainsString('tester', $shown[0], 'the author is in the annotation');

        $annotations->toggle();
        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations(), 'toggling off clears them');
    }

    public function testWithBlameOnASwitchOrSaveRepublishes(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile($api);
        $annotations = new BlameAnnotations($blame, $api, showByDefault: true);

        // The editor drops annotations on every document show, which is exactly
        // why the hooks exist: nothing is showing until one fires.
        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations());

        $annotations->refreshIfEnabled();
        $this->await(fn (): bool => [] !== Probe::get($this->editor, 'decorations')->lineAnnotations(), what: 'the hook republished');
        $this->assertNotSame([], Probe::get($this->editor, 'decorations')->lineAnnotations());
    }

    public function testWithBlameOffTheHooksDoNothing(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile($api);
        new BlameAnnotations($blame, $api)->refreshIfEnabled();

        $this->pump(0.2);
        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations(), 'disabled: no blame is run');
    }

    public function testAStaleResponseAfterTogglingOffIsDropped(): void
    {
        $api = $this->api();
        $blame = $this->openCommittedFile($api);
        $annotations = new BlameAnnotations($blame, $api);

        $annotations->toggle(); // on: the batch blame is now in flight
        $annotations->toggle(); // off, before it can answer
        $this->pump(0.5);

        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations(), 'the answer to a cancelled run is dropped');
    }

    public function testEnabledWithNoActiveFileIsANoOp(): void
    {
        $api = $this->api();
        $this->skipWithoutGit();
        $this->project->initGit();
        // Enabled, but nothing is open — refresh has no path to blame.
        new BlameAnnotations(new BlameClient($this->project->dir), $api, showByDefault: true)->refreshIfEnabled();

        $this->pump(0.2);
        $this->assertSame([], Probe::get($this->editor, 'decorations')->lineAnnotations());
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
        $blame = $this->openCommittedFile($api);

        new BlameLine($blame, $api)->show();
        $this->await(fn (): bool => str_contains($this->flashText($this->status), 'tester'), what: 'the single-line blame resolved');
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
