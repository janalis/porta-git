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
        $this->pump(0.4);

        $this->assertFalse(Probe::get($status, 'rerun'), 'the rerun was consumed, not left armed');
        $this->assertFalse(Probe::get($status, 'inFlight'), 'and the second run finished too');
        $this->assertNotSame([], $this->statuses->all(), 'the marks landed');
    }

    private function api(): PluginApi
    {
        $harness = GitPluginHarness::build(['a.txt' => "one\ntwo\n"]);
        $this->project = $harness->project;
        $this->statuses = $harness->statuses;

        return $harness->api;
    }
}
