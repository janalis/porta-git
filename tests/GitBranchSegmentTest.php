<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\GitBranchSegment;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The branch indicator as an ordinary status contributor.
 *
 * It was three classes' business before: StatusBarProvider built the cell from
 * GitRefresher and GitSettings, published a branchStart/branchEnd pair, and the
 * context-menu router paired that one span with the one git menu. The cell
 * carries its own menu now, so what is left to check is that the contributor
 * still answers the two questions those three used to answer between them —
 * whether to appear at all, and what right-clicking offers.
 */
final class GitBranchSegmentTest extends TestCase
{
    private function segment(bool $showBranch, ?string $branch, ?string $worktree = null): GitBranchSegment
    {
        $translator = new Translator('en');
        TestTui::create(80, 24);
        $project = new TempProject();
        $git = new GitInfo($project->dir);
        $refresher = new GitRefresher($git, static function (): void {});
        // The branch resolves asynchronously through git; set it directly so
        // the test is about the CELL rather than about a subprocess.
        Probe::set($refresher, 'branch', $branch);
        $flashes = [];
        $worktrees = GitDoubles::worktrees($git, $project->dir);
        $commands = new GitCommands(
            $git,
            GitDoubles::modals(new CodeEditorWidget()),
            GitDoubles::recordingFlash($flashes),
            static function (): void {},
            GitDoubles::translate($translator),
            $refresher,
            static function (string $text): void {},
            new EventDispatcher(),
            static fn (): array => [],
            static function (array $lines, string $title): void {},
            $worktrees,
        );

        return new GitBranchSegment($refresher, new GitSettings(['showBranch' => $showBranch]), $commands, GitDoubles::translate($translator), $worktrees, $worktree);
    }

    public function testTheSettingSuppressesTheCellEntirely(): void
    {
        // Not merely hidden: a suppressed cell contributes no span either, so
        // right-clicking where it would have been offers nothing.
        $this->assertNull($this->segment(showBranch: false, branch: 'main')->statusCell());
    }

    public function testNoResolvedBranchMeansNoCell(): void
    {
        // The branch resolves through an async git call; until it lands there
        // is nothing to show, which is the normal state for the first frames.
        $this->assertNull($this->segment(showBranch: true, branch: null)->statusCell());
    }

    public function testAResolvedBranchIsAnAccentCellCarryingItsOwnMenu(): void
    {
        $cell = $this->segment(showBranch: true, branch: 'trunk')->statusCell();
        $this->assertInstanceOf(StatusCell::class, $cell);

        $this->assertNotNull($cell);
        $this->assertStringContainsString('trunk', $cell->text);
        $this->assertSame(StatusTone::Accent, $cell->tone, 'picked out from the prefix, because it is clickable');
        $this->assertNotNull($cell->menu, 'and it carries what a right-click offers');
        $this->assertSame('Git branch trunk — click to switch, create or delete a branch', $cell->tooltip);
        $this->assertSame(
            ['Switch Branch…', 'New Branch…', 'Delete Branch…', 'Worktrees…', 'New Worktree…'],
            \array_map(static fn (object $item): string => $item->label, ($cell->menu)()),
        );
    }

    public function testInALinkedWorktreeTheCellSaysWhich(): void
    {
        // Two windows on two worktrees of one repository otherwise read as the
        // same project, each on its own branch.
        $cell = $this->segment(showBranch: true, branch: 'feat', worktree: 'ide-feat')->statusCell();

        $this->assertInstanceOf(StatusCell::class, $cell);
        $this->assertStringEndsWith('feat ❐ ide-feat', $cell->text);
        $this->assertSame('Git branch feat in worktree ide-feat — click to switch branch or worktree', $cell->tooltip);
    }

    public function testInTheMainWorktreeTheCellIsTheBranchAlone(): void
    {
        $cell = $this->segment(showBranch: true, branch: 'main')->statusCell();

        $this->assertInstanceOf(StatusCell::class, $cell);
        $this->assertStringNotContainsString('❐', $cell->text);
    }
}
