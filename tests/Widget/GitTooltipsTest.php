<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\RendersWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the git view's cells say under the pointer: git's shorthand spelled
 * out, a control's reason for refusing, and whatever a row had to cut.
 */
final class GitTooltipsTest extends TestCase
{
    use RendersWidget;

    private function changes(): ChangesListWidget
    {
        $list = new ChangesListWidget(new Translator('en'));
        $list->setEntries([
            new ChangeEntry('src/a.php', 'M', ' '),
            new ChangeEntry('new.php', '?', '?'),
            new ChangeEntry('b.php', 'R', 'M', 'old/b.php'),
            new ChangeEntry('src/a/very/deep/directory/with/a/long/name/File.php', ' ', 'D'),
        ]);
        $list->render(new RenderContext(40, 10));

        return $list;
    }

    public function testAStatusLetterIsSpelledOutWithHowMuchIsStaged(): void
    {
        $list = $this->changes();
        $letter = 1 + 3 + 1; // the lead space, the box, a space

        $modified = $list->tooltipAt(1, $letter);
        $this->assertSame(['git:status:src/a.php', "Modified\nStaged", 1, $letter, $letter + 1], [$modified?->key, $modified?->text, $modified?->row, $modified?->colStart, $modified?->colEnd]);
        $this->assertSame("Untracked: new to git\nNot staged", $list->tooltipAt(2, $letter)?->text, 'untracked shows as A, and is not an addition');
        $this->assertSame("Modified\nPartly staged", $list->tooltipAt(3, $letter)?->text, 'the worktree side wins the letter');
        $this->assertSame("Deleted\nNot staged", $list->tooltipAt(4, $letter)?->text);
    }

    public function testTheBoxSaysWhatAClickDoesAndItsKey(): void
    {
        $list = $this->changes();

        $this->assertSame("Unstage this file\tSpace", $list->tooltipAt(1, 2)?->text);
        $this->assertSame("Stage this file\tSpace", $list->tooltipAt(2, 1)?->text);
        $this->assertSame('Stage everything', $list->tooltipAt(0, 2)?->text, "the header's box, over a mix");
    }

    public function testACutPathIsSaidWholeAndAWholeOneIsNot(): void
    {
        $list = $this->changes();

        $this->assertSame('src/a/very/deep/directory/with/a/long/name/File.php', $list->tooltipAt(4, 20)?->text);
        $this->assertNull($list->tooltipAt(1, 10), 'src/a.php is drawn whole');
        $this->assertNull($list->tooltipAt(8, 10), 'past the entries');
    }

    public function testEveryLetterGitCanShowHasWords(): void
    {
        $list = new ChangesListWidget(new Translator('en'));
        $list->setEntries([
            new ChangeEntry('a', 'A', ' '),
            new ChangeEntry('c', 'C', ' ', 'orig'),
            new ChangeEntry('r', 'R', ' ', 'was'),
            new ChangeEntry('u', 'U', 'U'),
            new ChangeEntry('t', ' ', 'T'),
            new ChangeEntry('x', ' ', 'X'),
        ]);
        $list->render(new RenderContext(40, 10));

        $texts = \array_map(static fn (int $row): ?string => $list->tooltipAt($row, 5)?->text, \range(1, end: 6));

        $this->assertSame(
            [
                "Added\nStaged",
                "Copied from orig\nStaged",
                "Renamed from was\nStaged",
                "Unmerged: a conflict to resolve\nPartly staged",
                "Type changed\nNot staged",
                null,
            ],
            $texts,
            'a letter git does not document here says nothing rather than something wrong',
        );
    }

    public function testEverythingStagedUnstagesEverything(): void
    {
        $list = new ChangesListWidget(new Translator('en'));
        $list->setEntries([new ChangeEntry('a.php', 'M', ' ')]);
        $list->render(new RenderContext(40, 5));

        $this->assertSame('Unstage everything', $list->tooltipAt(0, 1)?->text);
    }

    public function testAnEmptyListsHeaderSaysNothing(): void
    {
        $list = new ChangesListWidget(new Translator('en'));
        $list->render(new RenderContext(40, 5));

        $this->assertNull($list->tooltipAt(0, 1));
    }

    public function testTheGearSaysWhatTheDiffShows(): void
    {
        $diff = new DiffViewWidget(new Translator('en'));
        $diff->render(new RenderContext(80, 10));
        $anchor = $diff->settingsAnchor();
        $this->assertNotNull($anchor);

        $tip = $diff->tooltipAt(0, $anchor[0] + 1);
        $this->assertSame(
            ['git:diff:settings', 'Diff settings: Unified diff, whitespace changes shown', 0, 3],
            [$tip?->key, $tip?->text, $tip?->row, $tip?->rows],
            'any row of the boxed gear; the card spans all three',
        );
        $this->assertNull($diff->tooltipAt(1, 2), 'the title');
        $this->assertNull($diff->tooltipAt(5, $anchor[0] + 1), 'below the toolbar');

        $diff->useSplitView();
        $diff->hideWhitespace();
        $this->assertSame('Diff settings: Side-by-side diff, whitespace changes hidden', $diff->tooltipAt(1, $anchor[0] + 1)?->text);
    }

    public function testADisabledCommitButtonSaysWhy(): void
    {
        $box = new CommitBoxWidget(new Translator('en'));
        $staged = false;
        $box->setStagedChangesProvider(static function () use (&$staged): bool {
            return $staged;
        });
        $box->render(new RenderContext(40, 20));
        $row = $box->buttonRow();

        $tip = $box->tooltipAt($row, 5);
        $this->assertSame(['git:commit', 'Write a summary first', $row], [$tip?->key, $tip?->text, $tip?->row]);

        $box->setMessage('Fix it');
        $this->assertSame('Stage a change first', $box->tooltipAt($row, 5)?->text);

        $staged = true;
        $this->assertNull($box->tooltipAt($row, 5), 'an enabled button needs no card');
        $this->assertNull($box->tooltipAt(0, 5), 'nor anything but the button');
    }

    public function testAHistoryRowSaysItselfInFull(): void
    {
        $history = new HistoryListWidget(new Translator('en'));
        $history->setCommits([new LogEntry('abcdef1234567890', 'abcdef1', 'Alice', 1_700_000_000, 'A subject long enough to be cut by the pane')]);
        $history->render(new RenderContext(30, 14));

        $commit = $history->tooltipAt(1, 3);
        $this->assertSame("abcdef1234567890\nA subject long enough to be cut by the pane\nAlice · ".\date('Y-m-d H:i', timestamp: 1_700_000_000), $commit?->text);
        $this->assertSame([1, 0, 30], [$commit?->row, $commit?->colStart, $commit?->colEnd]);
    }

    /** A commit's file whose path was cut says the path; one drawn whole says nothing. */
    public function testACommitFileRowSaysACutPath(): void
    {
        $details = new CommitDetailsWidget(new Translator('en'));
        $details->setCommit(new LogEntry('abcdef1234567890', 'abcdef1', 'Alice', 1_700_000_000, 'Short'));
        $details->setFiles([['status' => 'M', 'path' => 'src/a/deep/path/that/will/not/fit/in/the/pane.php'], ['status' => 'A', 'path' => 'x.php']]);
        $rows = \explode("\n", $this->plain($details->render(new RenderContext(30, 14))));

        $cut = (int) \array_key_first(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'pane.php')));
        $this->assertSame('src/a/deep/path/that/will/not/fit/in/the/pane.php', $details->tooltipAt($cut, 3)?->text);
        $this->assertNull($details->tooltipAt($cut + 1, 3), 'x.php is drawn whole');
    }
}
