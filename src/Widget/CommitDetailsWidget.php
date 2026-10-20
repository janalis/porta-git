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
 * GitHub Desktop's middle column in History: the files the selected commit
 * changed, under a `N changed files` heading level with the diff's file
 * header beside it. Selecting a file drives the read-only diff; the commit
 * itself is said above both ({@see CommitHeaderWidget}).
 *
 * Stacked over the diff — a window too narrow for three columns — the
 * heading goes (the commit header's rule names the count instead) and the
 * list stops at {@see STACKED_ROWS}, closed off by a rule.
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

    /** Stacked over the diff, the most file rows the list takes before it scrolls. */
    private const int STACKED_ROWS = 6;

    /** Whether the column is stacked over the diff rather than beside it. */
    private bool $stacked = false;

    private ?LogEntry $commit = null;

    /** @var list<array{status: string, path: string}> */
    private array $files = [];

    private readonly ListSelection $fileList;

    /** The file row under the pointer; visual only. */
    private ?int $hovered = null;

    /** Rows above the first file row in the last render: the heading and its rule. */
    private int $headerRows = 0;

    /** File rows the last render had room for. */
    private int $fileRows = 12;

    private int $drawnWidth = 0;

    /**
     * A file's whole path, keyed by the row it was cut on.
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

    /** Stack the column over the diff, or stand it beside it again. */
    public function stack(SplitShape $shape): void
    {
        $this->stacked = SplitShape::Stacked === $shape;
        $this->invalidate();
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

    public function fileCount(): int
    {
        return \count($this->files);
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

    /** A press on a file row selects that file. */
    public function handleClick(int $relRow): void
    {
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
        return \max(1, $this->fileRows);
    }

    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $this->drawnWidth = $width;
        $this->tips = [];
        $lines = [];
        if (!$this->stacked) {
            $heading = null === $this->commit ? '' : $this->translator->trans('%count% changed file|%count% changed files', ['%count%' => \count($this->files)]);
            // The diff's file header's shape beside it: a bar row and its close.
            $lines[] = $this->styles->of(Role::HeaderBar)->apply(AnsiText::truncate(
                \str_repeat(' ', \max(0, \intdiv($width - AnsiUtils::visibleWidth($heading), num2: 2))).$heading,
                $width,
                pad: true,
            ));
            $lines[] = Rule::under($width, $this->styles, Role::HeaderBar);
        }
        $this->headerRows = \count($lines);
        $rows = $this->stacked
            ? \min(self::STACKED_ROWS, \max(1, \count($this->files)))
            : \max(1, ($context->getRows() > 0 ? $context->getRows() : 12) - $this->headerRows);
        $this->fileRows = $rows;

        $scroll = $this->fileList->follow($rows);
        for ($i = 0; $i < $rows; ++$i) {
            $lines[] = $this->fileRow($scroll + $i, \count($lines), $width);
        }
        if ($this->stacked) {
            $lines[] = Rule::horizontal($width, $this->styles);
        }

        return $lines;
    }

    /** File $index's row — drawn at $row, $width wide — or a blank past the last file. */
    private function fileRow(int $index, int $row, int $width): string
    {
        $file = $this->files[$index] ?? null;
        if (null === $file) {
            return AnsiText::blank($width);
        }
        if ((AnsiUtils::visibleWidth($file['path']) + 4) > $width) {
            $this->tips[$row] = ['key' => 'file:'.$file['path'], 'text' => $file['path']];
        }
        $selected = $this->fileList->isSelected($index);
        $line = SelectionBar::of($selected, $this->focused)->value.FileRow::render($file['path'], $file['status'], \max(0, $width - 1), $this->styles);

        return $this->shadeRow(AnsiText::truncate($line, $width, pad: true), $selected, $index === $this->hovered);
    }

    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
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
