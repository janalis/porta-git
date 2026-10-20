<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitToolbarMenuRequested;
use Porta\GitPlugin\GitOperation;
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
 * the worktree when there are several, the branch with its pull request, and
 * the remote button — Fetch, or Pull, Push or Publish when there is something
 * to move, with when it last fetched.
 *
 * Roomy, each cell is a small label over its value and the row is ruled off
 * below; compact, the values share one bar row. Either way the cells stand
 * over the panes under them: the first is as wide as the sidebar, the next as
 * the column beside it ({@see $columns}), so each `│` meets a divider. A press
 * on a menu cell drops its menu ({@see GitToolbarMenuRequested}); on the
 * remote cell, it runs the operation the cell names — whose icon turns while
 * it runs ({@see spin()}).
 */
final class GitToolbarWidget extends AbstractWidget implements MenuOverlayHost, TooltipProvider
{
    use MenuOverlayHostTrait;
    use StylesDefault;

    // Checked narrow: each is East Asian Neutral and has no emoji presentation.
    private const string REPOSITORY = '⌂';
    private const string WORKTREE = '◫';
    private const string BRANCH = '⑂';
    private const string REMOTE = '↻';
    private const string AHEAD = '⇡';
    private const string BEHIND = '⇣';

    /** The remote icon while an operation runs: a quarter arc, turning clockwise. */
    private const array SPIN = ['◜', '◝', '◞', '◟'];

    /** A cell not standing over a pane takes its share of the rest, within these. */
    private const int CELL_MIN = 22;
    private const int CELL_MAX = 40;

    /** Narrower than this, a cell beside the sidebar's says too little: the cells share the row. */
    private const int CELL_TIGHT = 14;

    private readonly ClickZones $zones;

    private readonly ScreenDensity $density;

    private readonly RelativeTime $time;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** The cell under the pointer; visual only. */
    private ?ToolbarCell $hovered = null;

    /** How many rows the last render drew — where a dropped menu hangs from. */
    private int $drawnRows = 1;

    /** The remote operation running, while its icon turns; null at rest. */
    private ?GitRemoteOperation $running = null;

    private int $frame = 0;

    /**
     * The widths of the panes under a row so wide, left to right; without,
     * the cells share the row.
     *
     * @var (\Closure(int): list<int>)|null
     */
    private ?\Closure $columns = null;

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

    /**
     * Stand the cells over the panes under the toolbar. The view says how
     * wide they are, from what it knows BEFORE the frame lays them out — the
     * toolbar renders first, so a rect read off the panes would be a frame
     * stale during a drag.
     *
     * @param \Closure(int): list<int> $columns the panes' widths under a row so wide, left to right
     */
    public function followPanes(\Closure $columns): void
    {
        $this->columns = $columns;
        $this->invalidate();
    }

    /** The rows the last render drew. */
    public function drawnRows(): int
    {
        return $this->drawnRows;
    }

    /**
     * Turn the remote icon one step on, and say which operation runs. The
     * view calls it on a timer while one does; {@see settle()} stops it.
     */
    public function spin(GitRemoteOperation $operation): void
    {
        $this->frame = null === $this->running ? 0 : ($this->frame + 1) % \count(self::SPIN);
        $this->running = $operation;
        $this->invalidate();
    }

    /** The operation is over: the icon comes back to rest. */
    public function settle(): void
    {
        if (null === $this->running) {
            return;
        }
        $this->running = null;
        $this->invalidate();
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

    /** A press: the remote cell runs its operation, a menu cell drops its menu. */
    public function handleClick(int $relCol): void
    {
        $cell = $this->cellAt($relCol);
        if (null === $cell || !$this->opens($cell)) {
            return;
        }
        $this->events?->dispatch(ToolbarCell::Remote === $cell ? new GitRemoteRequested($this->operation()) : new GitToolbarMenuRequested($cell));
    }

    /** Whether a press on $cell does anything: the repository is a plain label once the worktree cell takes its menu. */
    private function opens(ToolbarCell $cell): bool
    {
        return ToolbarCell::Repository !== $cell || !$this->repository->hasWorktrees();
    }

    /** Tint the cell under the pointer; returns whether that changed. */
    public function hoverAt(?int $relCol): bool
    {
        $cell = null === $relCol ? null : $this->cellAt($relCol);
        $cell = null !== $cell && $this->opens($cell) ? $cell : null;
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
            ToolbarCell::Repository => $this->repository->hasWorktrees()
                ? $this->translator->trans('Repository %name%', ['%name%' => $this->repository->name()])
                : $this->translator->trans('Repository %name% — click for its worktrees', ['%name%' => $this->repository->name()]),
            ToolbarCell::Worktree => $this->translator->trans('Worktree %name% — click to switch or add one', ['%name%' => $this->repository->worktree()]),
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

    /** "Pull origin", as GitHub Desktop says it — "Pulling origin…" while it runs. */
    private function remoteLabel(): string
    {
        $published = null !== $this->repository->tracking() || null === $this->repository->branch();
        $remote = $this->repository->remote();
        $named = null === $remote ? [] : ['%remote%' => AnsiText::oneLine($remote)];
        $operation = $this->running ?? $this->operation();

        return match (true) {
            null !== $this->running => $this->translator->trans(match ($operation) {
                GitRemoteOperation::Fetch => [] === $named ? 'Fetching…' : 'Fetching %remote%…',
                GitRemoteOperation::Pull => [] === $named ? 'Pulling…' : 'Pulling %remote%…',
                GitRemoteOperation::Push => [] === $named ? 'Pushing…' : 'Pushing %remote%…',
            }, $named),
            !$published => $this->translator->trans('Publish branch'),
            default => $this->translator->trans(match ($operation) {
                GitRemoteOperation::Fetch => [] === $named ? 'Fetch' : 'Fetch %remote%',
                GitRemoteOperation::Pull => [] === $named ? 'Pull' : 'Pull %remote%',
                GitRemoteOperation::Push => [] === $named ? 'Push' : 'Push %remote%',
            }, $named),
        };
    }

    private function remoteIcon(): string
    {
        return null === $this->running ? self::REMOTE : self::SPIN[$this->frame];
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

    /** Empty until git has answered: an unread branch is not a detached one. A merge or rebase in progress follows it. */
    private function branchName(): string
    {
        $branch = $this->repository->branch();
        $name = match (true) {
            null !== $branch => AnsiText::oneLine($branch),
            $this->repository->branchRead() => $this->translator->trans('Detached HEAD'),
            default => '',
        };
        $state = $this->repository->operation();
        if (null === $state) {
            return $name;
        }
        $progress =
            $this->translator->trans(match ($state->operation) {
                GitOperation::Merging => 'merging',
                GitOperation::Rebasing => 'rebasing',
                GitOperation::CherryPicking => 'cherry-picking',
                GitOperation::Reverting => 'reverting',
            }).(null === $state->step || null === $state->total ? '' : " {$state->step}/{$state->total}");

        return \trim($name.' — '.$this->styles->of(Role::Modified)->apply($progress), characters: ' —');
    }

    private function pullRequest(): string
    {
        $number = $this->repository->pullRequest();

        return null === $number ? '' : $this->styles->of(Role::TabAccent)->apply('#'.$number);
    }

    /**
     * The cells drawn, left to right: the worktree's only once there are
     * several to choose from.
     *
     * @return list<ToolbarCell>
     */
    private function cells(): array
    {
        return (
            $this->repository->hasWorktrees()
                ? [ToolbarCell::Repository, ToolbarCell::Worktree, ToolbarCell::Branch, ToolbarCell::Remote]
                : [ToolbarCell::Repository, ToolbarCell::Branch, ToolbarCell::Remote]
        );
    }

    /**
     * Each cell's columns in a row $width wide, the last taking what is left.
     * A cell stands over the pane under it while there is one (the last pane
     * is "the rest", so it seats no cell but the last); past them, the cells
     * share the rest within {@see CELL_MAX}. Too tight for {@see CELL_MIN},
     * the first still stands over the sidebar and the others share what is
     * left; tighter still, they share the whole row — lined up with nothing,
     * but legible.
     *
     * @return list<int>
     */
    private function widths(int $width, int $count): array
    {
        $panes = null === $this->columns ? [] : ($this->columns)($width);
        $widths = [];
        $used = 0;
        for ($i = 0; $i < ($count - 1); ++$i) {
            $left = $count - $i;
            $cell = $i < (\count($panes) - 1) ? $panes[$i] : \min(self::CELL_MAX, \intdiv($width - $used - $left + 1, $left));
            $widths[] = $cell;
            $used += $cell + 1;
        }
        if ([] !== $widths && (\min($widths) < self::CELL_MIN || $used >= $width)) {
            $first = $panes[0] ?? 0;
            $rest = $count > 1 ? \intdiv($width - $first - $count + 1, $count - 1) : 0;

            return $rest >= self::CELL_TIGHT ? self::share($width, [$first], $count - 1) : self::share($width, [], $count);
        }

        return [...$widths, \max(1, $width - $used)];
    }

    /**
     * $fixed's cells, then $count more sharing the rest of a row $width wide
     * evenly, the last taking the odd columns.
     *
     * @param list<int> $fixed
     *
     * @return list<int>
     */
    private static function share(int $width, array $fixed, int $count): array
    {
        $left = $width - \array_sum($fixed) - \count($fixed);
        $even = \max(1, \intdiv($left - $count + 1, \max(1, $count)));

        return [...$fixed, ...\array_fill(0, \max(0, $count - 1), $even), \max(1, $left - (($count - 1) * ($even + 1)))];
    }

    /**
     * A cell's two rows: its label with what sits at its end, then its value
     * with its badge.
     *
     * @return array{string, string, string, string}
     */
    private function roomyCell(ToolbarCell $cell): array
    {
        $dim = $this->styles->of(Role::Dim);
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $arrow = $this->opens($cell) ? Glyphs::SELECT_ARROW : '';

        return match ($cell) {
            ToolbarCell::Repository => [self::REPOSITORY.' '.$dim->apply($this->translator->trans('Current repository')), $arrow, '  '.$bold->apply($this->repository->name()), ''],
            ToolbarCell::Worktree => [self::WORKTREE.' '.$dim->apply($this->translator->trans('Current worktree')), $arrow, '  '.$bold->apply(AnsiText::oneLine($this->repository->worktree())), ''],
            ToolbarCell::Branch => [self::BRANCH.' '.$dim->apply($this->translator->trans('Current branch')), $arrow, '  '.$bold->apply($this->branchName()), $this->pullRequest()],
            ToolbarCell::Remote => [$this->remoteIcon().' '.$bold->apply($this->remoteLabel()), $this->counts(), '  '.$dim->apply($this->fetched()), ''],
        };
    }

    /**
     * The label row, the value row and the rule under both, a `┴` under each
     * cell's divider.
     *
     * @return list<string>
     */
    private function roomy(int $width): array
    {
        $cells = $this->cells();
        $widths = $this->widths($width, \count($cells));
        $rule = $this->styles->of(Role::Rule);
        $top = '';
        $middle = '';
        $bottom = '';
        $col = 0;
        foreach ($cells as $i => $cell) {
            [$label, $marker, $value, $badge] = $this->roomyCell($cell);
            $cellWidth = $widths[$i];
            $last = $i === (\count($cells) - 1);
            $this->zones->add($cell->name, $col, $col + $cellWidth);
            $hover = $cell === $this->hovered ? $this->styles->of(Role::BarHoverBg) : null;
            $divider = $last ? '' : $rule->apply(Glyphs::SEPARATOR);
            $top .= $this->shade(self::seat(' '.$label, $marker, $cellWidth), $hover).$divider;
            $middle .= $this->shade(self::seat(' '.$value, $badge, $cellWidth), $hover).$divider;
            $bottom .= \str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $cellWidth)).($last ? '' : Glyphs::RULE_UP);
            $col += $cellWidth + 1;
        }

        return [
            AnsiText::truncate($top, $width, pad: true),
            AnsiText::truncate($middle, $width, pad: true),
            $rule->apply(\mb_substr($bottom.\str_repeat(Glyphs::FRAME_HORIZONTAL, \max(0, $width)), start: 0, length: $width)),
        ];
    }

    /**
     * A cell on the one bar row: what leads its value, the value, and what
     * follows it.
     *
     * @return array{string, string, string}
     */
    private function compactCell(ToolbarCell $cell): array
    {
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $arrow = $this->opens($cell) ? ' '.Glyphs::SELECT_ARROW.' ' : ' ';
        $badge = $this->pullRequest();
        $when = $this->repository->lastFetched();

        return match ($cell) {
            ToolbarCell::Repository => [' '.self::REPOSITORY.' ', $bold->apply($this->repository->name()), $arrow],
            ToolbarCell::Worktree => [' '.self::WORKTREE.' ', $bold->apply(AnsiText::oneLine($this->repository->worktree())), $arrow],
            ToolbarCell::Branch => [' '.self::BRANCH.' ', $bold->apply($this->branchName()), ('' === $badge ? '' : ' '.$badge).$arrow],
            // What the button does and what it moves stay; the time gives way first.
            ToolbarCell::Remote => [
                ' '.$this->remoteIcon().' '.$bold->apply($this->remoteLabel()).('' === $this->counts() ? '' : ' '.$this->counts()).(null === $when ? '' : ' · '),
                null === $when ? '' : $this->time->short($when, ($this->clock)()),
                ' ',
            ],
        };
    }

    /**
     * One bar row. The first cell stands over the sidebar like a roomy one;
     * the others are as wide as what they say, and past their share of the
     * rest a cell gives up the end of its VALUE — never the arrow that says it
     * opens a menu, nor the badge.
     */
    private function compact(int $width): string
    {
        $cells = $this->cells();
        $first = null === $this->columns ? null : ($this->columns)($width)[0] ?? null;
        $row = '';
        $col = 0;
        $separator = $this->styles->of(Role::Rule)->apply(Glyphs::SEPARATOR);
        foreach ($cells as $i => $cell) {
            [$head, $value, $tail] = $this->compactCell($cell);
            $seated = 0 === $i && null !== $first && $first < $width;
            $room = $seated ? $first : \max(1, \intdiv($width - $col, \count($cells) - $i) - 1);
            $valueRoom = \max(1, $room - AnsiUtils::visibleWidth($head) - AnsiUtils::visibleWidth($tail));
            $text = $head.(AnsiUtils::visibleWidth($value) > $valueRoom ? AnsiText::truncate($value, $valueRoom) : $value).$tail;
            $text = $seated ? AnsiText::truncate($text, $room, pad: true) : $text;
            $cellWidth = AnsiUtils::visibleWidth($text);
            $this->zones->add($cell->name, $col, $col + $cellWidth);
            $row .= $this->shade($text, $cell === $this->hovered ? $this->styles->of(Role::BarHoverBg) : null).($i === (\count($cells) - 1) ? '' : $separator);
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
