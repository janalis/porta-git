<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * Where the view lands once core builds it, for the things registered before it
 * exists.
 *
 * The plugin's two phases do not line up: `PluginApi` drains its buffers at
 * commit, so a command or a listener registered after that is refused — but the
 * view's factory runs after commit, because a view needs `ViewServices` and
 * those need the region core mints for it. Anything that has to reach the view
 * therefore has to be registered against a promise of one.
 *
 * A typed slot rather than a `use (&$view)` capture: the reference form works,
 * but the analyser narrows the captured variable to the `null` it holds at the
 * closure's definition, so every use of it needs a suppression to say "this is
 * written later" — which is exactly the thing that should be in the type.
 */
final class ViewSlot
{
    public ?GitViewController $view = null;
}
