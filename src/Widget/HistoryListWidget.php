<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitViewMode;
use Porta\GitPlugin\LogEntry;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The history tab's left column: recent commits on top, the selected
 * commit's files below. Tab toggles between the two lists; selecting a
 * file drives the read-only diff pane. Selection changes fire onSelectCommit
 * / onSelectFile so the controller can load `git show` data.
 */
final class HistoryListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use GitPaneKeys;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use StylesDefault;

    private const int FILE_ROWS = 8;

    /** @var list<LogEntry> */
    private array $commits = [];
    /** @var list<array{status: string, path: string}> */
    private array $files = [];
    private readonly ListSelection $commitList;
    private readonly ListSelection $fileList;
    private bool $filesFocused = false;
    /** Rows tinted under the mouse pointer; visual only, never the selection. */
    private ?int $hoveredCommit = null;
    private ?int $hoveredFile = null;
    private int $lastRows = 12;

    /** Whether an older page exists past the loaded commits. */
    private bool $hasMore = false;
    /** Latch: one load-more in flight at a time. */
    private bool $loadingMore = false;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->events = $events;
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the focused sub-list
        $this->width = $width;
        $this->initStyles($styles);
        $this->commitList = new ListSelection();
        $this->fileList = new ListSelection();
    }

    /** @param list<LogEntry> $commits */
    public function setCommits(array $commits): void
    {
        $this->loadingMore = false;
        $this->commits = $commits;
        $this->commitList->reset();
        $this->hoveredFile = null;
        $this->hoveredCommit = null;
        $this->files = [];
        $this->fileList->reset();
        $this->filesFocused = false;
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
        $known = array_flip(array_column($this->commits, 'sha'));
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

    /** @param list<array{status: string, path: string}> $files */
    public function setFiles(array $files): void
    {
        $this->files = $files;
        $this->fileList->reset();
        $this->hoveredFile = null;
        $this->invalidate();
    }

    public function selectedCommit(): ?LogEntry
    {
        return $this->commits[$this->commitList->selected()] ?? null;
    }

    /** The screen-relative row of the selected commit (1-based; the header is row 0). */
    public function selectedCommitRow(): int
    {
        return $this->commitList->selected() - $this->commitList->scroll() + 1;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($this->handleGitPaneKey($data, $kb)) {
            return;
        }
        if ($kb->matches($data, 'show_changes')) {
            $this->events?->dispatch(new GitViewModeRequested(GitViewMode::Changes));

            return;
        }
        if ($kb->matches($data, 'switch_list')) {
            $this->filesFocused = !$this->filesFocused && [] !== $this->files;
            $this->invalidate();

            return;
        }

        if ($this->filesFocused) {
            if (ListNavigation::handleKey($data, $kb, $this->fileList, \count($this->files), page: self::FILE_ROWS)) {
                $this->fireSelectFile();
                $this->invalidate();
            }

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->commitList, \count($this->commits), page: $this->lastRows)) {
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

    /** Click routing: commit rows select a commit, file rows select a file. */
    public function handleClick(int $relRow): void
    {
        $commitRows = $this->commitRowCount();
        if ($relRow >= 1 && $relRow < (1 + $commitRows)) {
            $index = $this->commitList->scroll() + $relRow - 1;
            if (\array_key_exists($index, $this->commits)) {
                $this->filesFocused = false;
                // Re-clicking the selected commit must not reload its files —
                // that would clobber a diff the user already opened.
                if ($this->commitList->selectIfChanged($index)) {
                    $this->fireSelectCommit();
                }
                $this->invalidate();
            }

            return;
        }
        $fileHeader = 1 + $commitRows;
        $index = $this->fileList->scroll() + $relRow - $fileHeader - 1;
        if ($relRow > $fileHeader && \array_key_exists($index, $this->files)) {
            $this->filesFocused = true;
            $this->fileList->select($index);
            $this->fireSelectFile();
            $this->invalidate();
        }
    }

    /**
     * A right-click landed on a commit row: select it (like a left click) and
     * return its entry for the context menu; null off the commit rows.
     */
    public function selectCommitAt(int $relRow): ?LogEntry
    {
        $commitRows = $this->commitRowCount();
        if ($relRow < 1 || $relRow >= (1 + $commitRows)) {
            return null;
        }
        $index = $this->commitList->scroll() + $relRow - 1;
        if (!\array_key_exists($index, $this->commits)) {
            return null;
        }
        $this->filesFocused = false;
        if ($this->commitList->selectIfChanged($index)) {
            $this->fireSelectCommit();
        }
        $this->invalidate();

        return $this->commits[$index];
    }

    /**
     * Tint the row under the pointer (commit or file section, same row math
     * as {@see handleClick}); a pure visual that never moves the selections.
     */
    public function hoverRow(int $relRow): bool
    {
        $commitRows = $this->commitRowCount();
        if ($relRow >= 1 && $relRow < (1 + $commitRows)) {
            $index = $this->commitList->scroll() + $relRow - 1;

            return $this->setHovered(\array_key_exists($index, $this->commits) ? $index : null, null);
        }
        $fileHeader = 1 + $commitRows;
        $index = $this->fileList->scroll() + $relRow - $fileHeader - 1;

        return $this->setHovered(null, $relRow > $fileHeader && \array_key_exists($index, $this->files) ? $index : null);
    }

    /** The pointer left the list: drop the hover tint. */
    public function clearHover(): bool
    {
        return $this->setHovered(null, null);
    }

    private function setHovered(?int $commit, ?int $file): bool
    {
        if ($commit === $this->hoveredCommit && $file === $this->hoveredFile) {
            return false;
        }
        $this->hoveredCommit = $commit;
        $this->hoveredFile = $file;
        $this->invalidate();

        return true;
    }

    private function fireSelectCommit(): void
    {
        if (null !== ($commit = $this->selectedCommit())) {
            $this->events?->dispatch(new GitCommitSelected($commit));
        }
    }

    private function fireSelectFile(): void
    {
        $commit = $this->selectedCommit();
        $file = $this->files[$this->fileList->selected()] ?? null;
        if (null !== $commit && null !== $file) {
            $this->events?->dispatch(new GitCommitFileSelected($commit, $file['path']));
        }
    }

    private function commitRowCount(): int
    {
        return max(1, $this->lastRows - 2 - self::FILE_ROWS);
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
        $width = min($this->width, $context->getColumns());
        $rows = max(4, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $header = null === $this->filterLabel
            ? $this->translator->trans(' History (%count%)', ['%count%' => \count($this->commits)])
            // An em dash, not a colon: symfony's legacy plural handling treats
            // "label: message" specially whenever %count% is a parameter.
            : $this->translator->trans(' History — %path% (%count%)', ['%path%' => $this->filterLabel, '%count%' => \count($this->commits)]);
        $lines = [HeaderRenderer::render($header, $width, $this->styles->of(Role::HeaderBar)->withBold())];

        $commitRows = $this->commitRowCount();
        $scroll = $this->commitList->follow($commitRows);
        for ($row = 0; $row < $commitRows; ++$row) {
            $commit = $this->commits[$scroll + $row] ?? null;
            if (null === $commit) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }
            $selected = $this->commitList->isSelected($scroll + $row);
            $bg = null;
            if ($selected) {
                // The focused sub-list resolves the theme's .list::selected:focus
                // rule; the parked one shows the shared unfocused shade.
                $bg = $this->focused && !$this->filesFocused ? $this->resolveElement('selected') : $this->styles->of(Role::SelectedRowUnfocused);
            }
            if (!$selected && ($scroll + $row) === $this->hoveredCommit) {
                $bg = $this->styles->of(Role::RowHighlightBg);
            }
            $text = ' '.$this->styles->of(Role::Modified)->apply($commit->shortSha).' '.$commit->subject.$this->styles->of(Role::Dim)->apply('  '.$commit->author);
            $text = AnsiText::truncate($text, $width, pad: true);
            $lines[] = null !== $bg ? $bg->apply($text) : $text;
        }

        $lines[] = HeaderRenderer::render($this->translator->trans(' Files (%count%)', ['%count%' => \count($this->files)]), $width, $this->styles->merge(Role::Toolbar, Role::Dim)->withBold());
        $fileScroll = $this->fileList->follow(self::FILE_ROWS);
        for ($row = 0; $row < self::FILE_ROWS && \count($lines) < $rows; ++$row) {
            $file = $this->files[$fileScroll + $row] ?? null;
            if (null === $file) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }
            $selected = $this->fileList->isSelected($fileScroll + $row);
            $bg = null;
            if ($selected) {
                $bg = $this->focused && $this->filesFocused ? $this->resolveElement('selected') : $this->styles->of(Role::SelectedRowUnfocused);
            }
            if (!$selected && ($fileScroll + $row) === $this->hoveredFile) {
                $bg = $this->styles->of(Role::RowHighlightBg);
            }
            $text = AnsiText::truncate(' '.$file['status'].' '.$file['path'], $width, pad: true);
            $lines[] = null !== $bg ? $bg->apply($text) : $text;
        }

        // commitRowCount + the two headers + FILE_ROWS always fill $rows exactly
        // (the file loop is height-capped), so no tail padding is needed.

        return $this->withMenuOverlay($lines, $width);
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
}
