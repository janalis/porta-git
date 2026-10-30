<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\UntrustedConfig;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The overrides themselves, read off a real `git config --list`: every key
 * whose value is a command, set by the repository, comes back neutral; the
 * user's own value for it follows; a key naming no program is not touched.
 * Whether git then runs anything is GitProcessRunnerTrustTest's to show.
 */
final class UntrustedConfigTest extends TestCase
{
    use GitBinary;

    /** What every untrusted repository gets, whatever its config says. */
    private const array UNCONDITIONAL = [
        '-c',
        'core.fsmonitor=false',
        '-c',
        'core.hookspath=/dev/null',
        '-c',
        'protocol.ext.allow=never',
        '-c',
        'protocol.git.allow=never',
        '-c',
        'gc.auto=0',
        '-c',
        'maintenance.auto=false',
        '-c',
        'diff.ignoreSubmodules=all',
        '-c',
        'submodule.recurse=false',
        '-c',
        'fetch.recurseSubmodules=false',
        '-c',
        'push.recurseSubmodules=no',
    ];

    private TempProject $project;

    private TempProject $home;

    private string $git;

    #[Before]
    protected function isolateTheUsersConfig(): void
    {
        $this->skipWithoutGit();
        $this->git = (string) $this->whichGit();
        $this->home = new TempProject(['.gitconfig' => '']);
        \putenv('GIT_CONFIG_GLOBAL='.$this->home->path('.gitconfig'));
        \putenv('GIT_CONFIG_NOSYSTEM=1');
        $this->project = new TempProject();
        $this->project->initGit();
    }

    #[After]
    protected function restoreTheUsersConfig(): void
    {
        \putenv('GIT_CONFIG_GLOBAL');
        \putenv('GIT_CONFIG_NOSYSTEM');
    }

    /** @return iterable<string, array{string, string}> */
    public static function programs(): iterable
    {
        foreach ([
            'hook.lint.command' => 'true',
            'filter.x.clean' => '',
            'filter.x.smudge' => '',
            'filter.x.process' => '',
            'filter.x.required' => 'false',
            'diff.x.textconv' => 'cat',
            'diff.x.command' => '',
            'diff.external' => '',
            'log.showsignature' => 'false',
            'gpg.program' => 'gpg',
            'gpg.openpgp.program' => 'gpg',
            'gpg.x509.program' => 'gpgsm',
            'gpg.ssh.program' => 'ssh-keygen',
            'gpg.ssh.defaultkeycommand' => '',
            'core.sshcommand' => 'ssh',
            'core.askpass' => '',
            'core.editor' => '',
            'core.alternaterefscommand' => '',
            'sequence.editor' => '',
            'credential.helper' => '',
            'credential.https://example.com.helper' => '',
            'trailer.sign.cmd' => '',
            'trailer.sign.command' => '',
            'interactive.difffilter' => '',
            'lfs.customtransfer.x.path' => '',
            'lfs.standalonetransferagent' => '',
            'lfs.extension.x.clean' => '',
            'lfs.extension.x.smudge' => '',
        ] as $key => $neutral) {
            yield $key => [$key, $neutral];
        }
    }

    #[DataProvider('programs')]
    public function testARepositorysProgramIsReplaced(string $key, string $neutral): void
    {
        $this->project->git('config', $key, '/project/evil');

        $arguments = $this->arguments();

        $this->assertContains($key.'='.$neutral, $arguments);
        $this->assertNotContains($key.'=/project/evil', $arguments, 'and the repository\'s own is not passed on');
    }

    /** A subsection's case is the repository's, and it is the key git matches. */
    public function testAMergeDriverIsGitsOwnMergeFile(): void
    {
        $this->project->git('config', 'merge.Ours.driver', '/project/evil %A');

        $this->assertContains('merge.Ours.driver='.\escapeshellarg($this->git).' merge-file --marker-size=%L -L %X -L %S -L %Y %A %O %B', $this->arguments());
    }

    /** `.git/config.worktree` is the repository's own too, once it turns per-worktree config on. */
    public function testAWorktreesOwnConfigIsTheRepositorys(): void
    {
        $this->project->git('config', 'extensions.worktreeConfig', 'true');
        $this->project->git('config', '--worktree', 'filter.x.clean', '/project/evil');

        $this->assertContains('filter.x.clean=', $this->arguments());
    }

    /**
     * The repository needs no config to fill .git/hooks; and what no
     * override can reach is off — a git:// fetch (a proxy is first-wins), an
     * ext:: remote, the automatic gc (whose hooks are a list).
     */
    public function testThreeAreReplacedWhateverTheRepositorySays(): void
    {
        $this->assertSame(self::UNCONDITIONAL, $this->arguments());
    }

    /** What is turned off stays off: the user's own value for it is not put back. */
    public function testWhatIsTurnedOffIsNotTurnedBackOn(): void
    {
        \file_put_contents($this->home->path('.gitconfig'), data: "[gc]\n\tauto = 50\n[protocol \"git\"]\n\tallow = always\n");
        $this->project->git('config', 'gc.auto', '1');

        $this->assertSame(self::UNCONDITIONAL, $this->arguments());
    }

    /** Neighbours of a listed key that name no program, and plain settings: all left to the repository. */
    public function testAKeyNamingNoProgramIsLeftAlone(): void
    {
        foreach ([
            'core.fsmonitorhookversion' => '2',
            'hook.lint.event' => 'pre-commit',
            'diff.x.cachetextconv' => 'true',
            'filter.x.cleaner' => 'x',
            'credential.usehttppath' => 'true',
            'remote.origin.url' => '/x',
            'xdiff.external' => 'x',
        ] as $key => $value) {
            $this->project->git('config', $key, $value);
        }

        $this->assertSame(self::UNCONDITIONAL, $this->arguments());
    }

    /**
     * The user's own helpers follow the reset, in their order: a credential
     * helper is a list, and an empty value clears what came before it — the
     * repository's AND the user's, which is why theirs is said again.
     */
    public function testTheUsersOwnValuesArePutBackAfterTheNeutralOne(): void
    {
        \file_put_contents($this->home->path('.gitconfig'), data: "[credential]\n\thelper = store\n\thelper = cache\n[core]\n\thooksPath = /home/hooks\n");
        $this->project->git('config', 'credential.helper', '/project/evil');

        $arguments = $this->arguments();
        $values = static fn (string $key): array => \array_values(\array_filter($arguments, static fn (string $pair): bool => \str_starts_with($pair, $key.'=')));

        $this->assertSame(['core.hookspath=/dev/null', 'core.hookspath=/home/hooks'], $values('core.hookspath'), 'a key the repository does not set: theirs over the neutral one');
        $helpers = $values('credential.helper');
        $this->assertSame('credential.helper=', $helpers[0], 'the reset first');
        // what comes between is git's own built-in helper, where it has one (Apple's: osxkeychain)
        $this->assertSame(['credential.helper=store', 'credential.helper=cache'], \array_slice($helpers, -2), 'the user\'s own last, in their order');
        $this->assertNotContains('credential.helper=/project/evil', $arguments);
    }

    /** `[log] showSignature` with no `=` is a true, and goes back as one. */
    public function testABareKeyGoesBackBare(): void
    {
        \file_put_contents($this->home->path('.gitconfig'), data: "[log]\n\tshowSignature\n");
        $this->project->git('config', 'log.showSignature', 'true');

        $arguments = $this->arguments();

        $at = (int) \array_search('log.showsignature=false', $arguments, strict: true);
        $this->assertSame(['log.showsignature=false', '-c', 'log.showsignature', '-c'], \array_slice($arguments, $at, length: 4));
    }

    /**
     * What git cannot list — a malformed config or .gitmodules, a listing
     * killed — names programs nobody knows, so none can be replaced: git is
     * told nothing it can start with. A config: nothing says what it was
     * read from, so it is read again before every spawn. A .gitmodules is
     * one file: read again once it changes.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function unlistable(): iterable
    {
        yield 'a malformed config' => ['.git/config', "[filter \"x\"]\n\tclean = /project/evil\n[broken\n", false];
        yield 'a malformed .gitmodules' => ['.gitmodules', "[submodule \"lib\"]\n\tignore = none\n[broken\n", true];
    }

    #[DataProvider('unlistable')]
    public function testWhatGitCannotListStopsGitAltogether(string $file, string $config, bool $current): void
    {
        \file_put_contents($this->project->path($file), data: $config, flags: \FILE_APPEND);
        $read = UntrustedConfig::read($this->git, $this->project->dir);

        $this->assertSame(['-c', 'porta-cannot-override-this-repository'], $read->arguments);
        $this->assertSame($current, $read->isCurrent($this->git));
        \file_put_contents($this->project->path($file), data: \str_replace('[broken', replace: '', subject: $config));
        $this->assertFalse($read->isCurrent($this->git), 'mended');
    }

    /** A listing that never ends normally — killed here, timed out alike — is one that failed, not a throw on the loop. */
    public function testAListingKilledMidwayIsOneThatFailed(): void
    {
        $git = $this->home->path('git');
        $this->home->write('git', "#!/bin/sh\necho >> \"\$(dirname \"\$0\")/calls\"\nkill -KILL \$\$\n");
        \chmod($git, permissions: 0o755);
        $this->project->write('.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n");
        $config = UntrustedConfig::read($git, $this->project->dir);

        $this->assertSame(['-c', 'porta-cannot-override-this-repository'], $config->arguments);
        $this->assertFalse($config->isCurrent($git));
        $this->assertSame("\n", \file_get_contents($this->home->path('calls')), 'nor is .gitmodules listed after it: git is refused already');
    }

    /**
     * The user's own values are carried too, so their file is watched as
     * the repository's is; and the merge driver names the git it was read
     * through, so another git reads them again.
     */
    public function testTheUsersOwnConfigAndTheGitTheyNameAreWatchedToo(): void
    {
        \file_put_contents($this->home->path('.gitconfig'), data: "[credential]\n\thelper = mine\n");
        $config = UntrustedConfig::read($this->git, $this->project->dir);
        $this->assertTrue($config->isCurrent($this->git));
        $this->assertFalse($config->isCurrent($this->git.'-other'), 'another git');

        \file_put_contents($this->home->path('.gitconfig'), data: "[credential]\n\thelper = theirs\n");

        $this->assertFalse($config->isCurrent($this->git), 'the user\'s own config');
    }

    /** Their config files are watched before they hold anything, wherever git would look for them. */
    public function testAUsersConfigWrittenLaterIsSeen(): void
    {
        \unlink($this->home->path('.gitconfig'));
        $config = UntrustedConfig::read($this->git, $this->project->dir);
        $this->home->write('.gitconfig', "[credential]\n\thelper = mine\n");
        $this->assertFalse($config->isCurrent($this->git), 'the one GIT_CONFIG_GLOBAL names');

        \putenv('GIT_CONFIG_GLOBAL');
        $home = \getenv('HOME');
        $xdg = \getenv('XDG_CONFIG_HOME');
        \putenv('HOME='.$this->home->dir);
        try {
            foreach (['.gitconfig', '.config/git/config', 'xdg/git/config'] as $file) {
                \putenv('XDG_CONFIG_HOME'.('xdg/git/config' === $file ? '='.$this->home->path('xdg') : ''));
                new Filesystem()->remove([$this->home->path('.gitconfig'), $this->home->path('.config'), $this->home->path('xdg')]);
                $config = UntrustedConfig::read($this->git, $this->project->dir);
                $this->home->write($file, "[credential]\n\thelper = mine\n");
                $this->assertFalse($config->isCurrent($this->git), $file);
            }
        } finally {
            \putenv('HOME='.$home);
            \putenv('XDG_CONFIG_HOME'.(false === $xdg ? '' : '='.$xdg));
        }
    }

    /**
     * Each submodule it declares — in .gitmodules, or only in its config —
     * is ignored by name: a `submodule.<name>.ignore` of the repository's own
     * would outrank `diff.ignoreSubmodules`, and a status that looks into a
     * submodule obeys the submodule's config, which nobody read.
     */
    public function testEverySubmoduleItDeclaresIsIgnoredByName(): void
    {
        $this->project->write('.gitmodules', "[submodule \"lib/a\"]\n\tpath = lib/a\n\tignore = none\n");
        $this->project->git('config', 'submodule.b.ignore', 'none');

        $this->assertSame(
            [...self::UNCONDITIONAL, '-c', 'submodule.b.ignore=all', '-c', 'submodule.lib/a.ignore=all'],
            $this->arguments(),
        );
    }

    /**
     * Read again only when something it was read from says otherwise: the
     * config, a file it includes from the tree — before that file exists,
     * too — the branch an `includeIf "onbranch:…"` follows, .gitmodules.
     */
    public function testItIsCurrentUntilWhatItWasReadFromChanges(): void
    {
        $this->project->git('config', 'include.path', '../included.cfg');
        $config = UntrustedConfig::read($this->git, $this->project->dir);
        $this->assertTrue($config->isCurrent($this->git));

        foreach ([
            'the file it includes' => 'included.cfg',
            'the branch' => '.git/HEAD',
            '.gitmodules' => '.gitmodules',
            'the config' => '.git/config',
            'a worktree config, before there is one' => '.git/config.worktree',
        ] as $what => $file) {
            $config = UntrustedConfig::read($this->git, $this->project->dir);
            \file_put_contents($this->project->path($file), data: "\n", flags: \FILE_APPEND);

            $this->assertFalse($config->isCurrent($this->git), $what);
        }
        $this->assertNotContains('filter.x.clean=', $config->arguments);
        $this->project->write('included.cfg', "[filter \"x\"]\n\tclean = /project/evil\n");
        $this->assertContains('filter.x.clean=', UntrustedConfig::read($this->git, $this->project->dir)->arguments, 'what it includes is the repository\'s');
    }

    /** A conditional include, and one from home: their files are watched before they exist. */
    public function testAConditionalIncludeAndAHomeOneAreWatchedToo(): void
    {
        $home = \getenv('HOME');
        \getenv('XDG_CONFIG_HOME');
        \putenv('HOME='.$this->home->dir);
        try {
            $this->project->git('config', 'includeIf.onbranch:main.path', '../branch.cfg');
            $this->project->git('config', '--add', 'include.path', '~/mine.cfg');

            foreach (['the conditional one' => $this->project->path('branch.cfg'), 'the home one' => $this->home->path('mine.cfg')] as $what => $file) {
                $config = UntrustedConfig::read($this->git, $this->project->dir);
                \file_put_contents($file, data: "\n");

                $this->assertFalse($config->isCurrent($this->git), $what);
            }
        } finally {
            \putenv(false === $home ? 'HOME' : 'HOME='.$home);
        }
    }

    /** A linked worktree's `.git` names where its HEAD is. */
    public function testALinkedWorktreesBranchIsItsOwn(): void
    {
        $this->project->write('a.txt', "a\n");
        $this->project->commitAll('init');
        $worktree = new TempProject();
        \rmdir($worktree->dir);
        $this->project->git('worktree', 'add', '-q', '-b', 'other', $worktree->dir);
        $config = UntrustedConfig::read($this->git, $worktree->dir);

        \file_put_contents($this->project->path('.git/worktrees/'.\basename($worktree->dir).'/HEAD'), data: "ref: refs/heads/main\n");

        $this->assertFalse($config->isCurrent($this->git));
    }

    /**
     * A name `-c` cannot say — git splits it at its first `=` — and the
     * override would set some other key: git is told nothing it can start
     * with instead, so it runs nothing, the repository's program least.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unsayable(): iterable
    {
        yield 'a driver' => ['.git/config', "[filter \"a=b\"]\n\tclean = /project/evil\n"];
        yield 'a submodule' => ['.gitmodules', "[submodule \"a=b\"]\n\tpath = lib\n"];
    }

    #[DataProvider('unsayable')]
    public function testANameAnOverrideCannotSayStopsGitAltogether(string $file, string $config): void
    {
        \file_put_contents($this->project->path($file), data: $config, flags: \FILE_APPEND);

        $this->assertSame(['-c', 'porta-cannot-override-this-repository'], $this->arguments());
    }

    /** @return list<string> */
    private function arguments(): array
    {
        return UntrustedConfig::read($this->git, $this->project->dir)->arguments;
    }
}
