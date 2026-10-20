<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitToolbarMenuRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\RepositoryState;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\Tracking;
use Porta\GitPlugin\View\ToolbarCell;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\CompactScreen;
use Porta\Tests\Support\Probe;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * GitHub Desktop's toolbar: the repository, the branch with its pull request,
 * and the remote button, which names what it would do.
 */
final class GitToolbarWidgetTest extends TestCase
{
    private const int NOW = 1_760_000_000;

    /** @var list<object> */
    private array $dispatched = [];

    private function repository(?string $branch = 'main', ?Tracking $tracking = null, ?int $pullRequest = null, ?int $lastFetched = null, ?string $remote = null, int $worktrees = 1): RepositoryState
    {
        $repository = GitDoubles::repository(new GitInfo(\sys_get_temp_dir()), '/work/porta');
        Probe::set($repository, 'branch', $branch);
        Probe::set($repository, 'branchRead', true);
        Probe::set($repository, 'tracking', $tracking);
        Probe::set($repository, 'pullRequest', $pullRequest);
        Probe::set($repository, 'lastFetched', $lastFetched);
        Probe::set($repository, 'remote', $remote);
        Probe::set($repository, 'worktrees', $worktrees);
        Probe::set($repository, 'worktree', 'porta-review');

        return $repository;
    }

    /** The toolbar over panes this wide: History's sidebar, files and diff at 120 columns. */
    private function overPanes(RepositoryState $repository, ?ScreenDensity $density = null, int ...$panes): GitToolbarWidget
    {
        $toolbar = $this->toolbar($repository, $density);
        $toolbar->followPanes(static fn (int $width): array => [] === $panes ? [30, 35, $width - 67] : $panes);

        return $toolbar;
    }

    /**
     * The columns a glyph stands at in a plain row.
     *
     * @return list<int>
     */
    private static function columnsOf(string $glyph, string $line): array
    {
        return \array_keys(\mb_str_split($line), $glyph, strict: true);
    }

    private function toolbar(RepositoryState $repository, ?ScreenDensity $density = null): GitToolbarWidget
    {
        $events = new EventDispatcher();
        foreach ([GitRemoteRequested::class, GitToolbarMenuRequested::class] as $event) {
            $events->addListener($event, function (object $event): void {
                $this->dispatched[] = $event;
            });
        }

        return new GitToolbarWidget(new Translator('en'), $repository, Styles::dark(), $density ?? new ScreenDensity(), $events, static fn (): int => self::NOW);
    }

    /** @return list<string> */
    private static function plain(GitToolbarWidget $toolbar, int $width = 120): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $toolbar->render(new RenderContext($width, 3)));
    }

    public function testRoomyEachCellIsALabelOverItsValueRuledOffBelow(): void
    {
        $toolbar = $this->toolbar($this->repository('feature', new Tracking(0, 0), 446, self::NOW - 180));

        $lines = self::plain($toolbar);

        $this->assertCount(3, $lines);
        $this->assertSame(3, $toolbar->drawnRows());
        $this->assertMatchesRegularExpression('/^ ⌂ Current repository +▾ │ ⑂ Current branch +▾ │ ↻ Fetch +$/u', $lines[0]);
        $this->assertMatchesRegularExpression('/^ +porta +│ +feature +#446 │ +Last fetched 3 minutes ago +$/u', $lines[1]);
        $this->assertSame(self::columnsOf('│', $lines[0]), self::columnsOf('┴', $lines[2]), 'the rule joins each cell\'s divider');
        $this->assertCount(2, self::columnsOf('┴', $lines[2]), 'the last cell runs to the end of the bar');
        foreach ($lines as $line) {
            $this->assertSame(120, \mb_strwidth($line));
        }
    }

    public function testCompactTheValuesShareOneBarRow(): void
    {
        $toolbar = $this->toolbar($this->repository('feature', new Tracking(2, 1), 446, self::NOW - 7_200), CompactScreen::density());

        $lines = self::plain($toolbar);

        $this->assertCount(1, $lines);
        $this->assertSame(1, $toolbar->drawnRows());
        $this->assertMatchesRegularExpression('/^ ⌂ porta ▾ │ ⑂ feature #446 ▾ │ ↻ Pull ⇣1 ⇡2 · 2h +$/u', $lines[0]);
    }

    /** A narrow bar cuts a value, never the arrow that says its cell opens a menu. */
    public function testACompactCellGivesUpItsValueNotItsArrow(): void
    {
        $toolbar = $this->toolbar($this->repository('feature/a-very-long-branch-name-indeed', new Tracking(0, 0), 446), CompactScreen::density());

        $line = self::plain($toolbar, 60)[0];

        $this->assertStringContainsString('… #446 ▾ │', $line);
        $this->assertSame(60, \mb_strwidth($line));
    }

    /**
     * @return iterable<string, array{?string, ?Tracking, string, GitRemoteOperation}>
     */
    public static function remoteStates(): iterable
    {
        yield 'in step' => ['main', new Tracking(0, 0), 'Fetch', GitRemoteOperation::Fetch];
        yield 'behind' => ['main', new Tracking(0, 3), 'Pull', GitRemoteOperation::Pull];
        yield 'ahead' => ['main', new Tracking(2, 0), 'Push', GitRemoteOperation::Push];
        yield 'diverged: pull first' => ['main', new Tracking(2, 3), 'Pull', GitRemoteOperation::Pull];
        yield 'unpublished' => ['main', null, 'Publish branch', GitRemoteOperation::Push];
        yield 'detached' => [null, null, 'Fetch', GitRemoteOperation::Fetch];
    }

    #[DataProvider('remoteStates')]
    public function testTheRemoteButtonNamesAndRunsWhatThereIsToMove(?string $branch, ?Tracking $tracking, string $label, GitRemoteOperation $operation): void
    {
        $toolbar = $this->toolbar($this->repository($branch, $tracking));
        $line = self::plain($toolbar)[0];
        $this->assertStringContainsString('↻ '.$label.' ', $line);

        $span = $toolbar->span(ToolbarCell::Remote);
        $this->assertNotNull($span);
        $toolbar->handleClick($span[0] + 2);

        $this->assertEquals([new GitRemoteRequested($operation)], $this->dispatched);
    }

    public function testTheRepositoryAndBranchCellsAskForTheirMenus(): void
    {
        $toolbar = $this->toolbar($this->repository());
        self::plain($toolbar);

        foreach ([ToolbarCell::Repository, ToolbarCell::Branch] as $cell) {
            $span = $toolbar->span($cell);
            $this->assertNotNull($span);
            $toolbar->handleClick($span[1] - 1);
        }
        $toolbar->handleClick(\mb_strpos(self::plain($toolbar)[0], needle: '│')); // a divider

        $this->assertEquals([new GitToolbarMenuRequested(ToolbarCell::Repository), new GitToolbarMenuRequested(ToolbarCell::Branch)], $this->dispatched);
    }

    /** Before git answers, the branch is unknown — not claimed to be a detached HEAD. */
    public function testAnUnreadBranchSaysNothingYet(): void
    {
        $repository = $this->repository(null);
        Probe::set($repository, 'branchRead', false);

        $lines = self::plain($this->toolbar($repository));

        $this->assertStringNotContainsString('Detached HEAD', $lines[1]);
        $this->assertMatchesRegularExpression('/│ +│/u', $lines[1], 'the branch cell is blank');
    }

    public function testADetachedHeadAndANeverFetchedRepositorySaySo(): void
    {
        $lines = self::plain($this->toolbar($this->repository(null)));

        $this->assertStringContainsString('Detached HEAD', $lines[1]);
        $this->assertStringContainsString('Never fetched', $lines[1]);
    }

    public function testTheCellUnderThePointerTintsAndOnlyAChangeRepaints(): void
    {
        $toolbar = $this->toolbar($this->repository());
        $before = $toolbar->render(new RenderContext(120, 3));
        $span = $toolbar->span(ToolbarCell::Branch);
        $this->assertNotNull($span);

        $this->assertTrue($toolbar->hoverAt($span[0]));
        $this->assertFalse($toolbar->hoverAt($span[0] + 1), 'same cell: nothing to repaint');
        $this->assertNotSame($before, $toolbar->render(new RenderContext(120, 3)), 'the hovered cell is shaded');
        $this->assertTrue($toolbar->hoverAt(null));
        $this->assertSame($before, $toolbar->render(new RenderContext(120, 3)));
    }

    public function testEachCellExplainsItself(): void
    {
        $toolbar = $this->toolbar($this->repository('main', new Tracking(1, 2), 446, self::NOW - 60));
        self::plain($toolbar);
        $at = static function (ToolbarCell $cell) use ($toolbar): string {
            $span = $toolbar->span($cell);
            self::assertNotNull($span);
            $tip = $toolbar->tooltipAt(1, $span[0]);
            self::assertNotNull($tip);

            return $tip->text;
        };

        $this->assertSame('Repository porta — click for its worktrees', $at(ToolbarCell::Repository));
        $this->assertSame("Branch main — 1 ahead, 2 behind its upstream\nPull request #446\nClick to switch, create or delete a branch", $at(ToolbarCell::Branch));
        $this->assertSame("Pull the commits the upstream has and this branch does not\nLast fetched 1 minute ago", $at(ToolbarCell::Remote));
        $this->assertNull($toolbar->tooltipAt(1, (int) \mb_strpos(self::plain($toolbar)[1], needle: '│')), 'a divider says nothing');
        $this->assertNull($toolbar->tooltipAt(3, 1), 'nor does a row it did not draw');
    }

    /**
     * @return iterable<string, array{?string, ?Tracking, string}>
     */
    public static function remoteTips(): iterable
    {
        yield 'ahead' => ['main', new Tracking(1, 0), 'Push the commits this branch has and the upstream does not'];
        yield 'unpublished' => ['main', null, 'Publish this branch: push it and track it upstream'];
        yield 'in step' => ['main', new Tracking(0, 0), 'Fetch: ask the remote what changed, without touching this branch'];
    }

    #[DataProvider('remoteTips')]
    public function testTheRemoteTipSaysWhatAClickWouldDo(?string $branch, ?Tracking $tracking, string $tip): void
    {
        $toolbar = $this->toolbar($this->repository($branch, $tracking));
        self::plain($toolbar);
        $span = $toolbar->span(ToolbarCell::Remote);
        $this->assertNotNull($span);

        $this->assertSame($tip."\nNever fetched", $toolbar->tooltipAt(0, $span[0])?->text);
    }

    public function testABranchWithNoUpstreamSaysSoInItsTip(): void
    {
        $toolbar = $this->toolbar($this->repository('main'));
        self::plain($toolbar);
        $span = $toolbar->span(ToolbarCell::Branch);
        $this->assertNotNull($span);

        $this->assertSame("Branch main — no upstream branch\nClick to switch, create or delete a branch", $toolbar->tooltipAt(0, $span[0])?->text);
    }

    /** A tooltip's span is the cell's own columns, so the card centres on the cell. */
    public function testATipSpansItsCell(): void
    {
        $toolbar = $this->toolbar($this->repository('main', new Tracking(0, 0)));
        self::plain($toolbar);
        $span = $toolbar->span(ToolbarCell::Branch);
        $this->assertNotNull($span);

        $tip = $toolbar->tooltipAt(0, $span[0]);
        $this->assertSame([$span[0], $span[1]], [$tip?->colStart, $tip?->colEnd]);
    }

    /** Narrow, the remote button keeps saying what it would do and move; the time gives way. */
    public function testANarrowRemoteCellKeepsItsLabelOverItsTime(): void
    {
        $line = self::plain($this->toolbar($this->repository('feature', new Tracking(1, 2), lastFetched: self::NOW - 18_000), CompactScreen::density()), 45)[0];

        $this->assertStringContainsString('↻ Pull ⇣2 ⇡1 · ', $line);
        $this->assertStringNotContainsString('5h', $line);
    }

    /** Each cell stands over the pane under it, so its divider meets the pane's — the rule's `┴` lands on it. */
    public function testTheCellsStandOverThePanesUnderThem(): void
    {
        $lines = self::plain($this->overPanes($this->repository('main', new Tracking(1, 1), 446, self::NOW - 60, remote: 'origin')));

        $this->assertSame([30, 66], self::columnsOf('│', $lines[0]));
        $this->assertSame([30, 66], self::columnsOf('│', $lines[1]));
        $this->assertSame([30, 66], self::columnsOf('┴', $lines[2]));
        $this->assertStringStartsWith(' ⌂ Current repository', $lines[0]);
        $this->assertStringStartsWith('   porta', $lines[1], 'the value under its label\'s first letter');
        $this->assertSame(' ⑂ Current branch', \mb_substr($lines[0], start: 31, length: 17));
        $this->assertSame('   main', \mb_substr($lines[1], start: 31, length: 7));
        $this->assertSame(' ↻ Pull origin', \mb_substr($lines[0], start: 67, length: 14));
        $this->assertSame('   Last fetched', \mb_substr($lines[1], start: 67, length: 15));
    }

    /** Over Changes there is one pane beside the sidebar: the cells after the first share it. */
    public function testPastThePanesTheCellsShareTheRest(): void
    {
        $lines = self::plain($this->overPanes($this->repository(), null, 30, 89));

        $this->assertSame([30, 71], self::columnsOf('│', $lines[0]), 'the branch cell within its widest');
    }

    /** Too tight to stand over the panes, the cells share the bar instead. */
    public function testATightBarSharesItsWidthEvenly(): void
    {
        $lines = self::plain($this->overPanes($this->repository(), null, 30, 29), 60);

        $this->assertSame([19, 39], self::columnsOf('│', $lines[0]));
    }

    public function testCompactTheFirstCellStandsOverTheSidebar(): void
    {
        $line = self::plain($this->overPanes($this->repository('feature', new Tracking(0, 0)), CompactScreen::density()))[0];

        $this->assertSame(30, self::columnsOf('│', $line)[0]);
        $this->assertStringStartsWith(' ⌂ porta ▾ ', $line);
    }

    /** With worktrees beside the clone, one more cell names the open one and takes their menu from the repository's. */
    public function testWorktreesGetACellOfTheirOwn(): void
    {
        $toolbar = $this->overPanes($this->repository('main', new Tracking(0, 0), 446, remote: 'origin', worktrees: 2));
        $lines = self::plain($toolbar);

        $this->assertSame([30, 66, 93], self::columnsOf('┴', $lines[2]));
        $this->assertMatchesRegularExpression('/^ ⌂ Current repository +│ ◫ Current worktree +▾ │ ⑂ Current branch +▾ │ ↻ Fetch origin +$/u', $lines[0]);
        $this->assertSame('   porta-review', \mb_substr($lines[1], start: 31, length: 15));
        foreach ([ToolbarCell::Repository, ToolbarCell::Worktree] as $cell) {
            $span = $toolbar->span($cell);
            $this->assertNotNull($span);
            $toolbar->handleClick($span[0] + 1);
        }
        $this->assertEquals([new GitToolbarMenuRequested(ToolbarCell::Worktree)], $this->dispatched, 'the repository is a plain label now');
        $repository = $toolbar->span(ToolbarCell::Repository);
        $this->assertNotNull($repository);
        $this->assertFalse($toolbar->hoverAt($repository[0]), 'nor does it light up');
        $this->assertSame('Repository porta', $toolbar->tooltipAt(0, $repository[0])?->text);
        $this->assertSame('Worktree porta-review — click to switch or add one', $toolbar->tooltipAt(0, 31)?->text);
        $this->assertMatchesRegularExpression('/^ ⌂ porta +│ ◫ porta-review ▾ │/u', self::plain($this->overPanes($this->repository(worktrees: 2), CompactScreen::density()))[0]);
    }

    /** While an operation runs, the remote icon turns a quarter each step and the label says what it is doing. */
    public function testTheRemoteIconTurnsWhileAnOperationRuns(): void
    {
        $toolbar = $this->toolbar($this->repository('main', new Tracking(0, 1), remote: 'origin'));
        $frames = [];
        foreach ([1, 2, 3, 4, 5] as $ignored) {
            $toolbar->spin(GitRemoteOperation::Pull);
            $frames[] = \mb_substr(self::plain($toolbar)[0], start: (int) $toolbar->span(ToolbarCell::Remote)[0] + 1, length: 1);
        }
        $line = self::plain($toolbar)[0];

        $this->assertSame(['◜', '◝', '◞', '◟', '◜'], $frames);
        $this->assertStringContainsString('◜ Pulling origin… ', $line);
        $toolbar->settle();
        $this->assertStringContainsString('↻ Pull origin ', self::plain($toolbar)[0]);
        $toolbar->settle();
        $toolbar->spin(GitRemoteOperation::Fetch);
        $this->assertStringContainsString('◜ Fetching origin…', self::plain($toolbar)[0], 'a new run starts from the first quarter');
    }

    /**
     * @return iterable<string, array{GitRemoteOperation, string}>
     */
    public static function runningLabels(): iterable
    {
        yield 'fetch' => [GitRemoteOperation::Fetch, 'Fetching…'];
        yield 'pull' => [GitRemoteOperation::Pull, 'Pulling…'];
        yield 'push' => [GitRemoteOperation::Push, 'Pushing…'];
    }

    #[DataProvider('runningLabels')]
    public function testARunWithNoRemoteNamedSaysJustWhatItDoes(GitRemoteOperation $operation, string $label): void
    {
        $toolbar = $this->toolbar($this->repository(null));
        $toolbar->spin($operation);

        $this->assertStringContainsString('◜ '.$label, self::plain($toolbar)[0]);
    }

    /**
     * @return iterable<string, array{Tracking, string}>
     */
    public static function namedRemotes(): iterable
    {
        yield 'fetch' => [new Tracking(0, 0), 'Fetch origin'];
        yield 'pull' => [new Tracking(0, 1), 'Pull origin'];
        yield 'push' => [new Tracking(1, 0), 'Push origin'];
    }

    #[DataProvider('namedRemotes')]
    public function testTheButtonNamesTheRemote(Tracking $tracking, string $label): void
    {
        $this->assertStringContainsString('↻ '.$label.' ', self::plain($this->toolbar($this->repository('main', $tracking, remote: 'origin')))[0]);
    }
}
