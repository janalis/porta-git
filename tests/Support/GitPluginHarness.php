<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Support\Clipboard;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * A committed PluginApi for this plugin's own unit tests, with the pieces a
 * test asserts against kept beside it.
 *
 * The twenty-argument runtime is a rig, not a subject: two tests writing it out
 * is two places to update when the runtime grows an argument, and the
 * duplication gate said so the moment the second one appeared.
 */
final class GitPluginHarness
{
    private function __construct(
        public readonly PluginApi $api,
        public readonly TempProject $project,
        public readonly Tui $tui,
        public readonly StatusBarWidget $status,
        public readonly CodeEditorWidget $editor,
        public readonly EventDispatcher $events,
        public readonly BufferManager $buffers,
        public readonly RecordingChangeMarkSink $marks,
        public readonly FileStatuses $statuses,
    ) {}

    /** @param array<string, string> $files the project the api answers about */
    public static function build(array $files): self
    {
        $project = new TempProject($files);
        $settings = new PluginSettings($project->dir, []);
        $manager = new PluginManager($events = new EventDispatcher(), $settings);
        $tui = TestTui::create(120, 24);
        $status = new StatusBarWidget();
        $editor = new CodeEditorWidget();
        $manager->wireRuntime(new PluginRuntime(
            new Notifier($status, $tui),
            new Translator('en'),
            $editor,
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            $editor->decorations(),
            $marks = new RecordingChangeMarkSink(),
            $statuses = new FileStatuses(),
            $buffers = new BufferManager(),
            TestDockTools::controller(),
            TestDockTools::replacer(),
            $editor,
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
            new FakePluginLifecycle(),
            new FileTreeWidget(sys_get_temp_dir()),
        ));
        $api = new PluginApi('git', $manager, $project->dir, $settings);
        $manager->commit($api);

        return new self($api, $project, $tui, $status, $editor, $events, $buffers, $marks, $statuses);
    }
}
