<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Support;

use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\View\GitWiring;

/**
 * Where the view lands once core builds it, for the things registered before it
 * exists.
 *
 * The plugin's two phases do not line up: `PluginApi` drains its buffers at
 * commit, so a listener registered after that is refused — but the view's
 * factory runs after commit, because a view needs `ViewServices` and those need
 * the region core mints for it. A listener that has to reach the view is
 * therefore registered against a promise of one.
 *
 * ONE listener needs this: `GitWiring::VIEW_CORE`, the controller's answer to
 * core's `GitRemoteCompleted`. The toggle command used to need it too, to ask
 * `isOpen()` before choosing open or close — `PluginApi::toggleView()` asks the
 * registry instead, which is where the answer lived all along.
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
