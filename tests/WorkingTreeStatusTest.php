<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Tests\Support\GitPluginHarness;
use Porta\GitPlugin\WorkingTreeStatus;
use Porta\Plugin\Api\PluginApi;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tree\FileStatuses;

/**
 * The whole-tree status marks, and the one thing about them that is timing:
 * a refresh asked for while one is already running.
 */
final class WorkingTreeStatusTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;
    private FileStatuses $statuses;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
    }

    public function testARefreshDuringOneInFlightRerunsExactlyOnce(): void
    {
        // A save landing while a status is running must still produce
        // post-save marks: the second request is coalesced into one rerun that
        // follows the answer, rather than being dropped or doubling the work.
        $api = $this->api();
        $this->project->initGit();
        $this->project->commitAll('init');
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $status = new WorkingTreeStatus(new GitInfo($this->project->dir), $api, $this->project->dir);

        $status->refresh();
        $status->refresh(); // still in flight: this one becomes the rerun
        // The control: without these, two SEQUENTIAL runs would satisfy every
        // assertion below and the in-flight path would go untested.
        $this->assertTrue(Probe::get($status, 'inFlight'), 'the first run is still going');
        $this->assertTrue(Probe::get($status, 'rerun'), 'so the second was armed, not started');

        // Two serial `git status` subprocesses, so a fixed pump was a gap: the
        // marks landing with nothing in flight is the barrier. In the callback
        // the rerun re-arms inFlight synchronously, so there is no window where
        // both hold before the SECOND run has answered.
        $this->await(
            fn (): bool => [] !== $this->statuses->statuses && false === Probe::get($status, 'inFlight'),
            what: 'both status runs to finish',
        );

        $this->assertFalse(Probe::get($status, 'rerun'), 'the rerun was consumed, not left armed');
        $this->assertFalse(Probe::get($status, 'inFlight'), 'and the second run finished too');
        $this->assertNotSame([], $this->statuses->statuses, 'the marks landed');
    }

    public function testOutsideARepositoryNothingIsPublished(): void
    {
        // No repository, so no status: whatever the tree shows stays. Asked
        // anyway, the runner answers an empty status — published, it would
        // wipe marks nobody asked to clear.
        $api = $this->api();
        $api->setFileStatuses([$this->project->path('a.txt') => 'added']);
        $marked = $this->statuses->statuses;
        $this->assertNotSame([], $marked, 'the control: the mark is there');

        new WorkingTreeStatus(new GitInfo($this->project->dir), $api, $this->project->dir)->refresh();

        // Synchronous either way: with no repository the runner answers in
        // the same call, so a publish would already have landed.
        $this->assertSame($marked, $this->statuses->statuses);
    }

    private function api(): PluginApi
    {
        $harness = GitPluginHarness::build(['a.txt' => "one\ntwo\n"]);
        $this->project = $harness->project;
        $this->statuses = $harness->statuses;

        return $harness->api;
    }
}
