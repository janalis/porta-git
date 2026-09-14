<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\View\GitViewMode;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStyle;
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
    use PanelWidth;
    use StylesDefault;

    private GitViewMode $active = GitViewMode::Changes;

    /** The tab under the mouse pointer (visual only), or null. */
    private ?GitViewMode $hoveredMode = null;
    /** @var list<array{mode: GitViewMode, start: int, width: int}> tab layout from the last render, for hit-testing */
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

    public function render(RenderContext $context): array
    {
        $width = \min($this->width, $context->getColumns());

        $this->layout = [];
        $line = ' ';
        $col = 1;
        foreach (GitViewMode::cases() as $mode) {
            $label = $this->translator->trans(match ($mode) {
                GitViewMode::Changes => ' Changes ',
                GitViewMode::History => ' History ',
            });
            $tabWidth = \mb_strwidth($label);
            $this->layout[] = ['mode' => $mode, 'start' => $col, 'width' => $tabWidth];
            $line .= TabStyle::of($this->styles, TabState::of($mode === $this->active, $mode === $this->hoveredMode))->apply($label);
            $col += $tabWidth + 1;
            $line .= ' ';
        }

        return $this->withMenuOverlay([$this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($line, $width, pad: true))], $width);
    }
}
