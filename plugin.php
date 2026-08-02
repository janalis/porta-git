<?php

declare(strict_types=1);

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\BlameAnnotations;
use Porta\GitPlugin\BlameClient;
use Porta\GitPlugin\BlameLine;
use Porta\GitPlugin\ChangeMarks;
use Porta\GitPlugin\GitClient;
use Porta\GitPlugin\Stash;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * Git blame as a bundled plugin — the only slice of git that could leave
 * `src/`, and the reason the rest could not is in docs/internals/decisions.md.
 *
 * Blame qualifies because it owns nothing of the workbench: no widget, no
 * layout region, no full-screen view, and it dispatches no events. It listens
 * (buffer switched, file saved), reads one core setting, writes one editor
 * decoration, and flashes. Every one of those already had a seam except the
 * decoration, which is now `PluginApi::setLineAnnotations()` — justified on
 * its own for coverage overlays and inline test results.
 *
 * Both verbs keep their identity through `adoptCommand()`: core still NAMES
 * `git_blame` and `git_toggle_blame` in its ActionCatalog, so "Blame Current
 * Line" stays where it is in the Git menu instead of moving to the trailing
 * Plugins menu, and the keymap page lists them — unbound, as they have always
 * been. That is why there is no `addMenuItem()` call below.
 *
 * The plugin directory is `git` rather than `blame` because it is the place a
 * future git feature would land if one ever became extractable. Its namespace
 * is `Porta\GitPlugin`, NOT `Porta\Git`: core owns that one, and sharing it
 * would merge the two halves in `composer arch`'s FQCN-keyed edge buckets and
 * make a class-name collision resolve silently.
 */
return new class('git') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        // Built once, not per call: BlameAnnotations is a toggle, so it has to
        // remember whether it is on between the command and the hooks.
        $blame = new BlameClient($api->projectRoot(), $this->coreSetting('gitPath'));
        $annotations = new BlameAnnotations($blame, $api, '1' === $this->coreSetting('gitBlameDefault'));

        $api->adoptCommand('git_blame', new BlameLine($blame, $api)->show(...));
        $api->adoptCommand('git_toggle_blame', $annotations->toggle(...));

        $api->onBufferSwitched(static fn (string $path) => $annotations->refreshIfEnabled());
        $api->onFileSaved(static fn (string $path) => $annotations->refreshIfEnabled());

        // The gutter stripe, which core used to compute itself.
        $git = new GitClient($api->projectRoot(), $this->coreSetting('gitPath'));
        $marks = new ChangeMarks(
            $git,
            $api,
            '0' !== $this->coreSetting('gitGutterMarks'),
        );
        $stash = new Stash($git, $api);
        $api->adoptCommand('git_stash', $stash->push(...));
        $api->adoptCommand('git_stash_pop', $stash->pop(...));

        $api->onFileOpened($marks->refresh(...));
        $api->onFileSaved($marks->refresh(...));
        $api->onFileReloaded($marks->refresh(...));
        $api->onBufferSwitched($marks->refresh(...));
        // A commit, a stash, a pull: whatever moved, the marks are stale.
        $api->on(WorkingTreeChanged::class, static fn () => $marks->refresh());
        $api->on(GitCommitted::class, static fn () => $marks->refresh());
        $api->on(GitRemoteCompleted::class, static fn () => $marks->refresh());
    }
};
