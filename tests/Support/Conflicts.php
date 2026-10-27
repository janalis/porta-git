<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

/** Real conflicts in a real repository, made with git itself. */
final class Conflicts
{
    /**
     * $project, holding `a.txt` as "base\n", made a repository on `main` and
     * left mid-merge: `topic` wrote "theirs\n" where `main` wrote "ours\n".
     */
    public static function inA(TempProject $project): void
    {
        $project->initGit(branch: 'main');
        $project->commitAll('base');
        $project->git('checkout', '-q', '-b', 'topic');
        $project->write('a.txt', "theirs\n");
        $project->commitAll('theirs');
        $project->git('checkout', '-q', 'main');
        $project->write('a.txt', "ours\n");
        $project->commitAll('ours');
        new Process(['git', 'merge', '-q', 'topic'], $project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH])->run();
    }
}
