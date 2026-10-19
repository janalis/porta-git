<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Render\SelectionBar;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * GitHub Desktop's middle column in History: the selected commit — its
 * subject, its author and short sha with a copy button — over the files it
 * changed. Selecting a file drives the read-only diff beside it.
 *
 * The pane draws its own right border, a rule rather than a drag handle: it
 * takes a share of the content, not a width someone set.
 */
final class CommitDetailsWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, TooltipProvider, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use GitPaneKeys;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelRowStyle;

    /** The copy button after the short sha. Checked narrow: East Asian Neutral, no emoji presentation. */
    private const string COPY = '⧉';

    /** A long subject wraps to this many rows; the rest is the tooltip's. */
    private const int SUBJECT_ROWS = 2;

    private ?LogEntry $commit = null;

    /** @var list<array{status: string, path: string}> */
    private array $files = [];

    private readonly ListSelection $fileList;

    /** The file row under the pointer; visual only. */
    private ?int $hovered = null;

    /** Rows above the first file row in the last render: the header and the rule. */
    private int $headerRows = 0;

    private int $lastRows = 12;

    /**
     * The copy button's columns [start, end) on the sha row, or null when it was not drawn.
     *
     * @var array{int, int}|null
     */
    private ?array $copyZone = null;

    /** The sha row's index in the last render. */
    private int $shaRow = 0;

    private int $drawnWidth = 0;

    /**
     * What each drawn row could not fit, keyed by row: the commit in full on
     * the header rows, a file's path when it was cut.
     *
     * @var array<int, array{key: string, text: string}>
     */
    private array $tips = [];

    /** @var (callable(): void)|null Enter: back to the commit list */
    private $onBack;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->events = $events;
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->initStyles($styles);
        $this->fileList = new ListSelection();
    }

    /** @param callable(): void $callback Enter: back to the commit list */
    public function onBack(callable $callback): self
    {
        $this->onBack = $callback;

        return $this;
    }

    /** Show $commit, its files still to come; null empties the pane. */
    public function setCommit(?LogEntry $commit): void
    {
        $this->commit = $commit;
        $this->setFiles([]);
    }

    /** @param list<array{status: string, path: string}> $files */
    public function setFiles(array $files): void
    {
        $this->files = $files;
        $this->fileList->reset();
        $this->hovered = null;
        $this->invalidate();
    }

    public function commit(): ?LogEntry
    {
        return $this->commit;
    }

    /** @return array{status: string, path: string}|null */
    public function selectedFile(): ?array
    {
        return $this->files[$this->fileList->selected()] ?? null;
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
            $kb->matches($data, 'switch_list') => $this->fire($this->onBack),
            $kb->matches($data, 'copy_hash') => $this->copyHash(),
            ListNavigation::handleKey($data, $kb, $this->fileList, \count($this->files), page: $this->fileRowCount()) => $this->fireSelectFile(),
            default => null,
        };
    }

    /** A press: a file row selects that file, the copy button copies the sha. */
    public function handleClick(int $relRow, int $relCol): void
    {
        if ($relRow === $this->shaRow && null !== $this->copyZone && $relCol >= $this->copyZone[0] && $relCol < $this->copyZone[1]) {
            $this->copyHash();

            return;
        }
        $index = $this->fileAt($relRow);
        if (null !== $index) {
            $this->fileList->select($index);
            $this->fireSelectFile();
        }
    }

    public function hoverRow(int $relRow): bool
    {
        $index = $this->fileAt($relRow);
        if ($index === $this->hovered) {
            return false;
        }
        $this->hovered = $index;
        $this->invalidate();

        return true;
    }

    public function clearHover(): bool
    {
        if (null === $this->hovered) {
            return false;
        }
        $this->hovered = null;
        $this->invalidate();

        return true;
    }

    private function fileAt(int $relRow): ?int
    {
        $index = $this->fileList->scroll() + $relRow - $this->headerRows;

        return $relRow >= $this->headerRows && \array_key_exists($index, $this->files) ? $index : null;
    }

    private function copyHash(): void
    {
        if (null !== $this->commit) {
            $this->events?->dispatch(new GitCommitHashCopyRequested($this->commit));
        }
    }

    private function fireSelectFile(): void
    {
        $file = $this->selectedFile();
        if (null !== $this->commit && null !== $file) {
            $this->events?->dispatch(new GitCommitFileSelected($this->commit, $file['path']));
        }
        $this->invalidate();
    }

    private function fileRowCount(): int
    {
        return \max(1, $this->lastRows - $this->headerRows);
    }

    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;
        $this->drawnWidth = $width;
        // The last column is the pane's border with the diff.
        $inner = \max(0, $width - 1);
        $this->tips = [];
        $this->copyZone = null;

        $lines = null === $this->commit ? [AnsiText::blank($inner)] : $this->header($inner);
        $this->shaRow = \count($lines) - 1;
        $legend = $this->translator->trans('%count% changed file|%count% changed files', ['%count%' => \count($this->files)]);
        $lines[] = Rule::horizontal($inner, $this->styles, null === $this->commit ? '' : $legend);
        $this->headerRows = \count($lines);

        $scroll = $this->fileList->follow($this->fileRowCount());
        while (\count($lines) < $rows) {
            $index = $scroll + \count($lines) - $this->headerRows;
            $file = $this->files[$index] ?? null;
            if (null === $file) {
                $lines[] = AnsiText::blank($inner);
                continue;
            }
            if ((AnsiUtils::visibleWidth($file['path']) + 4) > $inner) {
                $this->tips[\count($lines)] = ['key' => 'file:'.$file['path'], 'text' => $file['path']];
            }
            $selected = $this->fileList->isSelected($index);
            $row = SelectionBar::of($selected, $this->focused)->value.FileRow::render($file['path'], $file['status'], \max(0, $inner - 1), $this->styles);
            $lines[] = $this->shadeRow(AnsiText::truncate($row, $inner, pad: true), $selected, $index === $this->hovered);
        }
        $border = $this->styles->of(Role::Rule)->apply(Glyphs::PANE_DIVIDER);

        return \array_map(static fn (string $line): string => AnsiText::truncate($line, $inner, pad: true).$border, \array_slice($lines, offset: 0, length: $rows));
    }

    /**
     * The subject, bold and wrapped, then `author · sha ⧉`.
     *
     * @return list<string>
     */
    private function header(int $width): array
    {
        \assert(null !== $this->commit, description: 'only a shown commit has a header');
        $commit = $this->commit;
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $subject = AnsiText::wrap(AnsiText::oneLine($commit->subject), \max(1, $width - 1));
        if (\count($subject) > self::SUBJECT_ROWS) {
            // The last row it keeps says there is more, the tooltip the rest.
            $kept = \array_slice($subject, offset: 0, length: self::SUBJECT_ROWS - 1);
            $kept[] = AnsiText::truncate(\implode(' ', \array_slice($subject, offset: self::SUBJECT_ROWS - 1)), \max(1, $width - 1));
            $subject = $kept;
        }
        $lines = [];
        foreach ($subject as $line) {
            $lines[] = ' '.$bold->apply($line);
        }
        $whole = $commit->sha."\n".$commit->subject."\n".$commit->author.' · '.\date('Y-m-d H:i', $commit->timestamp);
        foreach (\array_keys($lines) as $row) {
            $this->tips[$row] = ['key' => 'commit:'.$commit->sha, 'text' => $whole];
        }
        $prefix = ' '.$this->styles->of(Role::Dim)->apply(AnsiText::oneLine($commit->author).' · ');
        $sha = $this->styles->of(Role::Modified)->apply($commit->shortSha);
        $start = AnsiUtils::visibleWidth($prefix.$sha) + 1;
        $line = $prefix.$sha.' '.self::COPY;
        if (AnsiUtils::visibleWidth($line) <= $width) {
            $this->copyZone = [$start, $start + 1];
        }
        $this->tips[\count($lines)] = ['key' => 'commit:'.$commit->sha, 'text' => $whole];
        $lines[] = AnsiText::truncate($line, $width);

        return $lines;
    }

    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        if ($row === $this->shaRow && null !== $this->copyZone && $col >= $this->copyZone[0] && $col < $this->copyZone[1]) {
            return new Tooltip('git:details:copy', $this->translator->trans('Copy the full commit hash'), $row, $this->copyZone[0], $this->copyZone[1]);
        }
        $tip = $this->tips[$row] ?? null;

        return null === $tip ? null : new Tooltip('git:details:'.$tip['key'], $tip['text'], $row, 0, $this->drawnWidth);
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
            'copy_hash' => ['y'],
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
        return \max(1, $context->getColumns());
    }
}
