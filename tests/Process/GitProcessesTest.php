<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\GitProcesses;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\Support\AsyncProcess;
use Porta\Tests\Support\PumpsLoop;

/**
 * The two lanes, and the one verb that has to reach both.
 *
 * Written because a mutation sweep found that nothing did: dropping
 * `$this->remote->cancelAll()` from the group left the whole suite green, which
 * is the same shape of hole the group exists to close. Shutdown used to cancel
 * one runner of the four the plugin had, and a fetch, a blame or a mark refresh
 * could outlive the event loop.
 *
 * Driven with `sleep`, not with git: what is under test is the tracking, and a
 * real git call would make the timing the subject instead.
 */
final class GitProcessesTest extends TestCase
{
    use PumpsLoop;

    private function sleeper(ProcessLane $lane): void
    {
        $started = $lane->start(new AsyncProcess(['sleep', '30'], \sys_get_temp_dir()), static function (): void {
            // A cancelled process never calls back — that is the point.
        });
        $this->assertTrue($started, 'the fixture process spawned');
    }

    public function testCancelAllEmptiesBothLanes(): void
    {
        $processes = new GitProcesses();
        $this->sleeper($processes->general);
        $this->sleeper($processes->general);
        $this->sleeper($processes->remote);

        $this->assertSame(2, $processes->general->inFlight());
        $this->assertSame(1, $processes->remote->inFlight());

        $processes->cancelAll();

        $this->assertSame(0, $processes->general->inFlight(), 'the general lane stopped');
        $this->assertSame(0, $processes->remote->inFlight(), 'and so did the remote one');
    }

    public function testALaneCancelsOnlyItsOwn(): void
    {
        // The reason there are two. "Cancel Remote Operation" must stop a hung
        // fetch without stopping the status poll, and a single lane would make
        // that verb mean "stop all git".
        $processes = new GitProcesses();
        $this->sleeper($processes->general);
        $this->sleeper($processes->remote);

        $processes->remote->cancelAll();

        $this->assertSame(1, $processes->general->inFlight(), 'the status lane is untouched');
        $this->assertSame(0, $processes->remote->inFlight());

        $processes->cancelAll();
    }

    public function testAFinishedProcessLeavesTheLaneOnItsOwn(): void
    {
        // Otherwise `inFlight()` only ever grows, and the completion barrier
        // that reads it never returns — a timeout rather than a wrong answer,
        // but a timeout in every test that touches git.
        $lane = new ProcessLane();
        $done = false;
        $lane->start(new AsyncProcess(['true'], \sys_get_temp_dir()), static function () use (&$done): void {
            $done = true;
        });

        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the process to exit');

        $this->assertSame(0, $lane->inFlight(), 'and it untracked itself');
    }
}
