<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\DiffStat;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\CompactScreen;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * GitHub Desktop's commit header: the subject, then `author · sha ⧉` with the
 * lines added and removed at the far end, ruled off from the columns below.
 */
final class CommitHeaderWidgetTest extends TestCase
{
    private const string SHA = 'f00dfacecafebeef0123456789abcdef01234567';

    /** @var list<string> */
    private array $copied = [];

    private function header(?ScreenDensity $density = null, string $subject = 'Record the parallel flake fixes'): CommitHeaderWidget
    {
        $events = new EventDispatcher();
        $events->addListener(GitCommitHashCopyRequested::class, function (GitCommitHashCopyRequested $event): void {
            $this->copied[] = $event->commit->sha;
        });
        $header = new CommitHeaderWidget(new Translator('en'), Styles::dark(), $density ?? new ScreenDensity(), $events);
        $header->setCommit(new LogEntry(self::SHA, 'f00dfac', 'phcorp', 1_700_000_000, $subject));

        return $header;
    }

    /** @return list<string> */
    private static function rows(CommitHeaderWidget $header, int $width = 50): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $header->render(new RenderContext($width, 5)));
    }

    public function testRoomyTheSubjectStandsOverTheAuthorShaAndStat(): void
    {
        $header = $this->header();
        $header->setStat(new DiffStat(3, 1));

        $this->assertSame(
            [
                ' Record the parallel flake fixes                  ',
                ' phcorp · f00dfac ⧉                         +3 −1 ',
                '──────────────────────────────────────────────────',
            ],
            self::rows($header),
        );
        $raw = $header->render(new RenderContext(50, 5));
        $styles = Styles::dark();
        $this->assertStringContainsString($styles->of(Role::Added)->apply('+3'), $raw[1], 'added in the diff\'s green');
        $this->assertStringContainsString($styles->of(Role::Deleted)->apply('−1'), $raw[1], 'removed in its red');
    }

    public function testCompactOneRowSaysItAllAndTheSubjectGivesWay(): void
    {
        $header = $this->header(CompactScreen::density(), 'Release the session lock before an embedded editor reloads');
        $header->setStat(new DiffStat(12, 0));

        $rows = self::rows($header, 50);

        $this->assertCount(2, $rows);
        $this->assertSame(' Release the session lock bef… · f00dfac ⧉ +12 −0 ', $rows[0]);
    }

    public function testUntilGitHasCountedThereIsNoStatAndANewCommitDropsTheOld(): void
    {
        $header = $this->header();
        $header->setStat(new DiffStat(3, 1));
        $header->setCommit(new LogEntry('b', 'bbbbbbb', 'Bob', 1_700_000_000, 'Next'));

        $this->assertSame(' Bob · bbbbbbb ⧉', \rtrim(self::rows($header)[1]));
    }

    /** Stacked, the rule names the files count, standing in for the files column's heading. */
    public function testStackedTheRuleCountsTheFiles(): void
    {
        $header = $this->header();
        $header->nameFiles(2);

        $this->assertSame('─ 2 changed files ────────────', self::rows($header, 30)[2]);
        $header->nameFiles(null);
        $this->assertSame(\str_repeat('─', times: 30), self::rows($header, 30)[2]);
    }

    public function testNoCommitIsTheRuleAlone(): void
    {
        $header = new CommitHeaderWidget(new Translator('en'));

        $this->assertSame([\str_repeat('─', times: 10)], self::rows($header, 10));
        $this->assertNull($header->tooltipAt(0, 1));
        $header->handleClick(0, 1);
        $this->assertSame([], $this->copied);
    }

    public function testTheCopyButtonCopiesTheFullShaAndExplainsItself(): void
    {
        $header = $this->header();
        self::rows($header);
        $copy = \mb_strlen(' phcorp · f00dfac ');

        $header->handleClick(1, $copy - 2); // the sha: nothing
        $header->handleClick(0, $copy); // the subject row: nothing
        $header->handleClick(1, $copy);

        $this->assertSame([self::SHA], $this->copied);
        $this->assertSame('Copy the full commit hash', $header->tooltipAt(1, $copy)?->text);
        $this->assertSame(self::SHA."\nRecord the parallel flake fixes\nphcorp · ".\date('Y-m-d H:i', timestamp: 1_700_000_000), $header->tooltipAt(0, 3)?->text, 'the commit in full');
        $this->assertNull($header->tooltipAt(2, 3), 'the rule says nothing');
    }

    /** Too narrow for the copy button whole, there is none to press. */
    public function testANarrowHeaderDropsTheCopyButton(): void
    {
        $header = $this->header();
        $header->setStat(new DiffStat(1234, 5678));
        $rows = self::rows($header, 16);

        $this->assertSame('f0… +1234 −5678 ', $rows[1], 'the stat stays; the rest gives way');
        foreach (\range(0, end: 15) as $col) {
            $header->handleClick(1, $col);
        }
        $this->assertSame([], $this->copied);
    }

    /** An author cut short still stands apart from the sha. */
    public function testACutAuthorKeepsABlankBeforeTheSha(): void
    {
        $header = $this->header();
        $header->setStat(new DiffStat(6, 1));

        $this->assertSame(' phc… f00dfac ⧉ +6 −1 ', self::rows($header, 22)[1]);
    }

    /** Another commit's files are not this one's: the stacked count waits for its own. */
    public function testANewCommitDropsTheOldCount(): void
    {
        $header = $this->header();
        $header->nameFiles(5);

        $header->setCommit(new LogEntry('b', 'bbbbbbb', 'Bob', 1_700_000_000, 'Next'));
        $this->assertSame(\str_repeat('─', times: 30), self::rows($header, 30)[2]);
        $header->setCommit(null);
        $header->nameFiles(null);
        $this->assertSame([\str_repeat('─', times: 30)], self::rows($header, 30));
    }
}
