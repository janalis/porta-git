<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Config\PortaHome;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Parse\BranchParser;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\GitPlugin\Worktree;
use Porta\Support\Files;
use Porta\Support\Outcome;
use Porta\Ui\Glyphs;

/**
 * Git worktrees, after GitHub Desktop: list them and switch to one, add one
 * on a new, local or remote branch, rename, delete, lock and prune.
 *
 * Switching is reopening the IDE on the worktree's folder, through core's
 * project switch — the root of a running session never changes. That is
 * also why the worktree this session is IN can be neither renamed nor
 * deleted from here: Desktop switches to the main one first and then
 * deletes, and here the switch ends the session the delete would run in.
 */
final readonly class WorktreeCommands
{
    /** Sentinel row values — NUL can never appear in a path. */
    private const string NEW = "\0new";
    private const string DELETE = "\0delete";
    private const string RENAME = "\0rename";
    private const string LOCK = "\0lock";
    private const string PRUNE = "\0prune";
    private const string NEW_BRANCH = "\0new-branch";
    private const string REMOTE_PREFIX = "\0remote\0";

    /** The longest path a title shows whole, in characters. */
    private const int TITLE_PATH = 48;

    public function __construct(
        private GitInfo $git,
        private Modals $editor,
        private Flash $notifier,
        /** @var \Closure(): void asks for a frame */
        private \Closure $repaint,
        private Translate $translator,
        /** @var \Closure(string, string): void reopens the IDE on a folder, with the repeat clause its refusal ends on */
        private \Closure $openProject,
        /** @var \Closure(): string the `worktreeFolder` setting: where new worktrees go, '' for beside the main one */
        private \Closure $folder,
        private string $rootDir,
    ) {}

    /** The worktree list: each one to switch to, then what can be done to them. */
    public function worktrees(): void
    {
        $this->withWorktrees(function (array $worktrees): void {
            $items = [
                ['label' => $this->translator->trans('+ New worktree…'), 'hint' => '', 'value' => self::NEW],
                ['label' => $this->translator->trans('− Delete worktree…'), 'hint' => '', 'value' => self::DELETE],
                ['label' => '  '.$this->translator->trans('Rename worktree…'), 'hint' => '', 'value' => self::RENAME],
                ['label' => '  '.$this->translator->trans('Lock or unlock worktree…'), 'hint' => '', 'value' => self::LOCK],
            ];
            if ([] !== \array_filter($worktrees, static fn (Worktree $w): bool => $w->prunable)) {
                $items[] = ['label' => '  '.$this->translator->trans('Forget missing worktrees'), 'hint' => '', 'value' => self::PRUNE];
            }
            foreach ($worktrees as $worktree) {
                $items[] = [
                    'label' => ($this->isCurrent($worktree) ? Glyphs::BRANCH_CURRENT.' ' : '  ').$worktree->name(),
                    'hint' => $this->hint($worktree),
                    'value' => $worktree->path,
                    // Its folder is gone: nothing to open.
                    'disabled' => $worktree->prunable,
                ];
            }
            $this->editor->openListOverlay($this->translator->trans('Worktrees'), $items, function (string $value) use ($worktrees): void {
                match ($value) {
                    self::NEW => $this->askName($worktrees),
                    self::DELETE => $this->pickToDelete($worktrees),
                    self::RENAME => $this->pickToRename($worktrees),
                    self::LOCK => $this->pickToLock($worktrees),
                    self::PRUNE => $this->git->pruneWorktrees(fn (Outcome $ok, string $error) => $this->finish($ok, $error, $this->translator->trans('Forgot the missing worktrees'))),
                    default => $this->open($value),
                };
            });
            ($this->repaint)();
        });
    }

    /** Straight to the name prompt (the Git menu's "New Worktree…"). */
    public function newWorktree(): void
    {
        $this->withWorktrees($this->askName(...));
    }

    /** Reopen the IDE on the worktree at $path. */
    public function open(string $path): void
    {
        // Not "pick it again": by then the list it was picked from is closed, and
        // the guard's few seconds are gone before a fresh one has listed.
        ($this->openProject)($path, $this->translator->trans('Ctrl+S to save, then switch worktree'));
    }

    /** Whether $worktree is the one this session has open. */
    public function isCurrent(Worktree $worktree): bool
    {
        return Files::canonical($worktree->path) === Files::canonical($this->rootDir);
    }

    /**
     * The worktrees, or a flash saying why there are none to show.
     *
     * @param \Closure(list<Worktree>): void $then
     */
    private function withWorktrees(\Closure $then): void
    {
        if (!$this->git->isAvailable()) {
            $this->notifier->flash($this->translator->trans('Git not available'));

            return;
        }
        $this->git->worktrees(
            /** @param list<Worktree>|null $worktrees */
            function (?array $worktrees, string $reason) use ($then): void {
                if (null === $worktrees) {
                    $this->notifier->error($this->translator->trans('Could not list worktrees: %reason%', ['%reason%' => $reason]), 6.0);

                    return;
                }
                $then($worktrees);
            },
        );
    }

    /**
     * Desktop's two fields, one after the other: the worktree's name, which
     * is also its folder's and, by default, its new branch's — then the branch.
     *
     * @param list<Worktree> $worktrees
     */
    private function askName(array $worktrees): void
    {
        $this->editor->openPrompt($this->translator->trans('Worktree name'), '', function (string $name) use ($worktrees): void {
            $name = \trim($name);
            if ('' === $name) {
                return;
            }
            $this->pickBranch($name, $this->folderFor($worktrees).'/'.$name, $worktrees);
        });
        ($this->repaint)();
    }

    /**
     * A new branch named after the worktree, or a branch no worktree has
     * checked out — git refuses one that is. The title says where the
     * folder will be, as Desktop's dialog does under its fields.
     *
     * @param list<Worktree> $worktrees
     */
    private function pickBranch(string $name, string $path, array $worktrees): void
    {
        // A detached worktree holds no branch.
        $taken = \array_filter(\array_map(static fn (Worktree $w): ?string => $w->branch, $worktrees), static fn (?string $branch): bool => null !== $branch);
        $this->git->branches(function (array $branches) use ($name, $path, $taken): void {
            $free = \array_values(\array_filter($branches, static fn (array $b): bool => $b['remote'] || !\in_array($b['name'], $taken, strict: true)));
            $named = \array_values(\array_filter($free, static fn (array $b): bool => !$b['remote'] && $b['name'] === $name));
            $items = match (true) {
                // A free branch that already has the worktree's name is the
                // one meant, as in Desktop.
                [] !== $named => [],
                // One another worktree has out cannot be checked out again,
                // and a new branch of its name would only fail.
                \in_array($name, $taken, strict: true) => [[
                    'label' => $this->translator->trans('%branch% is checked out in another worktree', ['%branch%' => $name]),
                    'hint' => '',
                    'value' => '',
                    'note' => true,
                ]],
                default => [['label' => $this->translator->trans('+ New branch %branch%', ['%branch%' => $name]), 'hint' => '', 'value' => self::NEW_BRANCH]],
            };
            foreach ([...$named, ...\array_filter($free, static fn (array $b): bool => $b['remote'] || $b['name'] !== $name)] as $branch) {
                $items[] = [
                    'label' => '  '.$branch['name'],
                    'hint' => $branch['remote'] ? $this->translator->trans('remote') : '',
                    'value' => $branch['remote'] ? self::REMOTE_PREFIX.$branch['name'] : $branch['name'],
                ];
            }
            $this->editor->openListOverlay($this->translator->trans('Branch for %path%', ['%path%' => self::shown($path)]), $items, fn (string $value) => $this->add($name, $path, $value));
            ($this->repaint)();
        });
    }

    private function add(string $name, string $path, string $branch): void
    {
        $done = function (Outcome $ok, string $error) use ($name, $path): void {
            if (!$ok->isOk()) {
                $this->notifier->error($error, 6.0);

                return;
            }
            $this->notifier->success($this->translator->trans('Created worktree %name%', ['%name%' => $name]), 3.0);
            $this->editor->openListOverlay(
                $this->translator->trans('Open %name%?', ['%name%' => $name]),
                [
                    ['label' => $this->translator->trans('Open'), 'hint' => $this->translator->trans('this session ends'), 'value' => 'open'],
                    ['label' => $this->translator->trans('Stay'), 'hint' => '', 'value' => 'stay'],
                ],
                function (string $answer) use ($path): void {
                    if ('open' === $answer) {
                        $this->open($path);
                    }
                },
            );
            ($this->repaint)();
        };
        match (true) {
            self::NEW_BRANCH === $branch => $this->git->addWorktree($path, $name, null, $done),
            // A remote branch gets a local one of its short name, from it.
            \str_starts_with($branch, self::REMOTE_PREFIX) => $this->git->addWorktree(
                $path,
                BranchParser::shortName(\substr($branch, \strlen(self::REMOTE_PREFIX))),
                \substr($branch, \strlen(self::REMOTE_PREFIX)),
                $done,
            ),
            default => $this->git->addWorktree($path, null, $branch, $done),
        };
    }

    /** @param list<Worktree> $worktrees */
    private function pickToDelete(array $worktrees): void
    {
        $this->pickMovable($worktrees, $this->translator->trans('Delete worktree'), $this->translator->trans('No other worktrees to delete'), function (Worktree $worktree): void {
            $this->confirm(
                $this->translator->trans('Delete %name%? Its folder goes with it', ['%name%' => $worktree->name()]),
                $this->translator->trans('Delete'),
                fn () => $this->git->removeWorktree($worktree->path, function (Outcome $ok, string $error) use ($worktree): void {
                    // git refuses a worktree with changes in it, and says to
                    // force it. Desktop offers exactly that, so this does too.
                    // The flag, not the prose: git speaks the user's language,
                    // but not its options' — and not the path, which could
                    // spell `--force` itself.
                    if (!$ok->isOk() && \str_contains(\str_replace($worktree->path, replace: '', subject: $error), '--force')) {
                        $this->confirm(
                            $this->translator->trans('%name% has changes. Delete it anyway, and them?', ['%name%' => $worktree->name()]),
                            $this->translator->trans('Delete anyway'),
                            fn () => $this->git->forceRemoveWorktree($worktree->path, fn (Outcome $ok, string $error) => $this->deleted($ok, $error, $worktree)),
                        );

                        return;
                    }
                    $this->deleted($ok, $error, $worktree);
                }),
            );
        });
    }

    private function deleted(Outcome $ok, string $error, Worktree $worktree): void
    {
        $this->finish($ok, $error, $this->translator->trans('Deleted worktree %name%', ['%name%' => $worktree->name()]));
    }

    /** @param list<Worktree> $worktrees */
    private function pickToRename(array $worktrees): void
    {
        $this->pickMovable($worktrees, $this->translator->trans('Rename worktree'), $this->translator->trans('No other worktrees to rename'), function (Worktree $worktree): void {
            $this->editor->openPrompt($this->translator->trans('New name for %name%', ['%name%' => $worktree->name()]), $worktree->name(), function (string $name) use ($worktree): void {
                $name = \trim($name);
                if ('' === $name || $name === $worktree->name()) {
                    return;
                }
                $target = \dirname($worktree->path).'/'.$name;
                // `worktree move` onto a folder that exists moves the worktree
                // INTO it, as `mv` would: a rename that buries it a level down.
                if (\file_exists($target)) {
                    $this->notifier->error($this->translator->trans('%path% already exists', ['%path%' => PortaHome::tilde($target)]), 6.0);

                    return;
                }
                $this->git->moveWorktree($worktree->path, $target, fn (Outcome $ok, string $error) => $this->finish($ok, $error, $this->translator->trans('Renamed worktree to %name%', [
                    '%name%' => $name,
                ])));
            });
            ($this->repaint)();
        });
    }

    /** @param list<Worktree> $worktrees */
    private function pickToLock(array $worktrees): void
    {
        // The main worktree cannot be locked; the one this session is in can.
        $lockable = \array_values(\array_filter($worktrees, static fn (Worktree $w): bool => !$w->main && !$w->prunable));
        $this->pickFrom($lockable, $this->translator->trans('Lock or unlock worktree'), $this->translator->trans('No linked worktrees'), function (Worktree $worktree): void {
            if ($worktree->locked()) {
                $this->git->unlockWorktree($worktree->path, fn (Outcome $ok, string $error) => $this->finish($ok, $error, $this->translator->trans('Unlocked %name%', [
                    '%name%' => $worktree->name(),
                ])));

                return;
            }
            $this->editor->openPrompt($this->translator->trans('Why lock %name%? (optional)', ['%name%' => $worktree->name()]), '', function (string $reason) use ($worktree): void {
                $this->git->lockWorktree($worktree->path, \trim($reason), fn (Outcome $ok, string $error) => $this->finish($ok, $error, $this->translator->trans('Locked %name%', [
                    '%name%' => $worktree->name(),
                ])));
            });
            ($this->repaint)();
        });
    }

    /**
     * The worktrees that can be renamed or deleted: linked, there, unlocked,
     * and not the one this session is in.
     *
     * @param list<Worktree>          $worktrees
     * @param \Closure(Worktree): void $then
     */
    private function pickMovable(array $worktrees, string $title, string $none, \Closure $then): void
    {
        $this->pickFrom(\array_values(\array_filter($worktrees, fn (Worktree $w): bool => !$w->main && !$w->locked() && !$w->prunable && !$this->isCurrent($w))), $title, $none, $then);
    }

    /**
     * @param list<Worktree>          $worktrees
     * @param \Closure(Worktree): void $then
     */
    private function pickFrom(array $worktrees, string $title, string $none, \Closure $then): void
    {
        if ([] === $worktrees) {
            $this->notifier->flash($none);

            return;
        }
        $byPath = [];
        $items = [];
        foreach ($worktrees as $worktree) {
            $byPath[$worktree->path] = $worktree;
            $items[] = ['label' => '  '.$worktree->name(), 'hint' => $this->hint($worktree), 'value' => $worktree->path];
        }
        $this->editor->openListOverlay($title, $items, static function (string $path) use ($byPath, $then): void {
            $then($byPath[$path]);
        });
        ($this->repaint)();
    }

    /** @param \Closure(): void $then */
    private function confirm(string $title, string $yes, \Closure $then): void
    {
        $this->editor->openListOverlay(
            $title,
            [
                ['label' => $yes, 'hint' => '', 'value' => 'yes'],
                ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'no'],
            ],
            static function (string $answer) use ($then): void {
                if ('yes' === $answer) {
                    $then();
                }
            },
        );
        ($this->repaint)();
    }

    private function finish(Outcome $ok, string $error, string $success): void
    {
        $ok->isOk() ? $this->notifier->success($success, 3.0) : $this->notifier->error($error, 6.0);
        ($this->repaint)();
    }

    /**
     * What it has checked out, then what sets it apart — Desktop's two
     * lines. Not where it is: the name is its folder's, and the list gives a
     * hint its whole width before the label, so a path pushed the name out.
     */
    private function hint(Worktree $worktree): string
    {
        $tags = [$worktree->description()];
        if ($worktree->main) {
            $tags[] = $this->translator->trans('main');
        }
        if ($worktree->locked()) {
            $tags[] = $this->translator->trans('locked');
        }
        if ($worktree->prunable) {
            $tags[] = $this->translator->trans('folder missing');
        }

        return \implode(' · ', $tags);
    }

    /**
     * Where a new worktree goes: the setting's folder (a relative one from
     * the main worktree), or the one the main worktree sits in — Desktop's default is the folder it clones into,
     * and the main worktree is that clone.
     *
     * @param list<Worktree> $worktrees
     */
    private function folderFor(array $worktrees): string
    {
        $main = \array_values(\array_filter($worktrees, static fn (Worktree $w): bool => $w->main));
        $repository = [] === $main ? $this->rootDir : $main[0]->path;
        $setting = PortaHome::expand(\rtrim(\trim(($this->folder)()), characters: '/'));
        if ('' === $setting) {
            return \dirname($repository);
        }

        // Relative to the repository, as git would read it — and made whole
        // here, because the same path opened later is read from wherever
        // Porta was started.
        return \str_starts_with($setting, '/') ? $setting : $repository.'/'.$setting;
    }

    /**
     * $path for a title, which is cut at its END when too long — and the end
     * is the folder being named. So a long one gives up its start instead:
     * `…/parent/name` still says where it goes.
     */
    private static function shown(string $path): string
    {
        $shown = PortaHome::tilde($path);

        return \mb_strlen($shown) <= self::TITLE_PATH ? $shown : '…/'.\basename(\dirname($path)).'/'.\basename($path);
    }
}
