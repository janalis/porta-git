<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

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
     */
    private const array FIXED = ['protocol.ext.allow' => 'never', 'protocol.git.allow' => 'never', 'gc.auto' => '0', 'maintenance.auto' => 'false'];

    /** The scopes that are the repository's own: everything else is the user's. */
    private const array REPOSITORY = ['local', 'worktree'];

    /**
     * The overrides for the repository at $rootDir, read through $git. A
     * config git cannot list (a malformed one, a git older than 2.26 without
     * `--show-scope`) gets the unconditional half: a git that cannot read
     * its config runs nothing on its behalf either.
     *
     * @return list<string> `-c` pairs, to go between `git` and its verb
     */
    public static function arguments(string $git, string $rootDir): array
    {
        // getenv(), whole: Process alone passes on only what $_SERVER also has,
        // and the git this reads for must be the one the runner spawns
        $listing = new Process([$git, '-C', $rootDir, 'config', '--null', '--show-scope', '--list'], env: \getenv(), timeout: 10);
        $listing->run();
        $repository = \array_fill_keys(self::ALWAYS, value: true);
        $own = [];
        // `scope NUL key LF value NUL`, the LF and value missing on a bare `key` (a true)
        foreach (\array_chunk(\explode("\0", $listing->isSuccessful() ? $listing->getOutput() : ''), length: 2) as $entry) {
            if (2 !== \count($entry)) {
                continue;
            }
            $pair = \explode("\n", $entry[1], limit: 2);
            [$key, $value] = [$pair[0], $pair[1] ?? null];
            if (null === self::neutral($key, $git)) {
                continue;
            }
            if (!\in_array($entry[0], self::REPOSITORY, strict: true)) {
                $own[$key][] = $value;

                continue;
            }
            $repository[$key] = true;
        }
        $arguments = [];
        foreach (\array_keys($repository) as $key) {
            foreach ([self::neutral($key, $git), ...($own[$key] ?? [])] as $value) {
                $arguments[] = '-c';
                $arguments[] = null === $value ? $key : $key.'='.$value;
            }
        }
        foreach (self::FIXED as $key => $value) {
            $arguments[] = '-c';
            $arguments[] = $key.'='.$value;
        }

        return $arguments;
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
