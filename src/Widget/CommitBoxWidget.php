<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Command\CommitMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\MultilineInput;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The commit box under the changes list (GitHub Desktop's bottom-left):
 * a single-line summary, a multi-line description and a [Commit] button.
 * Commit is enabled when the summary is non-empty and anything is staged
 * (via the injected predicate); Ctrl+Enter or a button click commits.
 */
final class CommitBoxWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, HoverableList, TooltipProvider
{
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use PaneNavigationCallbacks;
    use StylesDefault;

    /** The description box's text rows; the box adds its two border rows. */
    private const int DESCRIPTION_ROWS = 2;

    /**
     * The summary, amend checkbox and button's height in the last render: a
     * box each, or one row on a compact screen ({@see FieldBox::rowsFor()}).
     * Boxed on a short terminal, the form left the changes list above it two
     * entries at 20 rows. The description stays framed: it is several rows
     * whatever the screen.
     */
    private int $controlRows = FieldBox::ROWS;

    /** The width the last render drew at — the button's, and so its tooltip's. */
    private int $drawnWidth = 0;

    private readonly ScreenDensity $density;

    /** The amend checkbox as a Tab stop, after the two fields. */
    private const int AMEND_FIELD = 2;

    private readonly TextField $summary;
    private readonly MultilineInput $description;
    private int $activeField = 0; // 0 = summary, 1 = description, 2 = the amend checkbox
    private CommitMode $amend = CommitMode::Fresh;

    /** Whether there is a commit to amend: a branch with none shows the checkbox disabled. */
    private bool $amendable = true;

    /** @var (callable(): bool)|null anything staged to commit? */
    private $stagedChangesProvider;
    /** @var (callable(string, CommitMode): void)|null commit with the assembled message (+ amend mode) */
    private $onCommit;
    /** @var (callable(CommitMode): void)|null amend toggled: the controller prefills/clears the message */
    private $onAmendToggle;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?ScreenDensity $density = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->density = $density ?? new ScreenDensity();
        $this->summary = new TextField(style: $cursorStyle);
        $this->description = new MultilineInput($cursorStyle);
    }

    /** @param callable(): bool $predicate */
    public function setStagedChangesProvider(callable $predicate): self
    {
        $this->stagedChangesProvider = $predicate;

        return $this;
    }

    /** @param callable(string, CommitMode): void $callback */
    public function onCommit(callable $callback): self
    {
        $this->onCommit = $callback;

        return $this;
    }

    /** @param callable(CommitMode): void $callback */
    public function onAmendToggle(callable $callback): self
    {
        $this->onAmendToggle = $callback;

        return $this;
    }

    /** A branch with no commit yet has nothing to amend: the checkbox is shown disabled, and no key or click reaches it. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain: whether the branch has a commit.
    public function setAmendable(bool $amendable): void
    {
        if ($amendable === $this->amendable) {
            return;
        }
        $this->amendable = $amendable;
        if (!$amendable && self::AMEND_FIELD === $this->activeField) {
            $this->activeField = 0;
        }
        $this->invalidate();
    }

    public function isAmend(): bool
    {
        return CommitMode::Amend === $this->amend;
    }

    /** Prefill the fields from an existing message (amend), caret at the end. */
    public function setMessage(string $message): void
    {
        $parts = \explode("\n\n", $message, limit: 2);
        $description = $parts[1] ?? '';
        $this->summary->setValue(\str_replace("\n", replace: ' ', subject: $parts[0]));
        '' === $description ? $this->description->clear() : $this->description->setValue($description);
        $this->invalidate();
    }

    public function clear(): void
    {
        $this->summary->clear();
        $this->description->clear();
        $this->activeField = 0;
        $this->amend = CommitMode::Fresh;
        $this->invalidate();
    }

    /** Focus lands on the summary field when the pane is entered. */
    public function resetField(): void
    {
        $this->activeField = 0;
        $this->invalidate();
    }

    public function commitEnabled(): bool
    {
        if ('' === $this->summary->value()) {
            return false;
        }

        // Amending only the message needs no staged changes.
        return CommitMode::Amend === $this->amend || null !== $this->stagedChangesProvider && ($this->stagedChangesProvider)();
    }

    /** The assembled commit message: summary, blank line, description. */
    public function message(): string
    {
        $description = $this->description->value();

        return '' === $description ? $this->summary->value() : $this->summary->value()."\n\n".$description;
    }

    /** Fire the commit (keyboard or button click); no-op while disabled. */
    public function submit(): void
    {
        if ($this->commitEnabled() && null !== $this->onCommit) {
            ($this->onCommit)($this->message(), $this->amend);
        }
    }

    /**
     * The pointer over the amend checkbox's row, or over the commit button
     * while it can commit — a disabled button is not actionable, so it never
     * reacts.
     */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered(match (true) {
            $this->onAmend($relRow) && $this->amendable => $this->amendTop(),
            $this->onButton($relRow) && $this->commitEnabled() => $this->buttonRow(),
            default => null,
        });
    }

    /** The widget-relative first row of the commit button, for mouse hit-testing. */
    public function buttonRow(): int
    {
        return $this->amendTop() + $this->controlRows;
    }

    /**
     * A dashed Commit button says why it will not commit — the shape says
     * "not now", only words say what is missing. An enabled one needs no card.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        if (!$this->onButton($row) || $this->commitEnabled()) {
            return null;
        }
        $why = '' === $this->summary->value() ? $this->translator->trans('Write a summary first') : $this->translator->trans('Stage a change first');

        return new Tooltip('git:commit', $why, $this->buttonRow(), 0, $this->drawnWidth, $this->controlRows);
    }

    /** The description box's first row: below the header and the summary. */
    private function descriptionTop(): int
    {
        return 1 + $this->controlRows;
    }

    /** The amend checkbox's first row: below the description box and its two border rows. */
    private function amendTop(): int
    {
        return $this->descriptionTop() + self::DESCRIPTION_ROWS + 2;
    }

    /** Any row of the amend checkbox — its label rides the box's middle row, and every row is its target. */
    private function onAmend(int $relRow): bool
    {
        return $relRow >= $this->amendTop() && $relRow < ($this->amendTop() + $this->controlRows);
    }

    private function onButton(int $relRow): bool
    {
        return $relRow >= $this->buttonRow() && $relRow < ($this->buttonRow() + $this->controlRows);
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'commit')) {
            $this->submit();

            return;
        }
        if ($kb->matches($data, 'toggle_amend')) {
            $this->toggleAmend();

            return;
        }
        if ($kb->matches($data, 'next_field')) {
            // Summary, description, then the checkbox — skipped while it is
            // disabled, the way a browser's Tab passes a disabled control.
            $last = $this->amendable ? self::AMEND_FIELD : 1;
            if ($this->activeField < $last) {
                ++$this->activeField;
                $this->invalidate();

                return;
            }
            if (null !== $this->onFocusNext) {
                $this->activeField = 0;
                ($this->onFocusNext)();
            }

            return;
        }
        // Shift+Tab walks back — amend, description, summary — and stops at
        // the summary: no pane hook names the one before this box.
        if ($kb->matches($data, 'prev_field')) {
            if ($this->activeField > 0) {
                --$this->activeField;
                $this->invalidate();
            }

            return;
        }
        if (self::AMEND_FIELD === $this->activeField) {
            if ($kb->matches($data, 'toggle_checkbox')) {
                $this->toggleAmend();
            }

            return; // a checkbox takes no text
        }

        if (0 === $this->activeField) {
            if ($kb->matches($data, 'delete_char_backward')) {
                if ($this->summary->backspace()) {
                    $this->invalidate();
                }

                return;
            }
            if ($this->summary->editKey($data, $kb) || $this->summary->insert($data)) {
                $this->invalidate();
            }

            return;
        }

        if ($this->description->handleKey($data, $kb)) {
            $this->invalidate();
        }
    }

    /**
     * A click anywhere on the button commits; anywhere on the amend row —
     * its label as well as its box, like an HTML <label> — toggles amend;
     * the field boxes move the caret between them.
     */
    public function handleClick(int $relRow): void
    {
        if ($this->onButton($relRow)) {
            $this->submit();

            return;
        }
        if ($this->onAmend($relRow)) {
            if ($this->amendable) {
                $this->activeField = self::AMEND_FIELD; // a clicked checkbox takes the focus, as in a browser
            }
            $this->toggleAmend();

            return;
        }
        $this->activeField = $relRow >= $this->descriptionTop() ? 1 : 0;
        $this->invalidate();
    }

    private function toggleAmend(): void
    {
        if (!$this->amendable) {
            return;
        }
        $this->amend = CommitMode::Amend === $this->amend ? CommitMode::Fresh : CommitMode::Amend;
        if (null !== $this->onAmendToggle) {
            ($this->onAmendToggle)($this->amend);
        }
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $this->drawnWidth = $width;
        $focused = $this->focused;
        $this->controlRows = FieldBox::rowsFor($this->density->current());

        $lines = [HeaderRenderer::title($this->translator->trans('Commit'), $width, $this->styles, suffix: 'Esc')];

        // Two boxed fields whose legends name them — always on screen, so
        // what each box is for no longer vanishes the moment it takes focus.
        // The border shape says which one is taking the keys.
        $typingSummary = $focused && 0 === $this->activeField;
        \array_push($lines, ...FieldBox::field(
            new FieldSpec($this->translator->trans('Summary (required)')),
            $this->summary,
            FieldState::of($typingSummary),
            $typingSummary ? CaretVisibility::Shown : CaretVisibility::Hidden,
            $width,
            $this->controlRows,
            $this->styles,
        ));

        $typingDescription = $focused && 1 === $this->activeField;
        $descriptionLines = $this->description->render(\max(1, $width - FieldBox::CHROME_COLS), self::DESCRIPTION_ROWS, cursor: $typingDescription ? CaretVisibility::Shown : CaretVisibility::Hidden);
        \array_push($lines, ...FieldBox::frame(
            new FieldSpec($this->translator->trans('Description')),
            \array_pad(\array_slice($descriptionLines, offset: 0, length: self::DESCRIPTION_ROWS), self::DESCRIPTION_ROWS, value: ''),
            FieldState::of($typingDescription),
            $width,
            $this->styles,
        ));
        // A checkbox with its label, the way a form offers an option: the
        // state reads by glyph without colour, and the whole row is its hit
        // target. It used to be a checkbox glued to the header, reachable
        // only by Alt+M; it is the form's third Tab stop now, a heavy box
        // while Space would toggle it, and dashed on a branch with no commit.
        $amend = CommitMode::Amend === $this->amend;
        $state = $this->amendable ? FieldState::of($focused && self::AMEND_FIELD === $this->activeField) : FieldState::Disabled;
        // A box of its own, three rows like the fields above it, its label on
        // the middle row. The pointer underlines the label, never the box —
        // hovering is not a state change — and the mark keeps its accent.
        $hovered = $this->amendTop() === $this->hoveredIndex;
        $text = $this->translator->trans('Amend last commit');
        $caption = match (true) {
            !$this->amendable => $this->styles->of(Role::Faint)->apply($text.'  '.$this->translator->trans('No commit to amend')),
            $hovered => $this->styles->of(Role::Bright)->withUnderline()->apply($text).$this->hint(),
            default => $text.$this->hint(),
        };
        $box = FieldBox::ROWS === $this->controlRows ? FieldBox::checkboxBox($amend, $state, $this->styles) : [FieldBox::checkbox($amend, $state, $this->styles)];
        $labelRow = \intdiv(\count($box), num2: 2);
        foreach ($box as $i => $boxRow) {
            $lines[] = AnsiText::truncate(' '.$boxRow.($labelRow === $i ? ' '.$caption : ''), $width, pad: true);
        }

        // The form's submit button, its key in its border: dashed while
        // there is nothing to commit, so "not now" reads without colour.
        \array_push($lines, ...FieldBox::button(
            $amend ? $this->translator->trans('Amend last commit') : $this->translator->trans('Commit to branch'),
            $this->commitEnabled() ? FieldState::Idle : FieldState::Disabled,
            $this->hoveredIndex === $this->buttonRow(),
            $width,
            $this->controlRows,
            $this->styles,
            trailing: 'Ctrl+Enter',
        ));

        return $lines;
    }

    private function hint(): string
    {
        return '  '.$this->styles->of(Role::Faint)->apply('Alt+M');
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'commit' => ['ctrl+enter'],
            'toggle_amend' => ['alt+m'],
            'toggle_checkbox' => [Key::SPACE],
            'next_field' => [Key::TAB],
            'prev_field' => ['shift+tab'],
            'delete_char_backward' => [Key::BACKSPACE],
            'exit_view' => [Key::ESCAPE],
        ];
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
