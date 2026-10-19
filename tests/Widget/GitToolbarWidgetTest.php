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

    private function repository(?string $branch = 'main', ?Tracking $tracking = null, ?int $pullRequest = null, ?int $lastFetched = null): RepositoryState
    {
        $repository = GitDoubles::repository(new GitInfo(\sys_get_temp_dir()), '/work/porta');
        Probe::set($repository, 'branch', $branch);
        Probe::set($repository, 'branchRead', true);
        Probe::set($repository, 'tracking', $tracking);
        Probe::set($repository, 'pullRequest', $pullRequest);
        Probe::set($repository, 'lastFetched', $lastFetched);

        return $repository;
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
        $this->assertMatchesRegularExpression('/⌂ Current repository +▾ │ ⑂ Current branch +▾ │ ⟳ Fetch +│/u', $lines[0]);
        $this->assertMatchesRegularExpression('/^ +porta +│ +feature +#446 │ +Last fetched 3 minutes ago +│/u', $lines[1]);
        $this->assertSame(3, \mb_substr_count($lines[2], needle: '┴'), 'the rule joins each cell\'s divider');
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
        $this->assertMatchesRegularExpression('/^ ⌂ porta ▾ │ ⑂ feature #446 ▾ │ ⟳ Pull ⇣1 ⇡2 · 2h │/u', $lines[0]);
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
        $this->assertStringContainsString('⟳ '.$label.' ', $line);

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
        $toolbar->handleClick(119); // the bar past the cells

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
        $this->assertNull($toolbar->tooltipAt(1, 119), 'the bar past the cells says nothing');
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
}
