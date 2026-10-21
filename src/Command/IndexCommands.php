<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\GitInfo;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Outcome;

/**
 * Stage the current file, and undo the last commit.
 *
 * Both were blocked in core until the working-tree announcement existed: each
 * ends by telling everyone the tree moved, which is how the gutter stripe and
 * the branch indicator catch up. Core used to call the refresher directly,
 * which a plugin cannot do — and should not, since it would mean knowing which
 * chrome exists.
 *
 * They are here and `commit` is not, deliberately: a commit needs the sign,
 * no-verify and signoff settings, which are live-mutable and reach a plugin
 * only as a boot snapshot, and it opens a prompt that has to be focused first.
 */
final readonly class IndexCommands
{
    public function __construct(
        private GitInfo $git,
        private PluginApi $api,
    ) {}

    public function stageCurrent(): void
    {
        $path = $this->api->activeFile();
        if (null === $path || !$this->git->isAvailable()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        // Staging a conflicted file marks it resolved: not with a marker left in it.
        $this->git->conflictsLeft(function (array $left) use ($path): void {
            if (\in_array($this->relative($path), $left, strict: true)) {
                $this->api->flash($this->api->trans('%file% still has conflict markers: resolve them first', ['%file%' => \basename($path)]));

                return;
            }
            $this->stage($path);
        });
    }

    private function stage(string $path): void
    {
        $this->git->stage($path, function (Outcome $ok) use ($path): void {
            $this->api->flash(
                $ok->isOk()
                    ? $this->api->trans('Staged %path%', ['%path%' => $this->relative($path)])
                    : $this->api->trans('Stage failed'),
            );
            $this->api->emit(new WorkingTreeChanged());
        });
    }

    public function undoLastCommit(): void
    {
        if (!$this->git->isAvailable()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        $this->git->undoLastCommit(function (Outcome $ok): void {
            $this->api->flash(
                $ok->isOk()
                    ? $this->api->trans('Last commit undone (changes kept staged)')
                    : $this->api->trans('Nothing to undo'),
            );
            $this->api->emit(new WorkingTreeChanged());
        });
    }

    /** The project-relative path, for a message the user can read at a glance. */
    private function relative(string $path): string
    {
        $root = $this->api->projectRoot();

        return \str_starts_with($path, $root.'/') ? \substr($path, \strlen($root) + 1) : $path;
    }
}
