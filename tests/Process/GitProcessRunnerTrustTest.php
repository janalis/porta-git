<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\WorkspaceTrust;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Support\Outcome;
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

        $this->assertSame(0, $this->pollStatus(new GitProcessRunner($project->dir)), 'untrusted git still answers — a git that failed outright would run no hook either');
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
        $this->assertSame(0, $this->pollStatus(new GitProcessRunner($project->dir)), 'untrusted git still answers');
        $this->assertSame([], \glob($project->path('ran-*')), 'untrusted: the filter never ran');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $touch();
        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertNotSame([], \glob($project->path('ran-*')), 'trusted: it does — so the first half was not luck');
    }

    /**
     * Untrusted is a narrower git, not a stripped one: what the IDE inherited
     * reaches it, and so does what a verb puts on top — the unattended
     * editor, without which a continue would wait on one nobody is at.
     */
    public function testAnUntrustedGitKeepsItsEnvironment(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n"]);
        $project->initGit();
        $project->commitAll('init');
        $runner = new GitProcessRunner($project->dir);

        $saved = \array_map(\getenv(...), ['GIT_AUTHOR_NAME' => 'GIT_AUTHOR_NAME', 'GIT_EDITOR' => 'GIT_EDITOR']);
        \putenv('GIT_AUTHOR_NAME=Inherited Name');
        \putenv('GIT_EDITOR=false'); // an editor that fails: only the verb's own lets the amend through
        try {
            $ident = null;
            $runner->run(['var', 'GIT_AUTHOR_IDENT'], static function (string $out) use (&$ident): void {
                $ident = $out;
            });
            $amended = null;
            $runner->runUnattended(['commit', '--amend'], static function (Outcome $ok) use (&$amended): void {
                $amended = $ok;
            });
            $this->await(static function () use (&$ident, &$amended): bool {
                return null !== $ident && null !== $amended;
            }, what: 'both to answer');
        } finally {
            foreach ($saved as $name => $value) {
                \putenv(false === $value ? $name : $name.'='.$value);
            }
        }

        $this->assertStringStartsWith('Inherited Name <', (string) $ident, 'the environment it inherits');
        $this->assertTrue($amended?->isOk(), 'and the editor the verb sets over it');
    }

    /**
     * What its config names besides a monitor and an in-tree filter — each a
     * program git runs on something the IDE does by itself or on one click:
     * a hook in .git/hooks and one in config on a stage, a filter that
     * `.git/info/attributes` names (no GIT_ATTR_SOURCE reaches it) on the
     * poll, a textconv on the gutter's diff, a `gpg.program` behind
     * `log.showSignature` on the history. Untrusted none runs and git still
     * answers; trusted, each does — the control per case.
     *
     * @return iterable<string, array{\Closure(TempProject): void, list<string>}>
     */
    public static function programs(): iterable
    {
        yield 'a hook in .git/hooks' => [
            static function (TempProject $project): void {
                $project->write('.git/hooks/post-index-change', "#!/bin/sh\nexec ".\escapeshellarg($project->path('hook.sh'))."\n");
                \chmod($project->path('.git/hooks/post-index-change'), permissions: 0o755);
            },
            ['add', 'a.txt'],
        ];
        yield 'a hook in config' => [
            static function (TempProject $project): void {
                $project->git('config', 'hook.lint.command', $project->path('hook.sh'));
                $project->git('config', 'hook.lint.event', 'post-index-change');
            },
            ['add', 'a.txt'],
        ];
        yield 'a filter .git/info/attributes names' => [
            static function (TempProject $project): void {
                $project->write('.git/info/attributes', "a.txt filter=x\n");
                $project->git('config', 'filter.x.clean', $project->path('hook.sh'));
                $project->git('config', 'filter.x.required', 'true');
            },
            ['status', '--porcelain'],
        ];
        yield 'a textconv .git/info/attributes names' => [
            static function (TempProject $project): void {
                $project->write('.git/info/attributes', "a.txt diff=x\n");
                $project->git('config', 'diff.x.textconv', $project->path('hook.sh'));
            },
            ['diff', '--no-color', '--no-ext-diff', 'HEAD', '--', 'a.txt'],
        ];
        yield 'a gpg.program on the history' => [
            static function (TempProject $project): void {
                // a signature git will try to check: nobody's key, any program
                $commit = \sprintf(
                    "tree %s\nparent %s\nauthor a <a@b> 1700000000 +0000\ncommitter a <a@b> 1700000000 +0000\ngpgsig -----BEGIN PGP SIGNATURE-----\n \n x\n -----END PGP SIGNATURE-----\n\nsigned\n",
                    $project->git('rev-parse', 'HEAD^{tree}'),
                    $project->git('rev-parse', 'HEAD'),
                );
                $project->write('signed', $commit);
                $project->git('reset', '-q', '--soft', $project->git('hash-object', '-t', 'commit', '-w', $project->path('signed')));
                $project->git('config', 'log.showSignature', 'true');
                $project->git('config', 'gpg.program', $project->path('hook.sh'));
            },
            ['log', '-n', '1', '--format=%H'],
        ];
    }

    /**
     * @param \Closure(TempProject): void $plant
     * @param list<string>                $verb
     */
    #[DataProvider('programs')]
    public function testAnUntrustedRepositorysProgramDoesNotRun(\Closure $plant, array $verb): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $plant($project);
        $run = function () use ($project, $verb): Outcome {
            \array_map(\unlink(...), (array) \glob($project->path('ran-*')));
            \file_put_contents($project->path('a.txt'), data: "b\n");
            \touch($project->path('a.txt'), \time() + 5); // stat no longer matches the index: re-hashed

            return $this->verb(new GitProcessRunner($project->dir), $verb);
        };

        $this->assertTrue($run()->isOk(), 'untrusted git still answers');
        $this->assertSame([], \glob($project->path('ran-*')), 'untrusted: it never ran');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $run();
        $this->assertNotSame([], \glob($project->path('ran-*')), 'trusted: it does — so the first half was not luck');
    }

    /**
     * A config that includes a file of the tree: what a pull brings into it
     * after the runner first read the config is obeyed by git — so it is
     * read again, and the filter it now defines does not run either.
     */
    public function testWhatTheConfigComesToIncludeLaterDoesNotRunEither(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.txt' => "a\n", 'included.cfg' => '', 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->commitAll('init');
        $project->git('config', 'include.path', '../included.cfg');
        $project->write('.git/info/attributes', "a.txt filter=x\n");
        $runner = new GitProcessRunner($project->dir);
        $this->assertSame(0, $this->pollStatus($runner), 'read once, with nothing to override');

        $project->write('included.cfg', "[filter \"x\"]\n\tclean = ".$project->path('hook.sh')."\n");
        \touch($project->path('a.txt'), \time() + 5);
        $this->pollStatus($runner);

        $this->assertSame([], \glob($project->path('ran-*')));
    }

    /**
     * A status looks into a submodule, and there git obeys the submodule's
     * own config — `.git/modules/<name>/config`, which nobody read. Untrusted,
     * it does not look; trusted, the submodule's filter runs: the control.
     */
    public function testASubmodulesOwnConfigIsNotReached(): void
    {
        $this->untrustProjects();
        $library = new TempProject(['s.txt' => "s\n"]);
        $library->initGit();
        $library->commitAll('library');
        $project = new TempProject(['hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->git('-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', $library->dir, 'lib');
        $project->commitAll('with lib');
        $project->git('config', '--file', '.git/modules/lib/config', 'filter.x.clean', $project->path('hook.sh'));
        $project->write('.git/modules/lib/info/attributes', "s.txt filter=x\n");
        $touch = static function () use ($project): void {
            \array_map(\unlink(...), (array) \glob($project->path('ran-*')));
            \touch($project->path('lib/s.txt'), \time() + 5);
        };

        $touch();
        $this->assertSame(0, $this->pollStatus(new GitProcessRunner($project->dir)));
        $this->assertSame([], \glob($project->path('ran-*')), 'untrusted: the submodule\'s filter never ran');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($project->dir);
        $touch();
        $this->pollStatus(new GitProcessRunner($project->dir));
        $this->assertNotSame([], \glob($project->path('ran-*')), 'trusted: it does');
    }

    /**
     * A merge driver its config defines, named by `.git/info/attributes`:
     * untrusted, it is git's own merge-file that merges — the two sides'
     * edits both land, where an empty driver would have called it a conflict.
     */
    public function testAnUntrustedRepositorysMergeDriverIsGitsOwn(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['m.txt' => "1\n2\n3\n", 'driver.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\nexit 1\n"]);
        \chmod($project->path('driver.sh'), permissions: 0o755);
        $project->initGit('main');
        $project->commitAll('base');
        $project->git('checkout', '-q', '-b', 'side');
        $project->write('m.txt', "1\n2\n3x\n");
        $project->commitAll('side');
        $project->git('checkout', '-q', 'main');
        $project->write('m.txt', "1x\n2\n3\n");
        $project->commitAll('main');
        $project->write('.git/info/attributes', "m.txt merge=x\n");
        $project->git('config', 'merge.x.driver', $project->path('driver.sh').' %A');

        $merged = null;
        new GitProcessRunner($project->dir)->runUnattended(['merge', 'side'], static function (Outcome $ok) use (&$merged): void {
            $merged = $ok;
        });
        $this->await(static function () use (&$merged): bool {
            return null !== $merged;
        }, what: 'the merge to answer');

        $this->assertSame([], \glob($project->path('ran-*')), 'the driver never ran');
        $this->assertTrue($merged?->isOk(), 'and the merge went through');
        $this->assertSame("1x\n2\n3x\n", \file_get_contents($project->path('m.txt')), 'both sides\' edits');
    }

    /** @param list<string> $args */
    private function verb(GitProcessRunner $runner, array $args): Outcome
    {
        $ok = null;
        $runner->runCapturing($args, static function (Outcome $outcome) use (&$ok): void {
            $ok = $outcome;
        });
        $this->await(static function () use (&$ok): bool {
            return null !== $ok;
        }, what: 'git to answer');

        return $ok ?? Outcome::Failed;
    }

    /** @return int the status's exit code */
    private function pollStatus(GitProcessRunner $runner): int
    {
        $code = null;
        $runner->runStatus(['status', '--porcelain'], static function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->await(static function () use (&$code): bool {
            return null !== $code;
        }, what: 'the status to answer');

        return (int) $code;
    }
}
