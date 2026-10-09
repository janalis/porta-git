<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\Widget\PaneNavigationCallbacks;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The keys the two git list panes share: the remote-operation trio
 * (fetch/pull/push, dispatched) plus the pane-navigation pair (still closures —
 * two live panes wire them to different targets, so a broadcast could not tell
 * which one asked). Composes
 * {@see PaneNavigationCallbacks} rather than sitting beside it, so mago's
 * isolated trait analysis sees $onExit/$onFocusNext/fire() as members here
 * and each widget states one dependency instead of two.
 */
trait GitPaneKeys
{
    use PaneNavigationCallbacks;

    /**
     * Declared here rather than promoted in each widget's constructor: mago
     * analyses a trait in isolation, so the dispatch below has to see the
     * property it uses.
     */
    private ?EventDispatcherInterface $events = null;

    /** Handle a key shared by the git list panes; true when it consumed the key. */
    private function handleGitPaneKey(string $data, Keybindings $kb): bool
    {
        $operation = \array_find(GitRemoteOperation::cases(), static fn (GitRemoteOperation $operation): bool => $kb->matches($data, $operation->value));
        if (null !== $operation) {
            $this->events?->dispatch(new GitRemoteRequested($operation));

            return true;
        }
        $exit = $kb->matches($data, 'exit_view');
        $handled = $exit || $kb->matches($data, 'focus_next_pane');
        if ($handled) {
            $this->fire($exit ? $this->onExit : $this->onFocusNext);
        }

        return $handled;
    }
}
