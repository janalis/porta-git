<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/**
 * Show the git view's History tab filtered to one file.
 *
 * A command, so exactly one listener performs it — the git view itself, which
 * is the only thing that can. It travels as an event rather than as a call
 * because the view registry hands back a {@see \Porta\Workbench\View\ContributedView}
 * without a view's own verbs on it: a capability only one view has is that
 * view's business, and the alternative (the caller holding the controller)
 * makes a heavyweight constructor dependency out of one method.
 */
final class GitFileHistoryRequested
{
    public function __construct(
        public readonly string $path,
    ) {}
}
