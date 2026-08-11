<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\Support\AsyncProcess;

/**
 * A tracked, cancellable set of git subprocesses.
 *
 * `GitProcessRunner` and `GitRemote` each owned one of these inline — the same
 * keyed map, the same `unset` in the exit callback, the same `cancelAll` loop,
 * written twice. What differs between them is the COMMAND (the remote verbs
 * take no `--no-optional-locks`, set `GIT_TERMINAL_PROMPT`, and want stderr and
 * stdout combined rather than the first stderr line), and command shape is not
 * what a lane is about. So the caller builds the {@see AsyncProcess} and the
 * lane owns what happens to it after.
 *
 * **A lane is a cancellation scope, not a queue.** Nothing here serialises:
 * `start()` spawns immediately, and the map exists so that a set of in-flight
 * processes can be cancelled together and counted. Two lanes exist in this
 * plugin because two things need cancelling separately — the user-facing
 * "Cancel Remote Operation" must stop a hung fetch without stopping the status
 * poll, and `isBusy()` must mean "a remote operation is running", not "git is
 * running". Anything that does not need its own cancellation scope shares one.
 *
 * That distinction matters because it was got wrong: three lanes existed on the
 * stated grounds that blame "has no business queueing behind the view's status
 * and diff traffic". There was never a queue to be behind, and the cost of the
 * belief was real — shutdown cancelled one lane of four, and the E2e completion
 * barrier awaited one of four.
 */
final class ProcessLane
{
    /** @var array<int, AsyncProcess> in flight right now */
    private array $running = [];

    private int $nextKey = 0;

    /**
     * Spawn $process and track it until it exits; false when it could not be
     * spawned, in which case $onExit never fires and nothing is tracked.
     *
     * $onExit receives the exit code and stdout, plus the process itself — the
     * callers that capture stderr read it off there, and handing it over beats
     * making them close over a variable they also passed in.
     *
     * @param \Closure(int, string, AsyncProcess): void $onExit
     */
    public function start(AsyncProcess $process, \Closure $onExit): bool
    {
        $key = $this->nextKey++;
        $started = $process->start(function (int $code, string $out) use ($key, $process, $onExit): void {
            unset($this->running[$key]);
            $onExit($code, $out, $process);
        });
        // Tracked only when it spawned, and returned rather than branched on:
        // `AsyncProcess::start()`'s false is documented unreachable in tests
        // (proc_open spawns even an unusable binary — the failure surfaces as
        // an exit code), so a guard clause here would be a line no test can
        // reach for a case no caller can produce.
        if ($started) {
            $this->running[$key] = $process;
        }

        return $started;
    }

    /**
     * Cancel everything in this lane.
     *
     * A cancelled `AsyncProcess` never fires its callback, so whatever was
     * waiting on one of these is waiting forever — every caller of this either
     * is shutting down or says so itself (the remote commands flash "cancelled",
     * because no `GitRemoteCompleted` will arrive to say it for them).
     */
    public function cancelAll(): void
    {
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }

    /** How many are in flight — the E2e completion barrier's question. */
    public function inFlight(): int
    {
        return \count($this->running);
    }
}
