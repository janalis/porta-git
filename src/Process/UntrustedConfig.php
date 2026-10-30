<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The `-c` overrides git runs under in a repository nobody trusted: every
 * program the repository's OWN config names — `.git/config`, and whatever it
 * includes — is replaced, and the user's own value for the same key (system,
 * global, the environment's) put back on top of it.
 *
 * A clone never carries `.git/config`, but a tarball or a shared folder does,
 * and git obeys it from the status poll with no one asking: an fsmonitor, a
 * `post-index-change` hook (from `.git/hooks`, or `hook.<name>.command`), a
 * filter driver `.git/info/attributes` names — GIT_ATTR_SOURCE keeps only the
 * in-tree attributes out — a textconv on the gutter's diff and on blame, a
 * `gpg.program` behind `log.showSignature` on the history. The rest of the
 * list runs on a verb the user asked for — a merge driver, a credential
 * helper, an ssh command — which is still the project's program, not theirs.
 *
 * An override is a `-c` after the repository's value, and most keys take the
 * last one; a few take the first, and {@see FIXED} has what is done there.
 *
 * The list is git's own (git-config(1): each key whose value is a command);
 * a key git learns later is not on it.
 */
final readonly class UntrustedConfig
{
    /** git's well-known empty tree (SHA-1): an attribute source — GIT_ATTR_SOURCE — that says nothing. */
    public const string EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /**
     * What each such key gets instead: nothing, or a stock program the
     * user's PATH finds. A NULL is the merge driver, which is git's own
     * merge-file — an empty one would fail every merge of the file.
     *
     * @var array<string, string|null>
     */
    private const array NEUTRAL = [
        '/^core\.fsmonitor$/' => 'false',
        '/^core\.hookspath$/' => '/dev/null', // .git/hooks, which the repository needs no config to fill
        '/^hook\..+\.command$/' => 'true', // config-defined hooks: core.hooksPath does not reach them
        '/^filter\..+\.(clean|smudge|process)$/' => '',
        '/^filter\..+\.required$/' => 'false', // a required filter left with no command fails the status outright
        '/^diff\..+\.textconv$/' => 'cat', // an empty one fails the diff
        '/^diff\.(.+\.command|external)$/' => '',
        '/^merge\..+\.driver$/' => null,
        '/^log\.showsignature$/' => 'false',
        '/^gpg\.(openpgp\.)?program$/' => 'gpg',
        '/^gpg\.x509\.program$/' => 'gpgsm',
        '/^gpg\.ssh\.program$/' => 'ssh-keygen',
        '/^gpg\.ssh\.defaultkeycommand$/' => '',
        '/^core\.sshcommand$/' => 'ssh',
        '/^core\.(askpass|editor|alternaterefscommand)$/' => '',
        '/^sequence\.editor$/' => '',
        '/^credential\.(.+\.)?helper$/' => '', // empty resets the list; the user's own helpers follow it
        '/^trailer\..+\.(cmd|command)$/' => '',
        '/^interactive\.difffilter$/' => '',
        // git-lfs is the user's, but it reads the repository's config through git
        // — which carries these overrides down to it
        '/^lfs\.(customtransfer\..+\.path|standalonetransferagent)$/' => '',
        '/^lfs\.extension\..+\.(clean|smudge)$/' => '',
    ];

    /** Overridden whether the repository sets them or not: it needs no config to fill .git/hooks. */
    private const array ALWAYS = ['core.fsmonitor', 'core.hookspath'];

    /**
     * Where an override cannot reach — git takes the FIRST `core.gitProxy`
     * that matches and the first `gc.recentObjectsHook` it is given runs
     * whatever follows — the thing that would run them is turned off
     * instead, and stays off: the user's own value is not put back. No
     * git:// fetch (the only one a proxy serves), no ext:: remote (which is
     * a command line; a `protocol.allow = always` lets one through), and no
     * automatic gc, which Porta never asks for. A remote's `uploadpack` and
     * `receivepack` are first-wins too: GitRemote names its own on the
     * command line.
     *
     * Nor does git go into a submodule: there it obeys the submodule's own
     * config, which this list is not read from. Each submodule the
     * repository declares is ignored by name too — a `submodule.<name>.ignore`
     * of its own outranks `diff.ignoreSubmodules`.
     */
    private const array FIXED = [
        'protocol.ext.allow' => 'never',
        'protocol.git.allow' => 'never',
        'gc.auto' => '0',
        'maintenance.auto' => 'false',
        'diff.ignoreSubmodules' => 'all',
        'submodule.recurse' => 'false',
        'fetch.recurseSubmodules' => 'false',
        'push.recurseSubmodules' => 'no',
    ];

    /**
     * Said in place of the overrides when one cannot be said: git splits a
     * `-c` at its first `=`, so a driver or a submodule named `a=b` would
     * turn `filter.a=b.clean=` into a `filter.a` nobody asked for and leave
     * the real one standing. A key with no section is one git refuses to
     * start with — so in such a repository git does not run at all.
     *
     * Nor where the config could not be listed — a malformed one, a git
     * older than 2.26 without `--show-scope`, a listing killed or timed
     * out: what it names is not known, so nothing it names can be replaced.
     */
    private const string REFUSED = 'porta-cannot-override-this-repository';

    /** The scopes that are the repository's own: everything else is the user's. */
    private const array REPOSITORY = ['local', 'worktree'];

    /**
     * @param list<string>          $arguments `-c` pairs, to go between `git` and its verb
     * @param string                $git       the git they were read through, which the merge driver names
     * @param array<string, string> $read      each file they were read from — the user's own
     *                                         too, whose values they carry — and each that
     *                                         would change them, by its contents' digest
     * @param bool                  $complete  whether git listed the config at all — a
     *                                         .gitmodules it could not is one file, watched
     */
    private function __construct(
        public array $arguments,
        private string $git,
        private array $read,
        private bool $complete,
    ) {}

    /**
     * The overrides for the repository at $rootDir, read through $git. A
     * config git cannot list gets {@see REFUSED}, and is read again on the
     * next spawn, since nothing says what it was read from; a .gitmodules git
     * cannot list, only once that file changes.
     */
    public static function read(string $git, string $rootDir): self
    {
        $repository = \array_fill_keys(self::ALWAYS, value: true);
        $own = [];
        $submodules = [];
        $head = self::head($rootDir);
        // config.worktree before extensions.worktreeConfig turns it on: no listing names it yet
        $files = [$rootDir.'/.gitmodules', $head, \dirname($head).'/config.worktree', ...self::usersOwn()];
        $listing = self::listing($git, $rootDir, ['--show-scope', '--show-origin']);
        // `scope NUL origin NUL key LF value NUL`, the LF and value missing on a bare `key` (a true)
        foreach (\array_chunk($listing ?? [], length: 3) as $entry) {
            if (3 !== \count($entry)) {
                continue;
            }
            [$key, $value] = self::pair($entry[2]);
            $theirs = \in_array($entry[0], self::REPOSITORY, strict: true);
            // every scope's: the user's own values are carried too
            if (\str_starts_with($entry[1], 'file:')) {
                $origin = self::path($rootDir, \substr($entry[1], offset: 5));
                $files[] = $origin;
                if (1 === \preg_match('/^include(if\..+)?\.path$/', $key)) {
                    $files[] = self::path(\dirname($origin), (string) $value);
                }
            }
            if ($theirs) {
                $submodules[] = self::submodule($key);
            }
            if (null === self::neutral($key, $git)) {
                continue;
            }
            if (!$theirs) {
                $own[$key][] = $value;

                continue;
            }
            $repository[$key] = true;
        }
        $gitmodules = null !== $listing && \is_file($rootDir.'/.gitmodules') ? self::listing($git, $rootDir, ['--file', '.gitmodules']) : [];
        foreach ($gitmodules ?? [] as $item) {
            $submodules[] = self::submodule(self::pair($item)[0]);
        }
        $arguments = [];
        foreach (\array_keys($repository) as $key) {
            foreach ([self::neutral($key, $git), ...($own[$key] ?? [])] as $value) {
                $arguments[] = '-c';
                $arguments[] = null === $value ? $key : $key.'='.$value;
            }
        }
        $ignored = [];
        foreach (\array_filter($submodules, static fn (?string $name): bool => null !== $name) as $name) {
            $ignored['submodule.'.$name.'.ignore'] = 'all';
        }
        foreach ([...self::FIXED, ...$ignored] as $key => $value) {
            $arguments[] = '-c';
            $arguments[] = $key.'='.$value;
        }
        $sayable = [] === \array_filter([...\array_keys($repository), ...\array_keys($ignored)], static fn (string $key): bool => \str_contains($key, '='));

        return new self(null !== $listing && null !== $gitmodules && $sayable ? $arguments : ['-c', self::REFUSED], $git, self::digests($files), null !== $listing);
    }

    /**
     * Whether what these overrides were read from still says the same. A
     * repository's config can include a file its own tree carries, and an
     * `includeIf "onbranch:…"` one its branch picks: a pull or a checkout
     * changes what git obeys without touching .git/config. Nor are they
     * for another git than the one they were read through.
     */
    public function isCurrent(string $git): bool
    {
        return $this->complete && $this->git === $git && $this->read === self::digests(\array_keys($this->read));
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>|null its NUL-separated fields, a trailing empty one included; null when git could not list
     */
    private static function listing(string $git, string $rootDir, array $options): ?array
    {
        // getenv(), whole: Process alone passes on only what $_SERVER also has,
        // and the git this reads for must be the one the runner spawns
        $listing = new Process([$git, '-C', $rootDir, 'config', '--null', ...$options, '--list'], env: \getenv(), timeout: 10);
        try {
            $listing->run();
        } catch (RuntimeException) { // timed out, or killed by a signal: run() throws for both
            return null;
        }

        return $listing->isSuccessful() ? \explode("\0", $listing->getOutput()) : null;
    }

    /** The submodule a `submodule.<name>.<key>` names, or null for any other key. */
    private static function submodule(string $key): ?string
    {
        $name = [];

        return 1 === \preg_match('/^submodule\.(.+)\.[^.]+$/', $key, $name) ? $name[1] : null;
    }

    /** @return array{string, string|null} a `key LF value` item's key and value — null for a bare key */
    private static function pair(string $item): array
    {
        $pair = \explode("\n", $item, limit: 2);

        return [$pair[0], $pair[1] ?? null];
    }

    /** Where `HEAD` is: an `includeIf "onbranch:…"` follows it. A linked worktree's `.git` is a file naming its own. */
    private static function head(string $rootDir): string
    {
        $link = \is_file($rootDir.'/.git') ? (string) \file_get_contents($rootDir.'/.git') : '';

        return self::path($rootDir, \str_starts_with($link, 'gitdir: ') ? \trim(\substr($link, offset: 8)) : '.git').'/HEAD';
    }

    /**
     * The user's own config files, there or not: a listing names only those
     * that hold something, and one written later changes what is carried.
     *
     * @return list<string>
     */
    private static function usersOwn(): array
    {
        $global = (string) \getenv('GIT_CONFIG_GLOBAL');
        if ('' !== $global) {
            return [$global];
        }
        $home = (string) \getenv('HOME');
        $xdg = (string) \getenv('XDG_CONFIG_HOME');

        return [$home.'/.gitconfig', ('' !== $xdg ? $xdg : $home.'/.config').'/git/config'];
    }

    /** $path as git reads it from a file in $directory: `~/` is home, and a relative one is $directory's. */
    private static function path(string $directory, string $path): string
    {
        return match (true) {
            \str_starts_with($path, '~/') => (string) \getenv('HOME').\substr($path, offset: 1),
            \str_starts_with($path, '/') => $path,
            default => $directory.'/'.$path,
        };
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, string> each by its contents' digest — a missing one by none
     */
    private static function digests(array $files): array
    {
        $digests = [];
        foreach ($files as $file) {
            $digests[$file] = \is_file($file) ? (string) \hash_file('xxh128', $file) : '';
        }

        return $digests;
    }

    /** What $key gets in place of the repository's value, or null when it names no program. */
    private static function neutral(string $key, string $git): ?string
    {
        foreach (self::NEUTRAL as $pattern => $value) {
            if (1 === \preg_match($pattern, $key)) {
                return $value ?? \escapeshellarg($git).' merge-file --marker-size=%L -L %X -L %S -L %Y %A %O %B';
            }
        }

        return null;
    }
}
