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

    /** The verbs' runner (stderr kept) too: neither the monitor nor the filter. */
    public function testNeitherRunsForAVerbEither(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", '.gitattributes' => "a.txt filter=x\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $project->git('config', 'filter.x.clean', $project->path('hook.sh'));
        \array_map(\unlink(...), (array) \glob($project->path('ran-*')));
        \touch($project->path('a.txt'), \time() + 5);

        foreach (['filter' => [], 'monitor' => ['core.fsmonitor', $project->path('hook.sh')]] as $what => $config) {
            if ([] !== $config) {
                $project->git('config', ...$config);
            }
            $done = false;
            new GitProcessRunner($project->dir)->runWithErrors(['status', '--porcelain'], static function () use (&$done): void {
                $done = true;
            });
            $this->await(static function () use (&$done): bool {
                return $done;
            }, what: 'the status to answer');

            $this->assertSame([], \glob($project->path('ran-*')), $what);
        }
    }

    /**
     * A filter driver the repository's own config defines, named by its own
     * `.gitattributes`: a status re-hashing a touched file runs its `clean`.
     * Untrusted, attributes are read from nowhere; trusted, the control.
     */
    public function testAnUntrustedRepositorysFilterDriverDoesNotRun(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", '.gitattributes' => "a.txt filter=x\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $project->git('config', 'filter.x.clean', $project->path('hook.sh'));
        $touch = static function () use ($project): void {
            \array_map(\unlink(...), (array) \glob($project->path('ran-*')));
            \touch($project->path('a.txt'), \time() + 5); // stat no longer matches the index: re-hashed
        };

        $touch();
        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertSame([], \glob($project->path('ran-*')), 'untrusted: the filter never ran');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $touch();
        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertNotSame([], \glob($project->path('ran-*')), 'trusted: it does — so the first half was not luck');
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
