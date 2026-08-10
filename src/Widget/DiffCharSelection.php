<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

/**
 * A character-level selection in the diff pane: the press point (from) and the
 * drag point (to) as (row, display-column) pairs, plus the split-view column
 * the drag started on (null in unified view). Owned by
 * {@see DiffViewWidget}; replaces the former
 * array{fromRow, fromCol, toRow, toCol, side} shape.
 */
final readonly class DiffCharSelection
{
    public function __construct(
        public int $fromRow,
        public int $fromCol,
        public int $toRow,
        public int $toCol,
        public ?DiffColumn $side,
    ) {}

    /** A zero-width selection — a plain click with no drag (row-copy rules apply). */
    public function isDegenerate(): bool
    {
        return $this->fromRow === $this->toRow && $this->fromCol === $this->toCol;
    }

    /** This selection ordered top-left → bottom-right, or null when degenerate. */
    public function normalized(): ?self
    {
        if ($this->isDegenerate()) {
            return null;
        }
        if ($this->fromRow > $this->toRow || $this->fromRow === $this->toRow && $this->fromCol > $this->toCol) {
            return new self($this->toRow, $this->toCol, $this->fromRow, $this->fromCol, $this->side);
        }

        return $this;
    }
}
