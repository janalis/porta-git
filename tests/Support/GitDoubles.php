<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\GitPlugin\Setting\DiffPrefs;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\I18n\Translator;
use Porta\Layout\DiffLayout;
use Porta\Layout\EditorAreaPanes;
use Porta\Overlay\State\ToastSeverity;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewHost;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The four closure-backed shims this plugin's classes take instead of the core
 * services they were injected with while this was `src/Git`.
 *
 * They exist because a plugin class can never be a container service, so
 * `Notifier`, `Translator`, `CodeEditorWidget` and `LayoutController` are all
 * out of reach; `plugin.php` builds each from `PluginApi`. A test wants the
 * same objects wired to something it can inspect, and hand-rolling them per
 * file is how four rigs drift apart.
 *
 * `flash()` is deliberately backed by a REAL `Notifier`: the flash text is the
 * only observable of half these verbs, and routing it anywhere else would have
 * meant rewriting every `StatusReads`/`Toasts` assertion that already reads it
 * off the status bar. `recordingFlash()` is for the few rigs that have no
 * status bar to read.
 */
final class GitDoubles
{
    /**
     * A flash backed by a real {@see Notifier}, so the toast host and the
     * status bar see exactly what they saw before the move — which is what
     * lets every `StatusReads`/`Toasts` assertion stand unchanged.
     */
    public static function flash(Notifier $notifier): Flash
    {
        return new Flash($notifier->notify(...));
    }

    /**
     * A flash that appends every message to $sink instead, for a test with no
     * status bar to read.
     *
     * @param list<string> $sink
     */
    public static function recordingFlash(array &$sink): Flash
    {
        return new Flash(static function (string $message, ToastSeverity $severity, float $seconds) use (&$sink): void {
            $sink[] = $message;
        });
    }

    /** Real translation, so a test asserting on user-facing text asserts on what ships. */
    public static function translate(Translator $translator): Translate
    {
        return new Translate($translator->trans(...));
    }

    /**
     * Modals backed by a real editor widget — the overlays it opens are the
     * ones the assertions read, exactly as they did before the move.
     */
    public static function modals(CodeEditorWidget $editor): Modals
    {
        return new Modals(
            $editor->openListOverlay(...),
            $editor->openPrompt(...),
            $editor->getFilePath(...),
        );
    }

    /**
     * Diff prefs over an in-memory array, standing in for `plugins.git.*`.
     *
     * @param array<string, bool|int|string> $store
     */
    public static function diffPrefs(array &$store): DiffPrefs
    {
        return new DiffPrefs(
            static fn (string $key): bool|int|string => $store[$key] ?? false,
            static function (string $key, bool $value) use (&$store): void {
                $store[$key] = $value;
            },
        );
    }

    /**
     * A minimal {@see ViewHost} over $tui.
     *
     * The view's collaborators take one instead of a `Tui` — every use was
     * `requestRender`, `setFocus` or `getFocus`, which is exactly its
     * vocabulary — so a test that used to hand over a `Tui` needs this. The
     * main area and the editor behind it are real but empty: a test of the
     * pointer or the context menus never swaps them.
     */
    public static function host(Tui $tui): ViewHost
    {
        return new ViewHost(
            new ContainerWidget(),
            new MenuBarWidget(),
            new class implements EditorAreaPanes {
                public function orderedEditorPanes(): array
                {
                    return [];
                }
            },
            new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $tui),
            $tui,
        );
    }

    /** What {@see diffPrefs()} reads back as, for a test that seeds one. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public static function splitLayout(bool $split): DiffLayout
    {
        return $split ? DiffLayout::Split : DiffLayout::Unified;
    }
}
