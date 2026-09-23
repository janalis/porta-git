<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
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
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\PanelWidth;
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
 * The git view's changed-files list (GitHub Desktop's left column): one row
 * per {@see ChangeEntry} as `[✓] M path`, where the checkbox mirrors the
 * staged state. Space toggles staging, `d` arms an inline discard
 * confirmation, Enter/selection drives the diff pane via onSelect.
 */
final class ChangesListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
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
    private int $lastRows = 10;
    /** Index of the entry an inline discard confirmation is armed for, or null. */
    private ?int $confirmDiscard = null;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->events = $events;
        $this->width = $width;
        $this->initStyles($styles);
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
        // Minus the title row, matching render()'s follow() budget.
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->entries), page: $this->lastRows - 1)) {
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
        if ($kb->matches($data, 'show_history')) {
            $this->events?->dispatch(new GitViewModeRequested(GitViewMode::History));

            return;
        }
        if ($kb->matches($data, 'discard')) {
            if (null !== $this->selectedEntry()) {
                $this->confirmDiscard = $this->list->selected();
                $this->invalidate();
            }
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

    /** The selected entry's widget-relative row (row 0 is the header). */
    public function selectedRow(): int
    {
        return $this->list->selected() - $this->list->scroll() + 1;
    }

    /** Left-click: the checkbox column toggles staging, anywhere else selects. */
    public function handleClick(int $relRow, int $relCol): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $entry = $this->entries[$index];
        if ($relCol <= 3) {
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

    private function rowIndexAt(int $relRow): ?int
    {
        return $this->list->rowIndexAt($relRow, \count($this->entries));
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
        $this->lastRows = $rows;

        $count = \count($this->entries);
        $title = $this->translator->trans(' %count% changed file| %count% changed files', ['%count%' => $count]);
        $lines = [HeaderRenderer::title($title, $width, $this->styles)];

        $scroll = $this->list->follow($rows - 1);
        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $entry = $this->entries[$index] ?? null;
            if (null === $entry) {
                $lines[] = AnsiText::blank($width);
                continue;
            }
            if ($index === $this->confirmDiscard) {
                $confirm = $this->translator->trans(' Discard changes? y = yes · any key = no');
                $lines[] = $this->styles->merge(Role::DangerBg, Role::Bright)->apply(AnsiText::truncate($confirm, $width, pad: true));
                continue;
            }

            $checkbox = $entry->stagedState()->checkbox();
            $status = $entry->displayStatus();
            $statusText = \array_key_exists($status, self::STATUS_ROLE) ? $this->styles->of(self::STATUS_ROLE[$status])->apply($status) : $status;
            $label = $entry->path.(null !== $entry->renamedFrom ? ' '.Glyphs::RENAME_FROM.' '.$entry->renamedFrom : '');

            $text = AnsiText::truncate(' '.$checkbox.' '.$statusText.' '.$label, $width, pad: true);
            $lines[] = $this->shadeRow($text, $this->list->isSelected($index), $index === $this->hoveredIndex);
        }

        return $lines;
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
