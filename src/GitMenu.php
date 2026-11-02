<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Workbench\Menu\MenuModel;

/**
 * Git's top-level menu and the commands that survive its view being open.
 *
 * Both used to be constants in {@see MenuModel} — a `DEFS['Git']` group and a
 * `VIEW_SAFE['git']` list — and the whole group was hidden by a
 * `'Git' !== $title || $git->isRepository()` closure the rebuilder passed in,
 * which existed for this one group and no other.
 *
 * The two lists belong together. A contributor that supplied its menu but not
 * its view-safe list would grey out its own items the moment its view opened:
 * the gate enables only what the active view names, so an empty list means a
 * dead menu with nothing to say why.
 *
 * Two static lists rather than a constructor with a side effect: this used to
 * be an #[AsEager] service that pushed itself into `MenuContributions`, which
 * a plugin class can never be. `porta.php` reads both and passes them to
 * `PluginApi::registerMenu()`, where the visibility gate lives too.
 */
final readonly class GitMenu
{
    /** Placed after Run, which is where the group sat among core's own. */
    public const string AFTER = 'Run';

    /** @return list<string> `'i:<command-id>'` entries and separators */
    public static function items(): array
    {
        return [
            'i:ide_git',
            MenuModel::SEP,
            'i:git_stage',
            'i:git_commit',
            'i:git_undo_commit',
            'i:git_blame',
            'i:git_branches',
            'i:git:worktrees',
            'i:git:new_worktree',
            'i:git_compare_files',
            MenuModel::SEP,
            'i:git_fetch',
            'i:git_pull',
            'i:git_push',
        ];
    }

    /**
     * The commands that stay live while the git view owns the main area.
     *
     * @return list<string>
     */
    public static function viewSafe(): array
    {
        return [
            'ide_quit',
            'ide_git',
            'git_commit',
            'git_undo_commit',
            'git_fetch',
            'git_pull',
            'git_push',
            'git_branches',
            'git:worktrees',
            'git:new_worktree',
            'ide_command_palette',
            'ide_help',
            'ide_welcome',
        ];
    }
}
