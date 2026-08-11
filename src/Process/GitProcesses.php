<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\GitPlugin\GitInfo;

/**
 * Every git subprocess this plugin can have in flight, in two lanes.
 *
 * `general` carries the status polls, diffs, staging, commits, blame and gutter
 * marks. `remote` carries fetch, pull and push, and is separate for one reason:
 * "Cancel Remote Operation" must stop a hung fetch without stopping the status
 * poll, and `GitRemote::isBusy()` must mean "a remote operation is running"
 * rather than "git is running". Cancellation scope is the whole of it — see
 * {@see ProcessLane} for why it is not a queue.
 *
 * This exists so that two questions have ONE answer each:
 *
 * - **Shutdown.** `$api->onShutdown()` cancels this, not one handle's runner.
 *   It used to cancel `GitInfo`'s alone, which was one lane of the four the
 *   plugin then had — a fetch, a blame and a mark refresh could outlive it.
 * - **"Is anything still running?"** — the E2e completion barrier, which reads
 *   both lanes off the public properties rather than through a summing verb
 *   here: a plugin never asks "is ANY git running?", so such a verb would have
 *   only the barrier as a caller, and that is test-only code wearing a class.
 *   git mutates the working tree before its process exits, so an empty set is
 *   what "the operation landed on disk" means. A barrier that saw one lane let
 *   a test walk out with work in flight — a flake and a `[loop-leak]` that
 *   nothing expressed.
 */
final readonly class GitProcesses
{
    public function __construct(
        public ProcessLane $general = new ProcessLane(),
        public ProcessLane $remote = new ProcessLane(),
    ) {}

    /** Stop everything, both lanes. Shutdown's verb. */
    public function cancelAll(): void
    {
        $this->general->cancelAll();
        $this->remote->cancelAll();
    }
}
