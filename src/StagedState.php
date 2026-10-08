<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;

/**
 * How much of a changed file the next commit would take: the changes list's
 * checkbox, GitHub-Desktop-style. Derived from the two status letters by
 * {@see ChangeEntry::stagedState()}; never stored, never crosses an event.
 */
enum StagedState
{
    case None;
    case Partial;
    case Full;

    /** @param ?Styles $tint the accent for the mark, off the selected row only ({@see FieldBox::checkbox()}) */
    public function checkbox(?Styles $tint = null): string
    {
        // @noinspection NullPointerExceptionInspection -- FieldBox::checkbox() takes a null tint: nothing is dereferenced
        return FieldBox::checkbox(match ($this) {
            self::Full => true,
            self::Partial => null,
            self::None => false,
        }, tint: $tint);
    }
}
