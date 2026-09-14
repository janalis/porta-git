<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Ui\Glyphs;

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

    public function checkbox(): string
    {
        return match ($this) {
            self::Full => Glyphs::CHECKBOX_ON,
            self::Partial => Glyphs::CHECKBOX_PARTIAL,
            self::None => Glyphs::CHECKBOX_OFF,
        };
    }
}
