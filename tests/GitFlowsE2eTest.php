<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Git flows through the full app: staging, commits, branches,
 * blame, stash, the git view and its focus ring.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class GitFlowsE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testRightClickingTheStatusBarBranchOpensTheBranchMenu(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // a real branch needs git
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $status = $tui->getById('status');
                    // The branch resolves asynchronously; wait for it to render.
                    $this->await(static fn (): bool => null !== self::clickableColumn($status), what: 'the branch to render in the status bar');

                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($status);
                    $col = $rect->col + (int) self::clickableColumn($status);
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 1, $rect->row + 1)); // right-click (1-based)
                    $this->frame($tui);

                    $menu = Services::resolve($ide, ContextMenu::class);
                    $this->assertTrue($menu->isOpen(), 'the branch menu opened');
                    $labels = \array_map(
                        static fn (array $row): string => \trim(AnsiUtils::stripAnsiCodes($row['segment'])),
                        $menu->segments($menu->panelWidth()),
                    );
                    $this->assertContains('Switch Branch…', $labels, 'with the branch actions');
                    $this->assertContains('New Branch…', $labels);

                    // A right-click elsewhere on the status bar (the legend area,
                    // outside the branch cell) offers no branch menu.
                    $tui->handleInput("\x1b"); // dismiss the menu
                    $this->frame($tui);
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + $rect->columns - 3, $rect->row + 1));
                    $this->frame($tui);
                    $this->assertFalse($menu->isOpen(), 'a right-click off the branch opens no menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitSidebarDragResizesAndPersists(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        // A seeded percent must be applied at startup (25% of 160 cols = 40).
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents($project->path('.porta/layout.json'), \json_encode(['gitSidebarWidthPercent' => 25]));

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => function ($tui): void {
                    $this->frame($tui);
                    $this->assertSame(40, $tui->getById('git-changes')->width(), 'the seeded percent applied at startup');
                    // Drag the git separator (SGR col 41 with a 40-wide sidebar)
                    // out to col 61: the whole left column follows.
                    $tui->handleInput("\x1b[<0;41;6M"); // arm on the separator
                    $tui->handleInput("\x1b[<32;61;6M"); // drag right
                    $tui->handleInput("\x1b[<0;61;6m"); // release
                },
                900 => function ($tui) use ($project): void {
                    $this->assertSame(60, $tui->getById('git-changes')->width(), 'the drag resized the sidebar');
                    $this->assertSame(60, $tui->getById('git-tabs')->width(), 'the tab strip follows');
                    $this->assertSame(60, $tui->getById('git-commit')->width(), 'the commit box follows');
                    $layout = \json_decode((string) \file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(38, $layout['viewSidebarWidthPercent']['git'], 'saved as a percentage (round(60/160))');
                    $this->assertArrayNotHasKey('gitSidebarWidthPercent', $layout, 'and the pre-move key is not rewritten');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCommitViaThePaletteAsksForAMessage(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git binary not available');
        }

        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t'); // 'tester' is asserted as the blame author
        $project->write('src/A.php', "<?php\n\$a = 1;\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged change

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
            foreach (\str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // `git_stage` is the git plugin's now, so the index is staged
                // with git directly — this test is about core's commit prompt.
                100 => static function ($tui) use ($project, $palette): void {
                    $project->git('add', '-A');
                    $palette($tui, label: 'Git: Commit'); // opens the message prompt
                },
                200 => function ($tui): void {
                    // Every keystroke below only means something while the
                    // prompt is up, and the palette opens it through a command
                    // rather than on the keypress — so the three gaps here were
                    // bets on a prompt, not waits for one.
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::promptOpen($editor),
                        what: 'the commit-message prompt to open',
                    );

                    $tui->handleInput("\r"); // empty message -> no-op, prompt closes
                },
                300 => function ($tui) use ($palette): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => !EditorState::promptOpen($editor),
                        what: 'the empty message to close the prompt',
                    );

                    $palette($tui, label: 'Git: Commit');
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->await(
                        static fn (): bool => EditorState::promptOpen($editor),
                        what: 'the commit-message prompt to reopen',
                    );

                    foreach (\str_split('my commit') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r"); // commit
                },
                500 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Committed: my commit'), what: 'the async commit to flash');
                    // Undoing it is `git_undo_commit`, which the git plugin owns
                    // now; its own suite covers that half.
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => ['my commit', 'init'] === \explode("\n", $project->git('log', '--format=%s')), what: 'the commit to land');
                },
            ],
        );

        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    /** @return list<\Porta\Workbench\Menu\Menu> */
    public function testAPickerRaisedOverTheGitViewIsVisibleAndLeavesTheViewOpen(): void
    {
        // Two defects in one shape: the pickers were owned by the editor
        // widget, which a full-screen view detaches. So the command palette —
        // which the Git menu offers as view-safe — painted nothing while the
        // git view was open, and every git verb that asks a question called
        // closeAll() first so its list would be somewhere visible.
        //
        // The palette half is the one nothing worked around, and nothing
        // noticed: it was simply dead from inside the view.
        $this->skipWithoutGit();
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d → the git view owns the main area
                },
                600 => function ($tui, $terminal, $ide): void {
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // From the container, not getById: the git view has DETACHED the
                    // editor and getById refuses a widget that is not mounted.
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->await(static fn (): bool => 'git-changes' === $tui->getFocus()?->getId(), what: 'the git view to take focus');

                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('ide_command_palette');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Find Action'), what: 'the palette to paint over the git view');

                    // Painting is only half of it — a visible picker that takes
                    // no keys is still broken, and the editor is not focused
                    // here, so this is the dispatcher's branch or nothing.
                    $tui->handleInput("\x1b");
                    $this->await(static fn (): bool => !$editor->isSearchOverlayOpen(), what: 'Esc to reach the palette and close it');

                    // And a git verb's own picker no longer costs the view.
                    $terminal->clearOutput();
                    Services::resolve($ide, CommandRegistry::class)->run('git_branches');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Create new branch'), what: 'the branch picker to paint');
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'the git view kept focus and the main area');

                    $tui->handleInput("\x1b");
                    $this->await(static fn (): bool => !EditorState::pickerOpen($editor), what: 'Esc to close the branch picker');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The first status column offering a context menu, or null.
     *
     * The widget used to expose the branch's span directly; it reports a menu
     * PER COLUMN now, because the status bar no longer knows which of its cells
     * is a branch. Scanning is how a caller finds one without being told.
     */
    private static function clickableColumn(object $status): ?int
    {
        for ($col = 0; $col < 120; ++$col) {
            if (null !== $status->menuAt($col)) {
                return $col;
            }
        }

        return null;
    }

    private function menuTitles($tui): array
    {
        return \array_column(Probe::get($tui->getById('menu'), 'menus'), 'title');
    }

    public function testWithoutARepositoryTheGitChromeIsAbsent(): void
    {
        // These verbs used to register everywhere and flash "Git not available"
        // when run. Outside a repository they now do not exist: not in the
        // palette, not on a key, and their menu group is not built — which is
        // also why the menu bar here is one shorter than in a repo.
        $project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']); // not a git repo

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $labels = \array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label');
                    // 'Git: Changes' is ide_git, which this plugin ADOPTS. It
                    // was core's until the view became a plugin's, and the flip
                    // is what makes it absent here rather than present and dead.
                    foreach (['Git: Commit…', 'Git: Branches…', 'Git: Stashes…', 'Git: File History', 'Git: Changes'] as $gone) { // blame is the git plugin's; its own suite covers it
                        $this->assertNotContains($gone, $labels, $gone.' is not offered outside a repository');
                    }
                    $this->assertNotContains('Git', $this->menuTitles($tui), 'and the Git menu is not built');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testInsideARepositoryTheGitVerbsRegisterLast(): void
    {
        // The other half of the palette-order contract: registration order IS
        // display order, and every plugin commits as one block. Asserted
        // here because it needs a repository; IdeHelpersTest owns the rest.
        $this->skipWithoutGit();
        $project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $labels = \array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label');
                    // A contiguous tail, not one pinned label: git is a plugin
                    // now, and plugins commit after every core provider has
                    // run — so what is contractual is that no core verb lands
                    // after a git one, not the order within the group.
                    // One unbroken block, wherever it sits. It used to be the
                    // palette's TAIL, because GitCommandProvider carried the
                    // lowest core priority; git is a plugin now and plugins
                    // commit before core's command providers run, so the group
                    // moved as a whole. What a user can still rely on is that
                    // it IS a whole — typing "git" walks a contiguous run.
                    $git = \array_keys(\array_filter($labels, static fn (string $l): bool => \str_starts_with($l, 'Git: ')));
                    self::assertNotSame([], $git);
                    $this->assertSame(\range($git[0], $git[\array_key_last($git)]), $git, 'the git verbs are one unbroken block');
                    $this->assertContains('Git: File History', $labels, 'the view-only verbs are among them');
                    $this->assertContains('Git: Blame Current Line', $labels, 'and so are the ones that never needed the workbench');
                    $this->assertContains('Git', $this->menuTitles($tui), 'and its menu is built');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitBranchPickerSwitchesAndCreatesBranches(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        $project->git('branch', 'feature');
        // A local bare remote with a branch no local shadows: the picker must
        // list it with a "remote" hint and check it out as a tracking branch.
        $bare = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $bare->git('init', '-q', '--bare');
        $project->git('remote', 'add', 'origin', $bare->dir);
        $project->git('push', '-q', 'origin', 'HEAD:remote-only');
        $project->git('fetch', '-q', 'origin');

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (\str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal, $ide) use ($project, $palette): void {
                    $editor = $tui->getById('editor');
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

                    // Round 1: switch to "feature". The picker's list is built
                    // inside a git callback — gate every navigation on the
                    // overlay actually being open, and prove each action by
                    // its ON-DISK effect (a toast outlives the step that
                    // flashed it, so it proves nothing about THIS action).
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the branch picker to open');
                    $this->frame($tui);
                    $this->assertStringContainsString('+ Create new branch…', $screen());
                    $this->assertStringContainsString('− Delete branch…', $screen());
                    $this->assertStringContainsString('feature', $screen());
                    $this->assertStringContainsString('remote-only', $screen());
                    // Rows: create + delete sentinels, then feature, then the
                    // current branch (ties sort by refname), then the remote
                    // row — two steps down picks "feature".
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => 'feature' === $project->git('rev-parse', '--abbrev-ref', 'HEAD'), what: 'the checkout to land on disk');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Switched to feature'), what: 'the switch flash');

                    // Round 2: the create sentinel with an empty name is a no-op.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the picker to reopen');
                    $tui->handleInput("\r"); // the "+ Create new branch…" row leads the list
                    $this->await(static fn (): bool => EditorState::promptOpen($editor), what: 'the branch-name prompt');
                    $tui->handleInput("\r"); // an empty name is a no-op
                    $branches = \array_filter(\explode("\n", $project->git('branch', '--list')));
                    $this->assertCount(2, $branches, 'no branch was created from the empty prompt');

                    // Round 3: create "fresh" for real.
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the picker for the create round');
                    $tui->handleInput("\r"); // create sentinel again
                    $this->await(static fn (): bool => EditorState::promptOpen($editor), what: 'the name prompt');
                    foreach (\str_split('fresh') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => 'fresh' === $project->git('rev-parse', '--abbrev-ref', 'HEAD'), what: 'the created branch to be checked out');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Created and switched to fresh'), what: 'the create flash');

                    // Round 4: check out the remote branch as a tracking branch.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the picker for the tracking round');
                    // Rows: 2 sentinels + 3 locals (feature/fresh/main-or-master,
                    // fresh current) + the remote row last — five steps down.
                    foreach (\range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r");
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => 'origin/remote-only' === $project->git('rev-parse', '--abbrev-ref', 'remote-only@{upstream}'), what: 'the tracking branch to exist');
                    $this->assertStringContainsString('Switched to remote-only (tracking origin/remote-only)', \Porta\Tests\Support\Toasts::last($ide));

                    // Round 5: the delete picker, cancelled. The gates read
                    // the CURRENT grid (screenGrid forces a full frame) — the
                    // accumulated output still shows the branches picker's own
                    // '− Delete branch…' sentinel, which would satisfy a
                    // substring await before the delete picker ever opened.
                    $grid = fn (): string => \implode("\n", $this->screenGrid($tui, $terminal));
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the picker for the delete round');
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains($grid(), 'Delete branch') && !\str_contains($grid(), '+ Create new branch…'), what: 'the delete picker');
                    $tui->handleInput("\r"); // "feature" leads (refname tie-break)
                    $this->await(static fn (): bool => \str_contains($grid(), 'Cancel'), what: 'the confirm list');
                    $tui->handleInput("\x1b[B"); // down to "Cancel"
                    $tui->handleInput("\r");
                    $kept = \array_filter(\explode("\n", $project->git('branch', '--list', 'feature')));
                    $this->assertCount(1, $kept, 'cancelling the confirm deletes nothing');

                    // Round 6: same flow, but confirm the delete this time.
                    $palette($tui, label: 'Git: Branches');
                    $this->await(static fn (): bool => \str_contains($grid(), '+ Create new branch…'), what: 'the picker for the confirmed delete');
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => \str_contains($grid(), 'Delete branch') && !\str_contains($grid(), '+ Create new branch…'), what: 'the delete picker again');
                    $tui->handleInput("\r"); // "feature" again
                    $this->await(static fn (): bool => \str_contains($grid(), 'Cancel'), what: 'the confirm list again');
                    $tui->handleInput("\r"); // the "Delete" confirm row leads
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => [] === \array_filter(\explode("\n", $project->git('branch', '--list', 'feature'))), what: 'the branch to be deleted on disk');
                    $this->await(static fn (): bool => \str_contains($grid(), 'Deleted branch feature'), what: 'the delete flash');
                },
            ],
        );

        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
        $bare->path('.'); // hold the bare remote alive through the run
    }

    public function testDeleteBranchWithOnlyOneLocalBranchFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u");
                    foreach (\str_split('Git: Branches') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                700 => function ($tui): void {
                    $this->frame($tui);
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'No other local branches to delete');
                    $this->assertStringContainsString('No other local branches to delete', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testTheBranchResolvesIntoTheStatusBar(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git binary not available');
        }
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // git must be reachable

        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');
        // Modify line 2 and append a line after committing
        $project->write('src/A.php', "<?php\n\$a = 999;\n\$b = 2;\n\$c = 3;\n");

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                500 => function ($tui, $terminal): void {
                    // Branch + gutter marks resolve through two async git
                    // round trips; await each, never a fixed gap.
                    $status = $tui->getById('status');
                    $this->await(static fn (): bool => null !== self::clickableColumn($status), what: 'the branch to resolve in the status bar');
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // Branch name in the status bar (default branch: main or master)
                    $this->assertMatchesRegularExpression('/\b(main|master)\b/', $screen);
                    // The gutter MARKS are the git plugin's now and are
                    // asserted in its own suite; the width contract the gutter
                    // column imposes is still core's.
                    $editor = $tui->getById('editor');
                    foreach ($editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 10)) as $line) {
                        $this->assertSame(80, AnsiUtils::visibleWidth($line));
                    }
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitViewStagesCommitsDiscardsAndRestores(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged edit
        $project->write('notes.md', "hi\n"); // untracked

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function ($tui, $terminal, $ide) use ($project): void {
                    // The view fills through status -> chained diff; await the
                    // rendered tail, never a fixed gap.
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($screen(), '+$a = 2;'), what: 'the diff pane to show the worktree change');
                    $this->assertStringContainsString('2 changed files', $screen());
                    $this->assertStringContainsString('[ ] M src/A.php', $screen());
                    $tui->handleInput(' '); // stage src/A.php
                    // Await the app's git barrier, then the on-disk effect —
                    // a blocking porcelain poll would race the async add.
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => \in_array('M  src/A.php', \explode("\n", $project->git('status', '--porcelain')), strict: true), what: 'space to stage the file');
                    // Tab twice: changes -> diff -> commit box; type + ctrl+enter.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (\str_split('Update A') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;5u");
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => ['Update A', 'init'] === \explode("\n", $project->git('log', '--format=%s')), what: 'ctrl+enter to commit the staged change');
                    // Discard the untracked notes.md: Tab walks summary -> description
                    // -> next pane (the changes list), then arm + confirm.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    $tui->handleInput('d');
                    $tui->handleInput('y');
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => !\is_file($project->path('notes.md')), what: 'discard to delete the untracked file');
                    // Await the view's own refresh BEFORE Esc leaves it — the
                    // clean-tree placeholder only paints while it is open.
                    $this->await(static fn (): bool => \str_contains($screen(), 'Committed:'), what: 'the commit flash');
                    $this->await(static fn (): bool => \str_contains($screen(), 'Working tree clean'), what: 'the clean-tree summary after commit+discard');
                    $tui->handleInput("\x1b"); // esc restores the editor view
                },
                700 => function ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertNotNull($tui->getById('tree'), 'tree pane restored');
                },
            ],
        );

        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testGitChangesRowContextMenuStagesAndArmsDiscard(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged edit

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '[ ] M src/A.php'));
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = $renderer->getWidgetRect($tui->getById('git-changes'));

                    $terminal->clearOutput();
                    // Focus somewhere else first, or this proves nothing: the
                    // view opens with the changes list already focused, so a
                    // right-press that never moved focus would look identical.
                    $tui->setFocus($tui->getById('git-diff'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 8, $rect->row + 2)); // right-click the first row
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a changes row opened its menu');
                    // Asserted HERE, not after the menu action: selecting the row
                    // under the pointer and taking focus is part of answering,
                    // and it is what makes the Ctrl+F10 parity below act on this
                    // row rather than on whatever had focus before.
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'the right-press focused the list it selected in');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'File History'));
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Stage', $screen, 'an unstaged row offers Stage');
                    $this->assertStringContainsString('File History', $screen);

                    $tui->handleInput("\r"); // Stage leads the list
                    // Await the app's git barrier (which pumps the loop) rather than
                    // polling a blocking `git status` — under load the blocking poll
                    // starves the async `git add` it is waiting on.
                    $this->awaitGit($ide);
                    $this->assertContains('M  src/A.php', \explode("\n", $project->git('status', '--porcelain')), 'the row staged');
                },
                1000 => function ($tui, $terminal, $ide): void {
                    $this->frame($tui);
                    // Keyboard parity: Ctrl+F10 on the (focused) changes list —
                    // a staged row offers Unstage; Discard… arms the confirm bar.
                    $this->assertSame('git-changes', $tui->getFocus()?->getId());
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'Ctrl+F10 opened the row menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Unstage'));
                    $this->assertStringContainsString('Unstage', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'a staged row offers Unstage');

                    $tui->handleInput("\x1b[B"); // → Discard…
                    $tui->handleInput("\r");
                    $this->frame($tui);
                    $this->assertStringContainsString('Discard changes?', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Discard… armed the two-press confirmation');
                    $tui->handleInput('n'); // decline
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitViewStagesASingleLineFromTheDiffPane(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['f.txt' => "alpha\nbeta\ngamma\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        $project->write('f.txt', "alpha CHANGED\nbeta\ngamma CHANGED\n");

        $terminal = $this->runIde(
            $project,
            $project->path('f.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => function ($tui, $terminal, $ide) use ($project): void {
                    // The diff pane fills through status -> chained diff; the
                    // arrows must not walk rows that aren't there yet.
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($screen(), '+alpha CHANGED'), what: 'the diff rows to load');
                    $tui->handleInput("\t"); // changes list -> diff pane
                    // Rows: hunk header, ctx?, -alpha, +alpha CHANGED, ... walk to
                    // the first '+' row and stage just it.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput(' ');
                    // The staging is an async git apply --cached: barrier, then
                    // prove the index state on disk.
                    $this->awaitGit($ide);
                    $this->await(static fn (): bool => \str_contains($project->git('diff', '--cached'), 'alpha'), what: 'the alpha change to reach the index');
                    $joinedCached = $project->git('diff', '--cached');
                    $this->assertStringNotContainsString('gamma CHANGED', $joinedCached, 'gamma stayed out of the index');
                    $this->assertStringContainsString('gamma CHANGED', $project->git('diff'), 'gamma remains a worktree change');
                    // The post-apply refresh repaints the mixed checkbox; await
                    // it here instead of scraping after the loop stopped.
                    $this->await(static fn (): bool => \str_contains($screen(), '[~] M f.txt'), what: 'partial staging to show the mixed checkbox');
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testGitViewMouseAndMenuGuard(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\ntwo\nthree\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('init');
        $project->write('a.txt', "one!\ntwo\nthree changed\n");
        $project->write('b.txt', 'new '.\str_repeat('long-tail ', times: 8)."\n"); // long enough to h-scroll

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                150 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                250 => function ($tui): void {
                    $this->frame($tui);
                    // F10 opens the app menu OVER the git view now.
                    $tui->handleInput("\x1b[21~");
                },
                350 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Save File', $screen, 'the File dropdown composites over the git panes');
                    $this->assertStringContainsString('Quit', $screen);
                    // Hover a dropdown row (the frame's top border starts under
                    // the menu row, items from SGR row 3), then click the greyed
                    // 'Save File' item: disabled in the git view, so it only
                    // closes the menu.
                    $tui->handleInput("\x1b[<35;5;5M");
                    $tui->handleInput("\x1b[<0;5;3M");
                    $tui->handleInput("\x1b[<0;5;3m");
                },
                450 => function ($tui, $terminal, $ide): void {
                    $this->frame($tui);
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'closing the menu restores the git focus target');
                    // The row clicked below only exists once `git status` has
                    // answered and the list has painted it.
                    $this->awaitGit($ide);
                    $this->awaitPainted($tui, $terminal, 'a.txt');
                    // Click the 'File' title (row 1) to open, then again to close.
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    // Below the menu row sits the tab strip (row 2), then the
                    // changes header (row 3); a.txt is row 4. Click the checkbox.
                    $tui->handleInput("\x1b[<0;3;4M");
                    $tui->handleInput("\x1b[<0;3;4m");
                },
                550 => function ($tui, $terminal) use ($project): void {
                    // Staging is a git subprocess, so this waits — but on the
                    // VIEW's reading of it rather than by shelling out to
                    // `git status` every 20 ms, which is what testing.md means
                    // by "never let a blocking $project->git() read race the
                    // async op". Await the checkbox, then read the disk once.
                    $this->await(
                        static fn (): bool => \str_contains(
                            AnsiUtils::stripAnsiCodes($tui->getById('git-changes')->render(new RenderContext(44, 4))[1] ?? ''),
                            '[x]',
                        ),
                        what: 'the changes list to show a.txt staged',
                    );
                    $this->assertContains('M  a.txt', \explode("\n", $project->git('status', '--porcelain')), 'checkbox click staged the file');
                    // Hover row 5 (b.txt): a tint only — the diff must NOT load.
                    $tui->handleInput("\x1b[<35;10;5M");
                },
                650 => function ($tui, $terminal): void {
                    // The POSITIVE first: a.txt's diff on screen is what makes
                    // "+new is absent" mean the hover declined to load, rather
                    // than that nothing has happened yet.
                    $this->awaitPainted($tui, $terminal, 'three changed');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringNotContainsString('+new', $screen, 'hover alone must not load the hovered diff');
                    // Moving the pointer onto the diff pane clears the tint...
                    $tui->handleInput("\x1b[<35;80;6M");
                    // ...and a CLICK on b.txt selects it and loads its diff.
                    $tui->handleInput("\x1b[<0;10;5M");
                    $tui->handleInput("\x1b[<0;10;5m");
                },
                750 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, '+new');
                    $this->assertStringContainsString('+new', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the click loaded the untracked diff');
                    // Horizontal wheel (trackpad code 67) over the diff pane
                    // scrolls sideways; the tree/list panes ignore it.
                    $tui->handleInput("\x1b[<67;80;6M");
                    $this->assertSame(6, $tui->getById('git-diff')->hScroll(), 'wheel-right scrolled the diff text');
                    $tui->handleInput("\x1b[<68;80;6M"); // shift+wheel-up = left
                    $this->assertSame(0, $tui->getById('git-diff')->hScroll());
                    $tui->handleInput("\x1b[<67;10;4M"); // over the changes list: ignored
                    // Wheel over the diff pane scrolls it; over the list it moves
                    // the selection (keyboard-like, so it may load).
                    $tui->handleInput("\x1b[<65;80;6M");
                    $tui->handleInput("\x1b[<65;10;4M");
                    // Press mid-text on a diff row and drag ten columns right:
                    // a char-level span arms; ctrl+c copies exactly those chars.
                    $tui->handleInput("\x1b[<0;60;6M");
                    $tui->handleInput("\x1b[<32;70;6M");
                    $tui->handleInput("\x1b[<0;70;6m");
                    $tui->handleInput("\x03");
                    // Click into the commit box summary row.
                    $tui->handleInput("\x1b[<0;5;20M");
                    $tui->handleInput("\x1b[<0;5;20m");
                },
                850 => function ($tui, $terminal): void {
                    $raw = $terminal->getOutput();
                    // Text col 2..12 of '+new long-tail …' (pane text starts at
                    // SGR col 58): the exact ten chars, no markers, no newline.
                    $this->assertStringContainsString("\x1b]52;c;".\base64_encode('w long-tai'), $raw, 'the dragged char span reached the clipboard');
                    $this->assertStringContainsString('Copied 1 line', AnsiUtils::stripAnsiCodes($raw));
                    $this->assertSame('git-commit', $tui->getFocus()?->getId(), 'click focused the commit box');
                    // The diff toolbar tags sit at the right edge of the diff
                    // title row (screen row 2): ' S split ' then ' W hide-ws '.
                    // Clicking them flips the modes and persists the prefs.
                    $tui->handleInput("\x1b[<0;141;2M");
                    $tui->handleInput("\x1b[<0;141;2m");
                    $this->assertTrue($tui->getById('git-diff')->isSplit(), 'the split tag switched to side-by-side');
                    $tui->handleInput("\x1b[<0;151;2M");
                    $tui->handleInput("\x1b[<0;151;2m");
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('b.txt', $screen);
        // The two diff toggles are plugin SETTINGS now, not layout prefs:
        // neither is a size, and they were in `.porta/layout.json` only
        // because the git view had no store of its own.
        $porta = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);
        $this->assertTrue($porta['plugins']['git']['diffSplit'], 'the split preference persisted');
        $this->assertTrue($porta['plugins']['git']['diffHideWhitespace'], 'the whitespace preference persisted');
    }

    public function testGitViewHistoryTabBrowsesCommits(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit();
        $project->commitAll('first commit');
        $project->write('a.txt', "one\nmore\n");
        $project->commitAll('second commit');

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                200 => function ($tui): void {
                    $this->frame($tui);
                    // Click the [History] tab cell (tab strip = screen row 2).
                    $tui->handleInput("\x1b[<0;14;2M");
                    $tui->handleInput("\x1b[<0;14;2m");
                },
                300 => function ($tui, $terminal): void {
                    // TWO subprocesses, not one: `git log` fills the history
                    // list, and selecting its top commit then loads that
                    // commit's file list. Awaiting the first (History (2))
                    // left the second racing, which failed 2 runs in 30 under
                    // load — so await the LAST effect this step asserts on.
                    $this->awaitPainted($tui, $terminal, 'M a.txt');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('second commit', $screen);
                    $this->assertStringContainsString('M a.txt', $screen, 'the newest commit file list loads');
                    // Mouse: hover tints a commit row (no selection change),
                    // wheel over the history list, click the first commit
                    // row (menu row + tab strip shift the list down two).
                    $tui->handleInput("\x1b[<35;10;5M");
                    $tui->handleInput("\x1b[<65;10;5M");
                    $tui->handleInput("\x1b[<0;10;4M");
                    $tui->handleInput("\x1b[<0;10;4m");
                },
                400 => function ($tui, $terminal): void {
                    // The clicked commit's files settled AND its first file's
                    // patch auto-loaded — no keystroke needed to see a diff.
                    $this->awaitPainted($tui, $terminal, '+more');
                    $tui->handleInput("\r"); // into the files list
                    $tui->handleInput("\x1b[B"); // wiggle selection to refire the load
                    $tui->handleInput("\x1b[A");
                },
                500 => function ($tui, $terminal): void {
                    $this->assertStringContainsString('+more', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the commit patch renders read-only');
                    // Click the [Changes] tab to switch back, and await the
                    // paint the post-run assertion reads — the loop stops 80 ms
                    // after this step, which was the only thing making it true.
                    $tui->handleInput("\x1b[<0;4;2M");
                    $tui->handleInput("\x1b[<0;4;2m");
                    $this->awaitPainted($tui, $terminal, 'Working tree clean');
                },
            ],
        );

        $this->assertStringContainsString('Working tree clean', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the Changes tab restored');
    }

    public function testF6InsideTheGitViewWalksItsPanes(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git binary not available');
        }
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // git must be reachable

        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('a.php', "<?php\n\$a = 1;\n");

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => function ($tui, $terminal, $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $views = Services::resolve($ide, ViewRegistry::class);
                    // The verb TOGGLES, and it has to go through the registry
                    // to do it: calling the view's own toggle() would skip the
                    // mutual exclusion that closes whatever else owns the main
                    // area. Both directions here, because the second is the one
                    // a wrong `openView` would silently break.
                    $commands->run('ide_git');
                    $this->assertTrue($views->isOpen('git'), 'the verb opened it');
                    $commands->run('ide_git');
                    $this->assertFalse($views->isOpen('git'), 'and the same verb closed it again');

                    $commands->run('ide_git');
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'git view opens on the changes list');
                    $tui->handleInput("\x1b[17~"); // F6 delegates to the git view's pane order
                    $this->assertSame('git-diff', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // Ctrl+F6 walks back
                    $this->assertSame('git-changes', $tui->getFocus()?->getId());

                    // Pointer motion over the git mode tabs tints the inactive one.
                    $this->frame($tui);
                    $renderer = Services::resolve($ide, Renderer::class);
                    $tabsRect = $renderer->getWidgetRect($tui->getById('git-tabs'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $tabsRect->col + 12, $tabsRect->row + 1)); // over 'History'
                    $this->frame($tui);
                    $this->assertStringContainsString(
                        "\x1b[48;5;240m",
                        \implode('', $tui->getById('git-tabs')->render(new \Symfony\Component\Tui\Render\RenderContext(44, 1))),
                        'the hovered git tab tints',
                    );
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $tabsRect->col + 2, $tabsRect->row + 3)); // off the strip
                    $this->frame($tui);
                    $this->assertStringNotContainsString(
                        "\x1b[48;5;240m",
                        \implode('', $tui->getById('git-tabs')->render(new \Symfony\Component\Tui\Render\RenderContext(44, 1))),
                        'leaving the strip clears the tint',
                    );

                    // A strip click focuses the pane it reveals — the git tabs
                    // used to leave the focus wherever it happened to be.
                    $tui->setFocus($tui->getById('git-diff'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $tabsRect->col + 12, $tabsRect->row + 1)); // click 'History'
                    $this->frame($tui);
                    $this->assertSame('git-history', $tui->getFocus()?->getId(), 'clicking the History tab brings you to the history list');

                    $tui->setFocus($tui->getById('git-diff'));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $tabsRect->col + 2, $tabsRect->row + 1)); // click 'Changes'
                    $this->frame($tui);
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'and back to the changes list');
                },
            ],
        );
    }

    public function testFileHistoryOpensTheViewFilteredToTheOpenFile(): void
    {
        // Its own fixture and its own session. It used to ride the tail of the
        // stash test, where the only thing pumping the loop between the last
        // stash await and this assertion was an unrelated blame await — so
        // removing blame broke it, which means it was never really awaiting the
        // history load at all.
        $this->skipWithoutGit();
        $project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');
        $project->write('other.txt', '');
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'other');

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (\str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, $ide) use ($palette): void {
                    $this->awaitGit($ide);
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: File History');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'History — src/A.php (1)'), timeout: 8.0, what: 'the file-history view');
                    $screenText = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('init', $screenText);
                    $this->assertStringNotContainsString(' other ', $screenText, "the commit that didn't touch the file is hidden");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
