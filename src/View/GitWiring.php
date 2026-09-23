<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitDiffContextCycleRequested;
use Porta\GitPlugin\Event\GitDiffCopyRequested;
use Porta\GitPlugin\Event\GitDiffLayoutToggled;
use Porta\GitPlugin\Event\GitDiffStagingRequested;
use Porta\GitPlugin\Event\GitDiffWhitespaceToggleRequested;
use Porta\GitPlugin\Event\GitFileHistoryRequested;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitStageAllRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitRefresher;

/**
 * Every event this plugin reacts to, and the method that answers it.
 *
 * Nineteen `#[AsEventListener]` attributes used to say this, and the container
 * read them. Outside `src/` that feature evaporates, and the failure is silent:
 * nothing throws, a reaction simply never happens, and only a test that walked
 * that exact path would notice.
 *
 * So the map is data in one place. `plugin.php` loops over it to register, and
 * `GitListenerContractTest` asserts it against the methods that actually exist
 * — in BOTH directions, so a listener that disappears fails and so does an
 * `on*` method added without being written down here. Hand-registering
 * eighteen of nineteen is a working IDE with one dead interaction, and that is
 * the failure this exists to make loud.
 */
final class GitWiring
{
    /**
     * Widget → controller, on the dispatcher `plugin.php` owns.
     *
     * @var array<class-string, string> event class => GitViewController method
     */
    public const array VIEW = [
        GitChangeDiscardRequested::class => 'onChangeDiscarded',
        GitChangeSelected::class => 'onChangeSelected',
        GitChangeStageToggleRequested::class => 'onChangeStageToggled',
        GitStageAllRequested::class => 'onStageAllRequested',
        GitCommitFileSelected::class => 'onCommitFileSelected',
        GitCommitSelected::class => 'onCommitSelected',
        GitDiffContextCycleRequested::class => 'onDiffContextCycled',
        GitDiffCopyRequested::class => 'onDiffTextCopied',
        GitDiffLayoutToggled::class => 'onDiffLayoutToggled',
        GitDiffStagingRequested::class => 'onDiffLinesStaged',
        GitDiffWhitespaceToggleRequested::class => 'onDiffWhitespaceToggled',
        GitFileHistoryRequested::class => 'onFileHistoryRequested',
        GitHistoryEndReached::class => 'onHistoryEndReached',
        GitRemoteRequested::class => 'onRemoteRequested',
        GitViewModeRequested::class => 'onViewModeRequested',
    ];

    /**
     * Core events the controller answers, through `PluginApi::on()`.
     *
     * One entry, and it is the one that proves the split is real: a fetch
     * finishing is a fact anything may observe, so it stays a core
     * `PluginObservable` rather than moving onto this plugin's dispatcher with
     * the fourteen above.
     *
     * @var array<class-string, string>
     */
    public const array VIEW_CORE = [
        GitRemoteCompleted::class => 'onRemoteCompleted',
    ];

    /**
     * Core events the branch refresher answers.
     *
     * `BufferSaved` is spelled `PluginApi::onFileSaved()` instead — it carries a
     * mutable `Buffer`, so it is not something a plugin may hold — which is why
     * it is not in this map and {@see GitRefresher::onBufferSaved()} is gone.
     *
     * @var array<class-string, string>
     */
    public const array REFRESHER = [
        GitCommitted::class => 'onGitCommitted',
        GitRemoteCompleted::class => 'onGitRemoteCompleted',
        WorkingTreeChanged::class => 'onWorkingTreeChanged',
    ];
}
