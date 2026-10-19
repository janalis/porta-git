<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitToolbarMenuRequested;
use Porta\GitPlugin\RepositoryState;
use Porta\GitPlugin\Support\RelativeTime;
use Porta\GitPlugin\View\ToolbarCell;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\ClickZones;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * GitHub Desktop's toolbar, across the top of the git view: the repository,
 * the branch with its pull request, and the remote button — Fetch, or Pull,
 * Push or Publish when there is something to move, with when it last fetched.
 *
 * Roomy, each cell is a small label over its value and the row is ruled off
 * below; compact, the three values share one bar row. A press on the first two
 * drops their menu ({@see GitToolbarMenuRequested}); on the third, it runs the
 * operation the cell names.
 */
final class GitToolbarWidget extends AbstractWidget implements MenuOverlayHost, TooltipProvider
{
    use MenuOverlayHostTrait;
    use StylesDefault;

    // Checked narrow: each is East Asian Neutral and has no emoji presentation.
    private const string REPOSITORY = '⌂';
    private const string BRANCH = '⑂';
    private const string REMOTE = '⟳';
    private const string AHEAD = '⇡';
    private const string BEHIND = '⇣';

    /** A roomy cell's columns: a third of the row, within these. */
    private const int CELL_MIN = 22;
    private const int CELL_MAX = 40;

    private readonly ClickZones $zones;

    private readonly ScreenDensity $density;

    private readonly RelativeTime $time;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** The cell under the pointer; visual only. */
    private ?ToolbarCell $hovered = null;

    /** How many rows the last render drew — where a dropped menu hangs from. */
    private int $drawnRows = 1;

    /** @param (\Closure(): int)|null $clock now, as a Unix timestamp; the real clock by default */
    public function __construct(
        private readonly Translator $translator,
        private readonly RepositoryState $repository,
        ?Styles $styles = null,
        ?ScreenDensity $density = null,
        private readonly ?EventDispatcherInterface $events = null,
        ?\Closure $clock = null,
    ) {
        $this->initStyles($styles);
        $this->density = $density ?? new ScreenDensity();
        $this->time = new RelativeTime($translator);
        $this->clock = $clock ?? \time(...);
        $this->zones = new ClickZones();
    }

    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $this->zones->clear();
        $lines = Density::Roomy === $this->density->current() ? $this->roomy($width) : [$this->compact($width)];
        $this->drawnRows = \count($lines);

        return $lines;
    }

    /** The rows the last render drew. */
    public function drawnRows(): int
    {
        return $this->drawnRows;
    }

    /**
     * A cell's columns [start, end) from the last render, or null when it was not drawn.
     *
     * @return array{int, int}|null
     */
    public function span(ToolbarCell $cell): ?array
    {
        return $this->zones->span($cell->name);
    }

    /** A press: the remote cell runs its operation, the other two drop their menu. */
    public function handleClick(int $relCol): void
    {
        $cell = $this->cellAt($relCol);
        if (null === $cell) {
            return;
        }
        $this->events?->dispatch(ToolbarCell::Remote === $cell ? new GitRemoteRequested($this->operation()) : new GitToolbarMenuRequested($cell));
    }

    /** Tint the cell under the pointer; returns whether that changed. */
    public function hoverAt(?int $relCol): bool
    {
        $cell = null === $relCol ? null : $this->cellAt($relCol);
        if ($cell === $this->hovered) {
            return false;
        }
        $this->hovered = $cell;
        $this->invalidate();

        return true;
    }

    private function cellAt(int $relCol): ?ToolbarCell
    {
        $name = $this->zones->at($relCol);

        return null === $name ? null : \array_find(ToolbarCell::cases(), static fn (ToolbarCell $cell): bool => $cell->name === $name);
    }

    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $cell = $row < $this->drawnRows ? $this->cellAt($col) : null;
        $span = null === $cell ? null : $this->span($cell);
        if (null === $cell || null === $span) {
            return null;
        }
        $text = match ($cell) {
            ToolbarCell::Repository => $this->translator->trans('Repository %name% — click for its worktrees', ['%name%' => $this->repository->name()]),
            ToolbarCell::Branch => $this->branchTip(),
            ToolbarCell::Remote => $this->remoteTip(),
        };

        return new Tooltip('git:toolbar:'.$cell->name, $text, $row, $span[0], $span[1]);
    }

    /** What the remote button does now: pull what is behind first, then push what is ahead, else fetch. */
    private function operation(): GitRemoteOperation
    {
        $tracking = $this->repository->tracking();

        if (null === $tracking) {
            // An unpublished branch is pushed — git then offers to set its
            // upstream; a detached HEAD has nothing to push, only to fetch.
            return null === $this->repository->branch() ? GitRemoteOperation::Fetch : GitRemoteOperation::Push;
        }

        return match (true) {
            $tracking->behind > 0 => GitRemoteOperation::Pull,
            $tracking->ahead > 0 => GitRemoteOperation::Push,
            default => GitRemoteOperation::Fetch,
        };
    }

    private function remoteLabel(): string
    {
        $published = null !== $this->repository->tracking() || null === $this->repository->branch();

        return $this->translator->trans(match (true) {
            !$published => 'Publish branch',
            GitRemoteOperation::Pull === $this->operation() => 'Pull',
            GitRemoteOperation::Push === $this->operation() => 'Push',
            default => 'Fetch',
        });
    }

    /** "⇣2 ⇡1": what the remote button would move, or nothing. */
    private function counts(): string
    {
        $tracking = $this->repository->tracking();
        if (null === $tracking) {
            return '';
        }
        $counts = \array_filter([
            $tracking->behind > 0 ? self::BEHIND.$tracking->behind : '',
            $tracking->ahead > 0 ? self::AHEAD.$tracking->ahead : '',
        ]);

        return \implode(' ', $counts);
    }

    private function fetched(): string
    {
        $when = $this->repository->lastFetched();

        return (
            null === $when
                ? $this->translator->trans('Never fetched')
                : $this->translator->trans('Last fetched %when%', ['%when%' => $this->time->long($when, ($this->clock)())])
        );
    }

    /** Empty until git has answered: an unread branch is not a detached one. */
    private function branchName(): string
    {
        $branch = $this->repository->branch();
        if (null !== $branch) {
            return AnsiText::oneLine($branch);
        }

        return $this->repository->branchRead() ? $this->translator->trans('Detached HEAD') : '';
    }

    private function pullRequest(): string
    {
        $number = $this->repository->pullRequest();

        return null === $number ? '' : $this->styles->of(Role::TabAccent)->apply('#'.$number);
    }

    /**
     * Three cells, a third of the row each up to a width, then the rest of
     * the bar; the label row, the value row and the rule under both.
     *
     * @return list<string>
     */
    private function roomy(int $width): array
    {
        $cell = \min(self::CELL_MAX, \max(self::CELL_MIN, \intdiv($width, num2: 3) - 1));
        if ((3 * ($cell + 1)) > $width) {
            $cell = \max(1, \intdiv($width, num2: 3) - 1);
        }
        $dim = $this->styles->of(Role::Dim);
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $rule = $this->styles->of(Role::Rule);
        $cells = [
            [ToolbarCell::Repository, self::REPOSITORY.' '.$dim->apply($this->translator->trans('Current repository')), Glyphs::SELECT_ARROW, '  '.$bold->apply($this->repository->name()), ''],
            [ToolbarCell::Branch, self::BRANCH.' '.$dim->apply($this->translator->trans('Current branch')), Glyphs::SELECT_ARROW, '  '.$bold->apply($this->branchName()), $this->pullRequest()],
            [ToolbarCell::Remote, self::REMOTE.' '.$bold->apply($this->remoteLabel()), $this->counts(), '  '.$dim->apply($this->fetched()), ''],
        ];
        $top = '';
        $middle = '';
        $col = 0;
        foreach ($cells as [$name, $label, $marker, $value, $badge]) {
            $this->zones->add($name->name, $col, $col + $cell);
            $hover = $name === $this->hovered ? $this->styles->of(Role::BarHoverBg) : null;
            $top .= $this->shade(self::seat(' '.$label, $marker, $cell), $hover).$rule->apply(Glyphs::SEPARATOR);
            $middle .= $this->shade(self::seat(' '.$value, $badge, $cell), $hover).$rule->apply(Glyphs::SEPARATOR);
            $col += $cell + 1;
        }
        $tail = \max(0, $width - $col);
        $bottom = '';
        for ($i = 0; $i < \min($col, $width); ++$i) {
            $bottom .= $cell === ($i % ($cell + 1)) ? Glyphs::RULE_UP : Glyphs::FRAME_HORIZONTAL;
        }

        return [
            AnsiText::truncate($top.AnsiText::blank($tail), $width, pad: true),
            AnsiText::truncate($middle.AnsiText::blank($tail), $width, pad: true),
            $rule->apply(\mb_substr($bottom.\str_repeat(Glyphs::FRAME_HORIZONTAL, $tail), start: 0, length: $width)),
        ];
    }

    /**
     * One bar row: each cell's value, its badge and its arrow, sized to what
     * it says. Past a third of the row a cell gives up the end of its VALUE —
     * never the arrow that says it opens a menu, nor the badge.
     */
    private function compact(int $width): string
    {
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $when = $this->repository->lastFetched();
        $badge = $this->pullRequest();
        $cells = [
            [ToolbarCell::Repository, ' '.self::REPOSITORY.' ', $bold->apply($this->repository->name()), ' '.Glyphs::SELECT_ARROW.' '],
            [ToolbarCell::Branch, ' '.self::BRANCH.' ', $bold->apply($this->branchName()), ('' === $badge ? '' : ' '.$badge).' '.Glyphs::SELECT_ARROW.' '],
            [
                ToolbarCell::Remote,
                // What the button does and what it moves stay; the time gives way first.
                ' '.self::REMOTE.' '.$bold->apply($this->remoteLabel()).('' === $this->counts() ? '' : ' '.$this->counts()).(null === $when ? '' : ' · '),
                null === $when ? '' : $this->time->short($when, ($this->clock)()),
                ' ',
            ],
        ];
        $room = \max(1, \intdiv($width, num2: 3) - 1);
        $row = '';
        $col = 0;
        $separator = $this->styles->of(Role::Rule)->apply(Glyphs::SEPARATOR);
        foreach ($cells as [$name, $head, $value, $tail]) {
            $valueRoom = \max(1, $room - AnsiUtils::visibleWidth($head) - AnsiUtils::visibleWidth($tail));
            $text = $head.(AnsiUtils::visibleWidth($value) > $valueRoom ? AnsiText::truncate($value, $valueRoom) : $value).$tail;
            $cellWidth = AnsiUtils::visibleWidth($text);
            $this->zones->add($name->name, $col, $col + $cellWidth);
            $row .= $this->shade($text, $name === $this->hovered ? $this->styles->of(Role::BarHoverBg) : null).$separator;
            $col += $cellWidth + 1;
        }

        return $this->styles->of(Role::HeaderBar)->apply(AnsiText::truncate($row, $width, pad: true));
    }

    private function shade(string $text, ?Style $hover): string
    {
        return $hover?->apply($text) ?? $text;
    }

    /** $left, then $right flush against the cell's end, in exactly $width columns. */
    private static function seat(string $left, string $right, int $width): string
    {
        $tail = '' === $right ? '' : ' '.$right.' ';
        $room = \max(0, $width - AnsiUtils::visibleWidth($tail));

        return AnsiText::truncate(AnsiText::truncate($left, $room, pad: true).$tail, $width, pad: true);
    }

    private function branchTip(): string
    {
        $tracking = $this->repository->tracking();
        $state = null === $tracking
            ? $this->translator->trans('no upstream branch')
            : $this->translator->trans('%ahead% ahead, %behind% behind its upstream', ['%ahead%' => (string) $tracking->ahead, '%behind%' => (string) $tracking->behind]);
        $number = $this->repository->pullRequest();

        return (
            $this->translator->trans('Branch %branch% — %state%', ['%branch%' => $this->branchName(), '%state%' => $state])
            .(null === $number ? '' : "\n".$this->translator->trans('Pull request #%number%', ['%number%' => (string) $number]))
            ."\n"
            .$this->translator->trans('Click to switch, create or delete a branch')
        );
    }

    private function remoteTip(): string
    {
        return (
            match ($this->operation()) {
                GitRemoteOperation::Pull => $this->translator->trans('Pull the commits the upstream has and this branch does not'),
                GitRemoteOperation::Push => null === $this->repository->tracking()
                    ? $this->translator->trans('Publish this branch: push it and track it upstream')
                    : $this->translator->trans('Push the commits this branch has and the upstream does not'),
                GitRemoteOperation::Fetch => $this->translator->trans('Fetch: ask the remote what changed, without touching this branch'),
            }
            ."\n"
            .$this->fetched()
        );
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return \max(1, $context->getColumns());
    }
}
