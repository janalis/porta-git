<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\DiffStat;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\Tracking;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\GitPlugin\Widget\SplitShape;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\CompactScreen;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Ui\ScreenDensity;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * No git widget may render a row wider than the pane it was given.
 *
 * The plugin's half of {@see \Porta\Tests\Unit\Widget\NarrowPaneRenderTest},
 * and it was missing entirely: that sweep is a core test over core widgets, so
 * every widget this plugin mounts — the whole git view — had never been through
 * it. The crash it guards against is not hypothetical. `CodeEditorWidget` did
 * it twenty-one times in this repo's own `.porta/error.log`, and the first run
 * of this file found three more, all the same shape: a bound written with
 * `max(0, …)` or `max(1, …)` that clamps one PIECE of the row while the rest is
 * emitted at its natural width.
 *
 * - `HeaderRenderer::render()`'s key-hint suffix: the commit box's `Esc` came
 *   out four columns wide into a one-column pane.
 * - The diff pane's toolbar and row gutters — swept now by the diff
 *   plugin's own `NarrowDiffPaneRenderTest`, where the pane moved.
 *
 * A `RenderException` does not garble a line — it escapes the render loop and
 * takes the session with it.
 */
final class NarrowGitPaneRenderTest extends TestCase
{
    use RendersExactWidth;

    /**
     * Every widget the git view mounts, each given something to DRAW — the
     * sweep refuses a widget that renders nothing, because a loop over no rows
     * asserts nothing while reporting success.
     *
     * @return iterable<string, array{AbstractWidget}>
     */
    public static function gitWidgets(): iterable
    {
        $changes = new ChangesListWidget(new Translator('en'));
        $changes->setEntries([
            new ChangeEntry('src/a.php', ' ', 'M'),
            new ChangeEntry('renamed.php', 'R', ' ', 'old.php'),
        ]);
        yield 'changes list' => [$changes];

        $history = new HistoryListWidget(new Translator('en'));
        $history->setFocused(true);
        $history->setCommits([new LogEntry('full1', 'aaa111', 'Alice', 1_700_000_100, 'Newest work')]);
        yield 'history list' => [$history];

        $details = new CommitDetailsWidget(new Translator('en'));
        $details->setFocused(true);
        $details->setCommit(new LogEntry('full1', 'aaa111', 'Alice', 1_700_000_100, 'Newest work, with a subject long enough to wrap twice and be cut'));
        $details->setFiles([['status' => 'M', 'path' => 'src/deep/folder/a.php'], ['status' => 'R', 'path' => 'b.php']]);
        yield 'commit details' => [$details];

        $stacked = new CommitDetailsWidget(new Translator('en'));
        $stacked->stack(SplitShape::Stacked);
        $stacked->setCommit(new LogEntry('full1', 'aaa111', 'Alice', 1_700_000_100, 'Newest work'));
        $stacked->setFiles([['status' => 'M', 'path' => 'src/deep/folder/a.php']]);
        yield 'commit details (stacked)' => [$stacked];

        // Both shapes: the subject over the author, and one row; with a stat and the stacked legend.
        foreach (['commit header (roomy)' => new ScreenDensity(), 'commit header (compact)' => CompactScreen::density()] as $name => $density) {
            $header = new CommitHeaderWidget(new Translator('en'), density: $density);
            $header->setCommit(new LogEntry('full1', 'aaa111', 'Alice Withalongname', 1_700_000_100, 'Newest work, with a subject long enough to be cut'));
            $header->setStat(new DiffStat(1234, 56));
            $header->nameFiles(12);
            yield $name => [$header];
        }

        yield 'git tabs' => [new GitTabsWidget(new Translator('en'))];

        // Both shapes: three labelled cells over a rule, and one bar row.
        foreach (['toolbar (roomy)' => new ScreenDensity(), 'toolbar (compact)' => CompactScreen::density()] as $name => $density) {
            $repository = GitDoubles::repository(new GitInfo(\sys_get_temp_dir()), '/work/porta');
            Probe::set($repository, 'branch', 'feature/a-branch-name-long-enough-to-cut');
            Probe::set($repository, 'tracking', new Tracking(1, 2));
            Probe::set($repository, 'pullRequest', 446);
            Probe::set($repository, 'lastFetched', 1_700_000_000);
            yield $name => [new GitToolbarWidget(new Translator('en'), $repository, density: $density)];
        }

        // The commit box is the one that carries a key-hint suffix in its
        // header, which is the piece that overflowed.
        $box = new CommitBoxWidget(new Translator('en'));
        $box->setMessage('a commit message long enough to need the width');
        yield 'commit box' => [$box];
    }

    #[DataProvider('gitWidgets')]
    public function testAGitWidgetFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        AttachedStyles::attach($widget);

        self::assertFitsEveryWidth($widget);
    }

    /**
     * Every widget in this plugin is in the provider above.
     *
     * The same self-check the core sweep grew, for the same reason: that
     * provider is a hand-written list of "everything", and a hand-written list
     * of everything is stale the first time someone adds a widget. This plugin
     * is where three over-wide rows were found the day the sweep first ran, so
     * the next widget added here should not have to wait for someone to
     * remember.
     *
     * No exemptions, and none expected: every widget in this directory is
     * mounted in the git view and laid out by it.
     */
    public function testEveryGitWidgetIsInTheProvider(): void
    {
        $swept = [];
        foreach (self::gitWidgets() as [$widget]) {
            $swept[$widget::class] = true;
        }

        $missing = [];
        $found = \glob(\dirname(__DIR__, levels: 2).'/src/Widget/*.php');
        foreach (false === $found ? [] : $found as $file) {
            $class = 'Porta\\GitPlugin\\Widget\\'.\basename($file, suffix: '.php');
            if (\class_exists($class) && \is_a($class, AbstractWidget::class, allow_string: true) && !\array_key_exists($class, $swept)) {
                $missing[] = $class;
            }
        }
        \sort($missing);

        $this->assertSame([], $missing, 'the git view mounts these and nothing renders them at a hostile width');
    }
}
