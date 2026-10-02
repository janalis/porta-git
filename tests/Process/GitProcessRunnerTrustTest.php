<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\TestCase;
use Porta\Config\WorkspaceTrust;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;

/**
 * A repository's own config can name a program git runs on every status —
 * `core.fsmonitor` — and the status poll runs with no one asking. Untrusted,
 * that program does not run; trusted, it does (the control: without it, a git
 * that ignored the hook anyway would pass the first half for free).
 */
final class GitProcessRunnerTrustTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;
    use UntrustsProjects;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
    }

    public function testAnUntrustedRepositorysFsmonitorDoesNotRun(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\nexit 1\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $project->git('config', 'core.fsmonitor', $project->path('hook.sh'));

        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertSame([], \glob($project->path('ran-*')), 'untrusted: the hook never ran');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertNotSame([], \glob($project->path('ran-*')), 'trusted: it does — so the first half was not luck');
    }

    public function testAnUntrustedRepositorysFsmonitorDoesNotRunForAVerbEither(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\nexit 1\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $project->git('config', 'core.fsmonitor', $project->path('hook.sh'));

        $done = false;
        new GitProcessRunner($project->dir)->runWithErrors(['status', '--porcelain'], static function () use (&$done): void {
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the status to answer');

        $this->assertSame([], \glob($project->path('ran-*')));
    }

    private function pollStatus(GitProcessRunner $runner): void
    {
        $done = false;
        $runner->runStatus(['status', '--porcelain'], static function () use (&$done): void {
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the status to answer');
    }
}
