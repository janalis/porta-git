<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Render\TabStripTail;
use Porta\Widget\Support\PaneFocusProbe;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The [Changes][History] tab strip heading the git sidebar. Not focusable —
 * the '1'/'2' keys in the lists switch tabs too; this is the mouse target.
 * The active tab is set by the controller, and a click on the inactive one
 * dispatches {@see GitViewModeRequested}.
 */
final class GitTabsWidget extends AbstractWidget implements MenuOverlayHost
{
    use MenuOverlayHostTrait;
    use PaneFocusProbe;
    use PanelWidth;
    use StylesDefault;

    /** The menu bar and the status bar: the terminal's rows the sidebar does not get. */
    private const int CHROME_ROWS = 2;

    private GitViewMode $active = GitViewMode::Changes;

    /** The sidebar focus the last render drew, so a focus change repaints the lid. */
    private bool $drawnFocus = false;

    /** The tab under the mouse pointer (visual only), or null. */
    private ?GitViewMode $hoveredMode = null;
    /** @var list<array{mode: GitViewMode, start: int, width: int}> the tabs the last render DREW, for hit-testing — a tab the pane cut off has no zone */
    private array $layout = [];

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
    }

    public function minWidth(): int
    {
        // 30, not the 24 this used to declare: the layout controller clamped
        // to 30 before ever calling setWidth, so 24 was never reachable.
        return 30;
    }

    public function maxRatio(): float
    {
        return 0.6;
    }

    /**
     * Silent on purpose: the controller calls this after ACTING on a mode
     * request, so dispatching here would loop the event that got us here.
     */
    public function setActive(GitViewMode $mode): void
    {
        if ($mode !== $this->active) {
            $this->active = $mode;
            $this->invalidate();
        }
    }

    /** Tint the (inactive) tab under the pointer; null clears. Returns whether it changed. */
    public function hoverMode(?GitViewMode $mode): bool
    {
        if ($mode === $this->hoveredMode) {
            return false;
        }
        $this->hoveredMode = $mode;
        $this->invalidate();

        return true;
    }

    /** The tab under a widget-relative column, from the last render's layout. */
    public function modeAtColumn(int $relCol): ?GitViewMode
    {
        foreach ($this->layout as $tab) {
            if ($relCol >= $tab['start'] && $relCol < ($tab['start'] + $tab['width'])) {
                return $tab['mode'];
            }
        }

        return null;
    }

    /** A click on the inactive tab switches to it; anywhere else is a no-op. */
    public function handleClick(int $relCol): void
    {
        $mode = $this->modeAtColumn($relCol);
        if (null !== $mode && $mode !== $this->active) {
            $this->events?->dispatch(new GitViewModeRequested($mode));
        }
    }

    #[\Override]
    public function beforeRender(): void
    {
        if ($this->paneFocused() !== $this->drawnFocus) {
            $this->invalidate(); // the active lid's weight follows the sidebar's focus
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $modes = GitViewMode::cases();
        // The editor's and the dock's strip, uncloseable: a mode is always
        // there. The hovered mode came from the last render's layout, so its
        // column there is the column the renderer resolves hover from.
        $tabs = \array_map(fn (GitViewMode $mode): array => [
            'label' => \trim($this->translator->trans(match ($mode) {
                GitViewMode::Changes => ' Changes ',
                GitViewMode::History => ' History ',
            })),
            'active' => $mode === $this->active,
            'closable' => false,
        ], $modes);
        $hover = \array_find($this->layout, fn (array $tab): bool => $tab['mode'] === $this->hoveredMode);
        // The core strips take their height from the terminal's; this one is
        // a plugin's and sees only its sidebar, which runs between the menu
        // bar and the status bar — the two rows added back.
        $rows = TabStripRenderer::rowsFor($context->getRows() + self::CHROME_ROWS);
        $strip = TabStripRenderer::render($tabs, $width, $this->styles, $hover['start'] ?? null, rows: $rows, paneFocused: $this->drawnFocus = $this->paneFocused());
        $this->layout = \array_map(static fn (array $zone): array => ['mode' => $modes[$zone['index']], 'start' => $zone['start'], 'width' => $zone['width']], $strip['hitMap']);

        return $this->withMenuOverlay(TabStripTail::pad($strip, $width, $rows > 1, $this->styles), $width);
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
