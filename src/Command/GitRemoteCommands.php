<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Event\GitRemoteCompleted;
use Porta\GitPlugin\Process\GitRemote;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\Support\Outcome;
use Porta\Support\Text;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The remote git actions behind the palette/menu/git-view keys: fetch, pull
 * and push, each an async {@see GitRemote} call framed by status flashes. The
 * start flash is long-lived so it persists until the completion flash
 * replaces it; failures surface git's own first stderr line (auth, diverged
 * branches, missing upstream). Two failures get a follow-up choice instead of
 * a dead end: a diverged pull offers Rebase / Merge, and a missing upstream
 * offers --set-upstream. Completion dispatches {@see GitRemoteCompleted} so
 * the branch/marks/history refresh listeners react without coupling.
 */
final readonly class GitRemoteCommands
{
    /** The one toast a remote operation owns: its progress, then its outcome, in place. */
    private const string SLOT = 'git.remote';

    public function __construct(
        private GitRemote $remote,
        private Flash $notifier,
        private Translate $translator,
        private EventDispatcherInterface $dispatcher,
        private Modals $editor,
        /** @var \Closure(): void asks for a frame */
        private \Closure $repaint,
    ) {}

    public function fetch(): void
    {
        $this->operate(GitRemoteOperation::Fetch, $this->translator->trans('Fetching…'), $this->translator->trans('Fetched'), fn (callable $onDone) => $this->remote->fetch($onDone));
    }

    public function pull(): void
    {
        $this->operate(
            GitRemoteOperation::Pull,
            $this->translator->trans('Pulling…'),
            $this->translator->trans('Pulled'),
            fn (callable $onDone) => $this->remote->pull($onDone),
            onFailure: function (string $output): bool {
                if (false === \stripos($output, needle: 'fast-forward')) {
                    return false;
                }
                $this->offer(
                    $this->translator->trans('Pull: branch diverged'),
                    [
                        ['label' => $this->translator->trans('Rebase local commits onto the remote'), 'hint' => '', 'value' => 'rebase'],
                        ['label' => $this->translator->trans('Merge the remote into the local branch'), 'hint' => '', 'value' => 'merge'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                    ],
                    function (string $choice): void {
                        match ($choice) {
                            'rebase' => $this->operate(
                                GitRemoteOperation::Pull,
                                $this->translator->trans('Pulling…'),
                                $this->translator->trans('Pulled'),
                                fn (callable $onDone) => $this->remote->pullRebase($onDone),
                            ),
                            'merge' => $this->operate(
                                GitRemoteOperation::Pull,
                                $this->translator->trans('Pulling…'),
                                $this->translator->trans('Pulled'),
                                fn (callable $onDone) => $this->remote->pullMerge($onDone),
                            ),
                            default => null,
                        };
                    },
                );

                return true;
            },
        );
    }

    public function push(): void
    {
        $this->operate(
            GitRemoteOperation::Push,
            $this->translator->trans('Pushing…'),
            $this->translator->trans('Pushed'),
            fn (callable $onDone) => $this->remote->push($onDone),
            onFailure: function (string $output): bool {
                if (false === \stripos($output, needle: 'no upstream')) {
                    return false;
                }
                // The branch name is parsed from git's own hint for the LABEL
                // only — the pick runs the plain -u origin HEAD push.
                $m = [];
                $branch = 1 === \preg_match('/git push --set-upstream \S+ (\S+)/', $output, $m) ? $m[1] : 'HEAD';
                $this->offer(
                    $this->translator->trans('Push: no upstream branch'),
                    [
                        ['label' => $this->translator->trans('Push with --set-upstream origin %branch%', ['%branch%' => $branch]), 'hint' => '', 'value' => 'upstream'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                    ],
                    function (string $choice): void {
                        if ('upstream' === $choice) {
                            $this->pushSetUpstream();
                        }
                    },
                );

                return true;
            },
        );
    }

    public function pushSetUpstream(): void
    {
        $this->operate(GitRemoteOperation::Push, $this->translator->trans('Pushing…'), $this->translator->trans('Pushed'), fn (callable $onDone) => $this->remote->pushSetUpstream($onDone));
    }

    /**
     * The escape hatch for a hung remote operation (an ssh passphrase prompt
     * without an agent, an unreachable host). Cancelled AsyncProcess
     * callbacks never fire, so this flash is the only completion signal —
     * and no GitRemoteCompleted is synthesized.
     */
    public function cancelOperation(): void
    {
        if (!$this->remote->isBusy()) {
            $this->notifier->flash($this->translator->trans('No git remote operation is running'));

            return;
        }
        $this->remote->cancelAll();
        // A cancelled operation never calls back, so this is its outcome.
        $this->notifier->warn($this->translator->trans('Git remote operation cancelled'), slot: self::SLOT);
    }

    /** Route a git-view remote key to its operation. */
    public function runOperation(GitRemoteOperation $operation): void
    {
        match ($operation) {
            GitRemoteOperation::Fetch => $this->fetch(),
            GitRemoteOperation::Pull => $this->pull(),
            GitRemoteOperation::Push => $this->push(),
        };
    }

    /**
     * @param \Closure(callable(Outcome, string): void): void $start
     * @param (\Closure(string): bool)|null                $onFailure sees the full output; true = it owns the follow-up
     *                                                     (the generic failure flash is suppressed; the event still fires)
     */
    private function operate(GitRemoteOperation $operation, string $started, string $succeeded, \Closure $start, ?\Closure $onFailure = null): void
    {
        if (!$this->remote->isAvailable()) {
            $this->notifier->error($this->translator->trans('Not a git repository (or git missing)'));

            return;
        }
        if ($this->remote->isBusy()) {
            $this->notifier->warn($this->translator->trans('A git remote operation is already running'));

            return;
        }
        // Sticky until the outcome replaces it in the same slot — the card
        // turns into the result where it stands rather than lingering beside it.
        $this->notifier->flash($started, 600.0, self::SLOT);
        $start(function (Outcome $ok, string $output) use ($operation, $succeeded, $onFailure): void {
            if (!$ok->isOk() && null !== $onFailure && $onFailure($output)) {
                // The follow-up choice is the answer; the progress card goes.
                $this->notifier->warn($this->translator->trans('%operation% needs a choice', ['%operation%' => \ucfirst($operation->value)]), 3.0, self::SLOT);
                $this->dispatcher->dispatch(new GitRemoteCompleted($operation->value, false));

                return;
            }
            $ok->isOk()
                ? $this->notifier->success($succeeded, 3.0, self::SLOT)
                : $this->notifier->error(
                    $this->translator->trans('%operation% failed: %error%', ['%operation%' => \ucfirst($operation->value), '%error%' => Text::firstLine($output)]),
                    6.0,
                    self::SLOT,
                );
            $this->dispatcher->dispatch(new GitRemoteCompleted($operation->value, $ok->isOk()));
        });
    }

    /**
     * A follow-up choice in the editor's list overlay. The overlay lives
     * inside the editor, so whichever full view owns the main area yields
     * first — the same interlock the branch picker uses.
     *
     * @param list<array{label: string, hint: string, value: string}> $items
     * @param \Closure(string): void                                  $onPick
     */
    private function offer(string $title, array $items, \Closure $onPick): void
    {
        $this->editor->openListOverlay($title, $items, $onPick);
        ($this->repaint)();
    }
}
