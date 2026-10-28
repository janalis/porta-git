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

    /** A config git cannot read: the unconditional half, and a git that will refuse to run anyway. */
    public function testAConfigGitCannotListGetsTheUnconditionalHalf(): void
    {
        \file_put_contents($this->project->path('.git/config'), data: "[filter \"x\"]\n\tclean = /project/evil\n[broken\n");

        $this->assertSame(self::UNCONDITIONAL, $this->arguments());
    }

    /** @return list<string> */
    private function arguments(): array
    {
        return UntrustedConfig::arguments($this->git, $this->project->dir);
    }
}
