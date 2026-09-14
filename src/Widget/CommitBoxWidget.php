<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Command\CommitMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\MultilineInput;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
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
final class CommitBoxWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, HoverableList
{
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use PaneNavigationCallbacks;
    use StylesDefault;

    private const int DESCRIPTION_ROWS = 3;

    private readonly TextField $summary;
    private readonly MultilineInput $description;
    private int $activeField = 0; // 0 = summary, 1 = description
    private CommitMode $amend = CommitMode::Fresh;

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
    ) {
        $this->width = $width;
        $this->initStyles($styles);
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

    /** Only the enabled commit button hovers — a disabled button is not actionable. */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($relRow === $this->buttonRow() && $this->commitEnabled() ? $relRow : null);
    }

    /** The widget-relative row of the [Commit] button, for mouse hit-testing. */
    public function buttonRow(): int
    {
        return 2 + self::DESCRIPTION_ROWS + 1;
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
            $this->amend = CommitMode::Amend === $this->amend ? CommitMode::Fresh : CommitMode::Amend;
            if (null !== $this->onAmendToggle) {
                ($this->onAmendToggle)($this->amend);
            }
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'next_field')) {
            if (0 === $this->activeField) {
                $this->activeField = 1;
                $this->invalidate();

                return;
            }
            if (null !== $this->onFocusNext) {
                $this->activeField = 0;
                ($this->onFocusNext)();
            }

            return;
        }

        if (0 === $this->activeField) {
            if ($kb->matches($data, 'delete_char_backward')) {
                if ($this->summary->backspace()) {
                    $this->invalidate();
                }

                return;
            }
            if ($this->summary->insert($data)) {
                $this->invalidate();
            }

            return;
        }

        if ($this->description->handleKey($data)) {
            $this->invalidate();
        }
    }

    /** A click on the button row commits; the field rows move the caret. */
    public function handleClick(int $relRow): void
    {
        if ($relRow === $this->buttonRow()) {
            $this->submit();

            return;
        }
        $this->activeField = $relRow >= 2 && $relRow < (2 + self::DESCRIPTION_ROWS) ? 1 : 0;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = \min($this->width, $context->getColumns());
        $focused = $this->focused;

        $lines = [];
        // One id in the dialect, with the state carried by the toggle
        // vocabulary's checkbox — which reads without colour, so the amend
        // state survives a monochrome terminal. Both glyphs are three columns,
        // so the header does not jump as it flips.
        $header = $this->translator->trans(' Commit  ·  Alt+M amend ').(CommitMode::Amend === $this->amend ? Glyphs::CHECKBOX_ON : Glyphs::CHECKBOX_OFF);
        $lines[] = HeaderRenderer::title($header, $width, $this->styles, suffix: 'Esc');

        $summary = ' '.$this->summary->render(cursor: $focused && 0 === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden, width: $width - 1);
        $summaryHint =
            '' === $this->summary->value() && (!$focused || 0 !== $this->activeField)
                ? $this->styles->of(Role::Dim)->apply(' '.$this->translator->trans('Summary (required)'))
                : $summary;
        $lines[] = $this->styles->of(Role::RowHighlightBg)->apply(AnsiText::truncate($summaryHint, $width, pad: true));

        $descriptionLines = $this->description->render($width - 1, self::DESCRIPTION_ROWS, cursor: $focused && 1 === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden);
        if ($this->description->isEmpty() && (!$focused || 1 !== $this->activeField)) {
            $descriptionLines = [$this->styles->of(Role::Dim)->apply($this->translator->trans('Description'))];
        }
        for ($i = 0; $i < self::DESCRIPTION_ROWS; ++$i) {
            $lines[] = $this->styles->of(Role::Toolbar)->apply(AnsiText::truncate(' '.($descriptionLines[$i] ?? ''), $width, pad: true));
        }

        $lines[] = AnsiText::blank($width);
        $label = CommitMode::Amend === $this->amend ? $this->translator->trans('  Amend last commit  ') : $this->translator->trans('  Commit to branch  ');
        // SelectedRow already carries the bright fg, so the hover cue is an
        // underline — the one attribute that reads on any theme.
        $button = (match (true) {
            !$this->commitEnabled() => $this->styles->merge(Role::RowHighlightBg, Role::Dim),
            $this->hoveredIndex === $this->buttonRow() => $this->styles->of(Role::SelectedRow)->withUnderline(),
            default => $this->styles->of(Role::SelectedRow),
        })->apply(AnsiText::truncate($label, $width, pad: true));
        $lines[] = $button;

        return $this->withMenuOverlay($lines, $width);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'commit' => ['ctrl+enter'],
            'toggle_amend' => ['alt+m'],
            'next_field' => [Key::TAB],
            'delete_char_backward' => [Key::BACKSPACE],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
