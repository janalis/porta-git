<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitStageAllRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The git view's changed-files list (GitHub Desktop's left column): one row
 * per {@see ChangeEntry} as `[✓] M path`, where the checkbox mirrors the
 * staged state. Space toggles staging, `d` arms an inline discard
 * confirmation, Enter/selection drives the diff pane via onSelect.
 */
final class ChangesListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, TooltipProvider, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use GitPaneKeys;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use PanelRowStyle;

    /** @var array<string, Role> file-status char → color role */
    private const array STATUS_ROLE = [
        'A' => Role::Added,
        'M' => Role::Modified,
        'D' => Role::Deleted,
        'R' => Role::Hunk,
        'C' => Role::Hunk,
    ];

    /** @var list<ChangeEntry> */
    private array $entries = [];
    private readonly ListSelection $list;

    private readonly ScreenDensity $density;
    /** Entries the pane last had room for from its scroll — the page size for PgUp/PgDn. */
    private int $lastPage = 1;
    /** Index of the entry an inline discard confirmation is armed for, or null. */
    private ?int $confirmDiscard = null;
    /**
     * Which entry each screen row of the last render belongs to — null for the
     * title and the padding — so a click and a hover land on what was drawn:
     * an armed entry is a block of rows, not one.
     *
     * @var list<int|null>
     */
    private array $rowOwner = [];
    /**
     * The armed confirmation's two buttons as drawn: the pane rows they span
     * and each one's columns.
     *
     * @var array{rows: array{int, int}, discard: array{int, int}, keep: array{int, int}}|null
     */
    private ?array $confirmButtons = null;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?ScreenDensity $density = null,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->events = $events;
        $this->width = $width;
        $this->initStyles($styles);
        $this->density = $density ?? new ScreenDensity();
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /**
     * Replace the entries, keeping the selection on the same path when it
     * still exists (staging ops reorder nothing but statuses change).
     *
     * @param list<ChangeEntry> $entries
     */
    public function setEntries(array $entries): void
    {
        $previous = $this->selectedEntry()?->path;
        $this->entries = $entries;
        $this->hoveredIndex = null; // rows moved under the pointer
        $this->hoveredBox = null;
        if (null !== $previous) {
            foreach ($entries as $i => $entry) {
                if ($entry->path !== $previous) {
                    continue;
                }

                $this->list->select($i);
                $this->confirmDiscard = null !== $this->confirmDiscard ? $i : null;
                $this->invalidate();

                return;
            }
        }
        $this->list->clamp(\count($entries));
        $this->confirmDiscard = null;
        $this->invalidate();
    }

    /**
     * Select $path's row, quietly — no selection event: the caller loads what
     * the row shows itself. False when no row has that path.
     */
    public function selectPath(string $path): bool
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry->path !== $path) {
                continue;
            }
            $this->list->select($i);
            $this->invalidate();

            return true;
        }

        return false;
    }

    public function selectedEntry(): ?ChangeEntry
    {
        return $this->entries[$this->list->selected()] ?? null;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        // An armed discard confirmation captures y / Esc first.
        if (null !== $this->confirmDiscard) {
            $entry = $this->entries[$this->confirmDiscard] ?? null;
            $this->confirmDiscard = null;
            if (('y' === $data || 'Y' === $data) && null !== $entry) {
                $this->events?->dispatch(new GitChangeDiscardRequested($entry));
            }
            $this->invalidate();

            return;
        }

        if ($this->handleGitPaneKey($data, $kb)) {
            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->entries), page: $this->lastPage)) {
            $this->fireSelect();
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'toggle_stage')) {
            if (null !== ($entry = $this->selectedEntry())) {
                $this->events?->dispatch(new GitChangeStageToggleRequested($entry));
            }

            return;
        }
        if ($kb->matches($data, 'stage_all')) {
            $this->stageAll();

            return;
        }
        if ($kb->matches($data, 'show_history')) {
            $this->events?->dispatch(new GitViewModeRequested(GitViewMode::History));

            return;
        }
        if ($kb->matches($data, 'discard') && null !== $this->selectedEntry()) {
            $this->confirmDiscard = $this->list->selected();
            $this->invalidate();
        }
    }

    /** A right-click landed on a row: select it (like a left click) and return its entry. */
    public function selectRowAt(int $relRow): ?ChangeEntry
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return null;
        }
        $this->list->select($index);
        $this->fireSelect();
        $this->invalidate();

        return $this->entries[$index];
    }

    /** Context-menu trigger: toggle the selected entry's staging (same path as Space). */
    public function toggleStageSelected(): void
    {
        if (null !== ($entry = $this->selectedEntry())) {
            $this->events?->dispatch(new GitChangeStageToggleRequested($entry));
            $this->invalidate();
        }
    }

    /** Context-menu trigger: arm the selected entry's two-press discard confirmation. */
    public function armDiscardSelected(): void
    {
        if (null !== $this->selectedEntry()) {
            $this->confirmDiscard = $this->list->selected();
            $this->invalidate();
        }
    }

    /** Context-menu trigger: switch to the History tab (same path as the `2` key). */
    public function showHistory(): void
    {
        $this->events?->dispatch(new GitViewModeRequested(GitViewMode::History));
    }

    /** The selected entry's widget-relative row as last drawn (row 0 is the header). */
    public function selectedRow(): int
    {
        $row = \array_search($this->list->selected(), $this->rowOwner, strict: true);

        return false === $row ? 1 : $row;
    }

    /**
     * Left-click: the checkbox column toggles staging, anywhere else selects.
     *
     * While a discard is armed, its buttons answer first — Discard discards,
     * Keep and the question keep — and a click anywhere else disarms it and
     * then does what it would have, just as any other key disarms it.
     */
    public function handleClick(int $relRow, int $relCol): void
    {
        $armed = $this->confirmDiscard;
        if (null !== $armed) {
            $choice = $this->confirmChoiceAt($relRow, $relCol);
            $entry = $this->entries[$armed] ?? null;
            $question = ($this->rowOwner[$relRow] ?? null) === $armed;
            $this->confirmDiscard = null;
            $this->invalidate();
            if ('discard' === $choice && null !== $entry) {
                $this->events?->dispatch(new GitChangeDiscardRequested($entry));
            }
            if (null !== $choice || $question) {
                return;
            }
        }
        // The header's box, with the same target around it as a row's.
        if (0 === $relRow && $relCol <= (FieldBox::CHECKBOX_WIDTH + 1)) {
            $this->stageAll();

            return;
        }
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $entry = $this->entries[$index];
        // The bar, the box and the gap after it: a target a column wider
        // each side of `[✓]`, since the path beside it is a link of its own.
        if ($relCol <= (FieldBox::CHECKBOX_WIDTH + 1)) {
            $this->events?->dispatch(new GitChangeStageToggleRequested($entry));
            $this->invalidate();

            return;
        }
        $this->fireSelect();
        $this->invalidate();
    }

    /**
     * Tint the row under the pointer; returns whether it changed. Hover is a
     * pure visual — it must never move the selection, because the controller
     * reloads the SELECTED entry's diff on every refresh/poll.
     */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /**
     * The box under the pointer, where the box is its own target apart from
     * the row — an entry's index, -1 for the header's, null for none. A
     * browser's checkbox answers the pointer; the row's tint alone did not
     * say which of the two a click there would do.
     */
    private ?int $hoveredBox = null;

    /** The width the last render drew its rows at — where a cut path's tooltip ends. */
    private int $drawnWidth = 0;

    /** The pointer at a widget-relative cell, or off the list (null row): lights the box under it. Returns whether that changed. */
    public function hoverBoxAt(?int $relRow, int $relCol): bool
    {
        $box = match (true) {
            null === $relRow || $relCol > (FieldBox::CHECKBOX_WIDTH + 1) => null,
            0 === $relRow => [] === $this->entries ? null : -1,
            default => $this->rowIndexAt($relRow),
        };
        if ($box === $this->hoveredBox) {
            return false;
        }
        $this->hoveredBox = $box;
        $this->invalidate();

        return true;
    }

    /** A box, underlined while the pointer is on it — the form controls' hover. */
    private function litBox(string $box, int $index): string
    {
        return $index === $this->hoveredBox ? new Style(underline: true)->apply($box) : $box;
    }

    /**
     * The header's box says what it does to every change; a row's box, its
     * status letter and its cut path say theirs ({@see ChangeTooltip}). An
     * armed discard's block is its own question and says nothing more.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $box = $col >= 1 && $col <= FieldBox::CHECKBOX_WIDTH;
        if (0 === $row && $box && [] !== $this->entries) {
            return new Tooltip(
                'git:box:all',
                true === $this->allStaged() ? $this->translator->trans('Unstage everything') : $this->translator->trans('Stage everything'),
                0,
                1,
                1 + FieldBox::CHECKBOX_WIDTH,
            );
        }
        $index = $this->rowIndexAt($row);
        if (null === $index || $index === $this->confirmDiscard) {
            return null;
        }
        $key = ShortcutFormatter::format($this->getKeybindings()->getBindings('toggle_stage')[0] ?? '');

        return ChangeTooltip::of($this->entries[$index], $row, $col, $this->drawnWidth, $key, $this->translator);
    }

    private function rowIndexAt(int $relRow): ?int
    {
        return $this->rowOwner[$relRow] ?? null;
    }

    /** 'discard' or 'keep' when the cell is on one of the armed confirmation's buttons. */
    private function confirmChoiceAt(int $relRow, int $relCol): ?string
    {
        $buttons = $this->confirmButtons;
        if (null === $buttons || $relRow < $buttons['rows'][0] || $relRow >= $buttons['rows'][1]) {
            return null;
        }
        foreach (['discard', 'keep'] as $name) {
            if ($relCol >= $buttons[$name][0] && $relCol < $buttons[$name][1]) {
                return $name;
            }
        }

        return null;
    }

    private function fireSelect(): void
    {
        if (null !== ($entry = $this->selectedEntry())) {
            $this->events?->dispatch(new GitChangeSelected($entry));
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->drawnWidth = $width;

        $count = \count($this->entries);
        $title = $this->translator->trans('%count% changed file|%count% changed files', ['%count%' => $count]);
        // A select-all box heads the list, as a browser's table of checkboxes
        // has one: checked when every change is staged, clear when none is,
        // and the partial mark for a mix. Untinted: the header's style runs
        // across it, and a tint's closing code would drop it for the title.
        $box = [] === $this->entries ? '' : $this->litBox(FieldBox::checkbox($this->allStaged()), -1).' ';
        $lines = [HeaderRenderer::title($box.$title, $width, $this->styles)];
        $this->rowOwner = [null];
        $this->confirmButtons = null;

        // Every entry is a row, the armed one a block: its question, then
        // its two buttons.
        $heights = [];
        foreach (\array_keys($this->entries) as $index) {
            $heights[] = $index === $this->confirmDiscard ? 1 + FieldBox::rowsFor($this->density->current()) : 1;
        }
        $scroll = $this->list->followHeights($heights, $rows - 1);
        $this->lastPage = $this->list->countFitting($heights, $scroll, $rows - 1);
        for ($index = $scroll; $index < $count && \count($lines) < $rows; ++$index) {
            $block = $index === $this->confirmDiscard ? $this->confirmBlock($this->entries[$index], $width, \count($lines)) : [$this->entryRow($index, $width)];
            foreach ($block as $line) {
                if (\count($lines) >= $rows) {
                    break;
                }
                $lines[] = $line;
                $this->rowOwner[] = $index;
            }
        }
        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
            $this->rowOwner[] = null;
        }

        return $lines;
    }

    /** Every change staged (true), none (false), or a mix (null) — the header box's state. */
    private function allStaged(): ?bool
    {
        $states = \array_unique(\array_map(static fn (ChangeEntry $entry): string => $entry->stagedState()->name, $this->entries));

        return match ($states) {
            [StagedState::Full->name] => true,
            [StagedState::None->name] => false,
            default => null,
        };
    }

    /**
     * The header box: stage everything, or — when everything is staged —
     * unstage it. A mix stages, as a browser's indeterminate box checks.
     */
    private function stageAll(): void
    {
        if ([] !== $this->entries) {
            $this->events?->dispatch(new GitStageAllRequested(true !== $this->allStaged()));
        }
    }

    private function entryRow(int $index, int $width): string
    {
        $entry = $this->entries[$index];
        // Lit, the box is untinted: the underline's close would cut the tint.
        $lit = $index === $this->hoveredBox;
        $checkbox = $this->litBox($entry->stagedState()->checkbox($lit || $this->list->isSelected($index) ? null : $this->styles), $index);
        $status = $entry->displayStatus();
        $statusText = \array_key_exists($status, self::STATUS_ROLE) ? $this->styles->of(self::STATUS_ROLE[$status])->apply($status) : $status;
        $label = $entry->path.(null !== $entry->renamedFrom ? ' '.Glyphs::RENAME_FROM.' '.$entry->renamedFrom : '');

        $selected = $this->list->isSelected($index);

        return $this->shadeRow($this->barred(AnsiText::truncate(' '.$checkbox.' '.$statusText.' '.$label, $width, pad: true), $selected), $selected, $index === $this->hoveredIndex);
    }

    /**
     * The armed discard, as a browser would ask it: the question, then a
     * destructive button — double-bordered, so "this destroys" reads without
     * colour — beside the one that keeps. y still discards and any other key
     * still keeps; the buttons are the same answer for the pointer.
     *
     * @return list<string>
     */
    private function confirmBlock(ChangeEntry $entry, int $width, int $top): array
    {
        $discard = $this->translator->trans('Discard (y)');
        $keep = $this->translator->trans('Keep');
        $discardWidth = FieldBox::controlWidth($discard);
        $keepWidth = FieldBox::controlWidth($keep);
        $rows = FieldBox::rowsFor($this->density->current());
        $discardRows = FieldBox::button($discard, FieldState::Danger, false, $discardWidth, $rows, $this->styles);
        $keepRows = FieldBox::button($keep, FieldState::Idle, false, $keepWidth, $rows, $this->styles);
        $this->confirmButtons = [
            'rows' => [$top + 1, $top + 1 + $rows],
            'discard' => [1, 1 + $discardWidth],
            'keep' => [2 + $discardWidth, 2 + $discardWidth + $keepWidth],
        ];

        $block = [AnsiText::truncate(' '.$this->styles->of(Role::Bright)->apply($this->translator->trans('Discard changes to %path%?', ['%path%' => $entry->path])), $width, pad: true)];
        foreach ($discardRows as $i => $row) {
            $block[] = AnsiText::truncate(' '.$row.' '.$keepRows[$i], $width, pad: true, ellipsis: '');
        }

        return $block;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'toggle_stage' => [Key::SPACE],
            'stage_all' => ['a'],
            'discard' => ['d', Key::DELETE],
            'show_history' => ['2'],
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
