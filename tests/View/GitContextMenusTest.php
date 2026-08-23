<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Support\Clipboard;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TempProject;
use Porta\Ui\BidiTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The answers this source gives when it has nothing to offer.
 *
 * Every one of them used to be a branch in core: the context-menu router asked
 * the git router for a selected row and fell through when it got null, and the
 * opener compared focus against two git widgets and fell through when neither
 * matched. Moving the decision here moved the empty answers here too, and they
 * are the paths a screen test never walks — nobody writes an E2e for clicking
 * where there is nothing.
 */
final class GitContextMenusTest extends TestCase
{
    use RendersRoot;

    private ChangesListWidget $changes;
    private HistoryListWidget $history;
    private CodeEditorWidget $elsewhere;

    private function menus(): GitContextMenus
    {
        $translator = new Translator('en');
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 100, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $project = new TempProject();
        $git = new GitInfo($project->dir);
        $status = new StatusBarWidget();
        $this->changes = new ChangesListWidget($translator);
        $this->history = new HistoryListWidget($translator);
        $this->tui->add($this->changes);
        $this->tui->add($this->history);
        // A third focusable, so "focus is on neither list" is reachable — the
        // tui focuses the first widget added, so without this the changes list
        // always holds it.
        $this->tui->add($this->elsewhere = new CodeEditorWidget());

        return new GitContextMenus(
            new HitTester($this->renderer),
            GitDoubles::host($this->tui),
            $this->changes,
            $this->history,
            new GitCommands(
                $git,
                GitDoubles::modals(new CodeEditorWidget()),
                GitDoubles::flash(new Notifier($status, $this->tui)),
                $this->tui->requestRender(...),
                GitDoubles::translate($translator),
                new GitRefresher($git, static function (): void {}),
                new Clipboard($this->tui)->copy(...),
                new EventDispatcher(),
            ),
            $translator,
        );
    }

    public function testAPointOverNeitherListOffersNoMenu(): void
    {
        // Nothing laid out, so every hit-test misses. The caller reads null to
        // decide the workspace beneath should get the right-click instead.
        $this->assertNull($this->menus()->contextItemsAt(row: 5, col: 5));
    }

    public function testAPointOverAListWithNoRowThereOffersNoMenu(): void
    {
        // Laid out, empty: the pointer IS over the list, and there is still no
        // row under it. A menu anchored to nothing would act on nothing.
        $menus = $this->menus();
        $this->frame();

        $this->assertNull($menus->contextItemsAt(row: 2, col: 2), 'over the changes list');

        // And over the OTHER list, which is a different miss: the changes list
        // is asked first, so a point it rejects has still not reached history.
        $historyRect = $this->renderer->getWidgetRect($this->history);
        $this->assertNotNull($historyRect, 'the history pane was laid out');
        $this->assertNull($menus->contextItemsAt($historyRect->row + 1, $historyRect->col + 1), 'over the history list');
    }

    public function testFocusOnNeitherListMeansNoKeyboardMenu(): void
    {
        // Ctrl+F10 while the git view is open but focus is on something the
        // view does not own: it answers null and the opener flashes instead.
        $menus = $this->menus();
        $this->frame();
        $this->tui->setFocus($this->elsewhere);

        $this->assertNull($menus->focusedContextMenu());
    }

    public function testAFocusedListWithNothingSelectedOffersNoMenu(): void
    {
        // Both panes, because each has its own selected-row accessor and its
        // own anchor arithmetic — a null from one says nothing about the other.
        $menus = $this->menus();
        $this->frame();

        $this->tui->setFocus($this->changes);
        $this->assertNull($menus->focusedContextMenu(), 'changes');

        $this->tui->setFocus($this->history);
        $this->assertNull($menus->focusedContextMenu(), 'history');
    }

    // --- The positive path: not covered by the E2e (which only exercises the
    // History tab's commit menu) or the rest of this file (deliberately scoped
    // to the empty answers above) — changeItems()'s Stage/Unstage label swap
    // has no test anywhere else in the plugin.

    public function testChangeContextMenuLabelsStageOrUnstageByTheEntrysStagedState(): void
    {
        $menus = $this->menus();
        $this->changes->setEntries([
            new ChangeEntry('partial.txt', indexStatus: ' ', worktreeStatus: 'M'),
        ]);
        $this->frame();

        $items = $menus->contextItemsAt(row: 1, col: 2);
        $this->assertNotNull($items, 'the row is real once the list has an entry');
        $this->assertSame('Stage', $items[0]->label, 'not fully staged: the checkbox action is to stage it');
        $this->assertSame($this->changes, $this->tui->getFocus(), 'a right-click also focuses the list it hit');

        $this->changes->setEntries([
            new ChangeEntry('staged.txt', indexStatus: 'M', worktreeStatus: ' '),
        ]);
        $items = $menus->contextItemsAt(row: 1, col: 2);
        $this->assertSame('Unstage', $items[0]->label, 'fully staged: the checkbox action is to unstage it');
    }
}
