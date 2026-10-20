<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\DiffStat;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\LogEntry;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Density;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * GitHub Desktop's commit header in History: the selected commit's subject,
 * then `author · sha ⧉` with the lines it added and removed at the far end,
 * across the files column and the diff both — then a rule, which the frame
 * joins to the divider between them.
 *
 * Roomy, the subject has a row of its own; compact, one row says it all and
 * the author gives way. While the columns are stacked the rule names the
 * files count, standing in for the files column's own heading.
 */
final class CommitHeaderWidget extends AbstractWidget implements MenuOverlayHost, TooltipProvider
{
    use MenuOverlayHostTrait;
    use StylesDefault;

    /** The copy button after the short sha. Checked narrow: East Asian Neutral, no emoji presentation. */
    private const string COPY = '⧉';

    private readonly ScreenDensity $density;

    private ?LogEntry $commit = null;

    private ?DiffStat $stat = null;

    /** The files count the rule names while the columns are stacked; null side by side. */
    private ?int $files = null;

    /**
     * The copy button's [row, start, end) in the last render, or null when it was not drawn.
     *
     * @var array{int, int, int}|null
     */
    private ?array $copyZone = null;

    /** The rows above the rule in the last render: the commit's. */
    private int $textRows = 0;

    private int $drawnWidth = 0;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?ScreenDensity $density = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->density = $density ?? new ScreenDensity();
    }

    /** Show $commit, its stat still to come; null empties the header. */
    public function setCommit(?LogEntry $commit): void
    {
        $this->commit = $commit;
        $this->stat = null;
        $this->invalidate();
    }

    public function setStat(?DiffStat $stat): void
    {
        $this->stat = $stat;
        $this->invalidate();
    }

    /** Name the files count in the rule (the columns are stacked), or stop (null). */
    public function nameFiles(?int $count): void
    {
        $this->files = $count;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $this->drawnWidth = $width;
        $this->copyZone = null;
        $lines = null === $this->commit ? [] : $this->text($this->commit, $width);
        $this->textRows = \count($lines);
        $legend = null === $this->files ? '' : $this->translator->trans('%count% changed file|%count% changed files', ['%count%' => $this->files]);
        $lines[] = Rule::horizontal($width, $this->styles, $legend);

        return $lines;
    }

    /**
     * The commit's rows: roomy, the subject over `author · sha ⧉ … +N −M`;
     * compact, `subject · sha ⧉ … +N −M` alone.
     *
     * @return list<string>
     */
    private function text(LogEntry $commit, int $width): array
    {
        $bold = $this->styles->of(Role::Emphasis)->withBold();
        $subject = $bold->apply(AnsiText::oneLine($commit->subject));
        $sha = $this->styles->of(Role::Modified)->apply($commit->shortSha).' '.self::COPY;
        $stat = $this->statText();
        if (Density::Roomy === $this->density->current()) {
            $by = ' '.$this->styles->of(Role::Dim)->apply(AnsiText::oneLine($commit->author).' · ');

            return [
                AnsiText::truncate(' '.$subject, $width, pad: true),
                $this->withStat($by, $sha, $stat, $width, 1),
            ];
        }

        // The joint goes with the sha, so a subject cut short still stands apart from it.
        return [$this->withStat(' '.$subject, $this->styles->of(Role::Dim)->apply(' · ').$sha, $stat, $width, 0)];
    }

    /**
     * $lead, the sha and its copy button, and the stat flush right, in
     * exactly $width columns. The lead gives way first, the stat last: the
     * copy button is drawn — and pressable — only while it fits whole.
     */
    private function withStat(string $lead, string $sha, string $stat, int $width, int $row): string
    {
        $tail = '' === $stat ? '' : ' '.$stat.' ';
        $room = \max(0, $width - AnsiUtils::visibleWidth($tail));
        $shaWidth = AnsiUtils::visibleWidth($sha);
        $leadRoom = \max(0, $room - $shaWidth);
        $lead = AnsiUtils::visibleWidth($lead) > $leadRoom ? AnsiText::truncate($lead, $leadRoom) : $lead;
        $start = AnsiUtils::visibleWidth($lead) + $shaWidth - 1;
        if (($start + 1) <= $room) {
            $this->copyZone = [$row, $start, $start + 1];
        }

        return AnsiText::truncate(AnsiText::truncate($lead.$sha, $room, pad: true).$tail, $width, pad: true);
    }

    /** `+3 −1` in the diff's own colours; nothing until git has counted. */
    private function statText(): string
    {
        if (null === $this->stat) {
            return '';
        }

        return $this->styles->of(Role::Added)->apply('+'.$this->stat->added).' '.$this->styles->of(Role::Deleted)->apply('−'.$this->stat->deleted);
    }

    /** A press: the copy button copies the sha. */
    public function handleClick(int $relRow, int $relCol): void
    {
        if ($this->onCopy($relRow, $relCol) && null !== $this->commit) {
            $this->events?->dispatch(new GitCommitHashCopyRequested($this->commit));
        }
    }

    private function onCopy(int $row, int $col): bool
    {
        return null !== $this->copyZone && $row === $this->copyZone[0] && $col >= $this->copyZone[1] && $col < $this->copyZone[2];
    }

    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $commit = $this->commit;
        $zone = $this->onCopy($row, $col) ? $this->copyZone : null;

        return match (true) {
            null === $commit || $row >= $this->textRows => null,
            null !== $zone => new Tooltip('git:header:copy', $this->translator->trans('Copy the full commit hash'), $row, $zone[1], $zone[2]),
            default => new Tooltip('git:header:'.$commit->sha, $commit->sha."\n".$commit->subject."\n".$commit->author.' · '.\date('Y-m-d H:i', $commit->timestamp), $row, 0, $this->drawnWidth),
        };
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return \max(1, $context->getColumns());
    }
}
