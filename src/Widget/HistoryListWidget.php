<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Support\RelativeTime;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Density;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\SelectionBar;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The history tab's left column: the commits, GitHub Desktop's way — two rows
 * each, the subject over `author · 5 hours ago`. Selecting one fires
 * {@see GitCommitSelected}; its files are {@see CommitDetailsWidget}'s, the
 * column beside this one, which `Enter` moves to.
 */
final class HistoryListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, TooltipProvider, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use GitPaneKeys;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelRowStyle;
    use PanelWidth;

    /** A commit's text: its subject, then who and when. */
    private const int TEXT_ROWS = 2;

    /** The rule between two commits on a roomy screen, GitHub Desktop's hairline. Box drawing: narrow in every TUI. */
    private const string SEPARATOR = '╌';

    private readonly ScreenDensity $density;

    /** @var list<LogEntry> */
    private array $commits = [];
    private readonly ListSelection $commitList;
    /** The commit tinted under the mouse pointer; visual only, never the selection. */
    private ?int $hoveredCommit = null;
    private int $lastRows = 12;

    private readonly RelativeTime $time;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * What each drawn row could not fit, from the last render, keyed by
     * widget-relative row: a commit's full sha, subject, author and date; a
     * file's path cut short.
     *
     * @var array<int, array{key: string, text: string}>
     */
    private array $tips = [];

    /** The width the last render drew at — a row tooltip's span. */
    private int $drawnWidth = 0;

    /** Whether an older page exists past the loaded commits. */
    private bool $hasMore = false;
    /** Latch: one load-more in flight at a time. */
    private bool $loadingMore = false;

    /** @param (\Closure(): int)|null $clock now, as a Unix timestamp; the real clock by default */
    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?EventDispatcherInterface $events = null,
        ?\Closure $clock = null,
        ?ScreenDensity $density = null,
    ) {
        $this->density = $density ?? new ScreenDensity();
        $this->events = $events;
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->width = $width;
        $this->initStyles($styles);
        $this->commitList = new ListSelection();
        $this->time = new RelativeTime($translator);
        $this->clock = $clock ?? \time(...);
    }

    /** @param list<LogEntry> $commits */
    public function setCommits(array $commits): void
    {
        $this->loadingMore = false;
        $this->commits = $commits;
        $this->commitList->reset();
        $this->hoveredCommit = null;
        $this->invalidate();
    }

    /**
     * Append an older page, preserving the selection/scroll and the files
     * pane. Deduped by sha — `--skip` offsets drift when new commits land
     * between pages.
     *
     * @param list<LogEntry> $commits
     */
    public function appendCommits(array $commits): void
    {
        $this->loadingMore = false;
        $known = \array_flip(\array_column($this->commits, 'sha'));
        foreach ($commits as $commit) {
            if (\array_key_exists($commit->sha, $known)) {
                continue;
            }

            $this->commits[] = $commit;
        }
        $this->invalidate();
    }

    public function markHasMore(): void
    {
        $this->hasMore = true;
    }

    public function markNoMore(): void
    {
        $this->hasMore = false;
    }

    public function commitCount(): int
    {
        return \count($this->commits);
    }

    public function selectedCommit(): ?LogEntry
    {
        return $this->commits[$this->commitList->selected()] ?? null;
    }

    /**
     * Put the selection back on a commit by sha after {@see setCommits()} has
     * reset it. A commit that is no longer here is not an error to report: a
     * reload can legitimately drop one (a rebase, a force push, a narrowed path
     * filter), and the reset has already left the selection on the first
     * commit, which is the only honest fallback.
     *
     * The caller reloads the files for whichever commit ends up selected,
     * because a file list belonging to the PREVIOUSLY selected commit is worse
     * than none.
     */
    public function selectSha(string $sha): void
    {
        foreach ($this->commits as $index => $commit) {
            if ($commit->sha !== $sha) {
                continue;
            }
            $this->commitList->select($index);
            $this->invalidate();

            return;
        }
    }

    /** The widget-relative row of the selected commit's who-and-when, its last text row: a menu opens under it. */
    public function selectedCommitRow(): int
    {
        return $this->headRows() + (($this->commitList->selected() - $this->commitList->scroll()) * $this->entryRows()) + self::TEXT_ROWS - 1;
    }

    /** The rows a commit takes: its two, and the rule under them where the screen is roomy. */
    private function entryRows(): int
    {
        return Density::Roomy === $this->density->current() ? self::TEXT_ROWS + 1 : self::TEXT_ROWS;
    }

    /** The rows over the commits: the heading that names a file the history is filtered to, or none. */
    private function headRows(): int
    {
        return null === $this->filterLabel ? 0 : 1;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        match (true) {
            $this->handleGitPaneKey($data, $kb) => null,
            $kb->matches($data, 'show_changes') => $this->events?->dispatch(new GitViewModeRequested(GitViewMode::Changes)),
            // Into the commit's files, the column beside: the next pane.
            $kb->matches($data, 'switch_list') => $this->fire($this->onFocusNext),
            default => $this->navigateCommits($data, $kb),
        };
    }

    private function navigateCommits(string $data, Keybindings $kb): void
    {
        // A page is the commits the pane SHOWS — two or three rows each —
        // not its height in rows, or a page would skip that much farther
        // than the screen showed.
        if (ListNavigation::handleKey($data, $kb, $this->commitList, \count($this->commits), page: $this->commitRowCount())) {
            $this->fireSelectCommit();
            // Hitting the bottom of the loaded page pulls the next one in
            // (keyboard and wheel — GitMouseRouter translates scroll to keys).
            if ($this->hasMore && !$this->loadingMore && $this->commitList->selected() === (\count($this->commits) - 1)) {
                $this->loadingMore = true;
                $this->events?->dispatch(new GitHistoryEndReached());
            }
            $this->invalidate();
        }
    }

    /** The commit whose text covers widget-relative $relRow, or null — the rule between two is neither's. */
    private function commitAt(int $relRow): ?int
    {
        $entry = $relRow - $this->headRows();
        $index = $this->commitList->scroll() + \intdiv($entry, $this->entryRows());
        $onText = $entry >= 0 && ($entry % $this->entryRows()) < self::TEXT_ROWS && $entry < ($this->commitRowCount() * $this->entryRows());

        return $onText && \array_key_exists($index, $this->commits) ? $index : null;
    }

    /** A press on either row of a commit selects it. */
    public function handleClick(int $relRow): void
    {
        $index = $this->commitAt($relRow);
        // Re-clicking the selected commit must not reload its files — that
        // would clobber a diff the user already opened.
        if (null !== $index && $this->commitList->selectIfChanged($index)) {
            $this->fireSelectCommit();
        }
        $this->invalidate();
    }

    /**
     * A right-click landed on a commit: select it (like a left click) and
     * return its entry for the context menu; null off the commits.
     */
    public function selectCommitAt(int $relRow): ?LogEntry
    {
        $index = $this->commitAt($relRow);
        if (null === $index) {
            return null;
        }
        if ($this->commitList->selectIfChanged($index)) {
            $this->fireSelectCommit();
        }
        $this->invalidate();

        return $this->commits[$index];
    }

    /** Tint the commit under the pointer; a pure visual that never moves the selection. */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->commitAt($relRow));
    }

    /** The pointer left the list: drop the hover tint. */
    public function clearHover(): bool
    {
        return $this->setHovered(null);
    }

    private function setHovered(?int $commit): bool
    {
        if ($commit === $this->hoveredCommit) {
            return false;
        }
        $this->hoveredCommit = $commit;
        $this->invalidate();

        return true;
    }

    private function fireSelectCommit(): void
    {
        if (null !== ($commit = $this->selectedCommit())) {
            $this->events?->dispatch(new GitCommitSelected($commit));
        }
    }

    /** How many commits the pane shows, under the filter's heading when there is one. */
    private function commitRowCount(): int
    {
        return \max(1, \intdiv($this->lastRows - $this->headRows(), $this->entryRows()));
    }

    /** The repo-relative file this history is filtered to, or null for repo-wide. */
    private ?string $filterLabel = null;

    /** Show which file the history is filtered to (null = repo-wide). */
    public function setFilterLabel(?string $label): void
    {
        $this->filterLabel = $label;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(4, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        // GitHub Desktop heads the list with nothing — the tab says History —
        // unless it is one file's: then the heading says which.
        $lines = null === $this->filterLabel
            ? []
            // An em dash, not a colon: symfony's legacy plural handling treats
            // "label: message" specially whenever %count% is a parameter.
            : [HeaderRenderer::title($this->translator->trans('History — %path% (%count%)', ['%path%' => $this->filterLabel, '%count%' => \count($this->commits)]), $width, $this->styles)];
        $this->tips = [];
        $this->drawnWidth = $width;

        $commitRows = $this->commitRowCount();
        $scroll = $this->commitList->follow($commitRows);
        $now = ($this->clock)();
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $dim = $this->styles->of(Role::Dim);
        for ($row = 0; $row < $commitRows; ++$row) {
            $index = $scroll + $row;
            $commit = $this->commits[$index] ?? null;
            if (null === $commit) {
                \array_push($lines, ...\array_fill(0, \max(0, $this->entryRows()), AnsiText::blank($width)));
                continue;
            }
            $selected = $this->commitList->isSelected($index);
            $bar = SelectionBar::of($selected, $this->focused)->value;
            // Two rows, the card says it all, and when to the minute.
            $tip = ['key' => 'commit:'.$commit->sha, 'text' => $commit->sha."\n".$commit->subject."\n".$commit->author.' · '.\date('Y-m-d H:i', $commit->timestamp)];
            $this->tips[\count($lines)] = $tip;
            $this->tips[\count($lines) + 1] = $tip;
            $hovered = $index === $this->hoveredCommit;
            $lines[] = $this->shadeRow(AnsiText::truncate($bar.$bold->apply(AnsiText::oneLine($commit->subject)), $width, pad: true), $selected, $hovered);
            $lines[] = $this->shadeRow(
                AnsiText::truncate($bar.$dim->apply(AnsiText::oneLine($commit->author).' · '.$this->time->long($commit->timestamp, $now)), $width, pad: true),
                $selected,
                $hovered,
            );
            if (self::TEXT_ROWS < $this->entryRows()) {
                $lines[] = AnsiText::truncate(' '.$this->styles->of(Role::Rule)->apply(\str_repeat(self::SEPARATOR, \max(0, $width - 2))), $width, pad: true);
            }
        }
        // The heading and the entries fill $rows but for the rows left over.
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
        }

        return $lines;
    }

    /** Either row of a commit says it in full. */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $tip = $this->tips[$row] ?? null;

        return null === $tip ? null : new Tooltip('git:history:'.$tip['key'], $tip['text'], $row, 0, $this->drawnWidth);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'switch_list' => [Key::ENTER],
            'show_changes' => ['1'],
            'fetch' => ['f'],
            'pull' => ['p'],
            'push' => ['shift+p'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
