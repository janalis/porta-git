<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitTools;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\CallsTools;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** `git_status` and `git_diff`, against a real repository and a real git. */
final class GitToolsTest extends TestCase
{
    use CallsTools;
    use GitBinary;
    use PreservesPath;
    use PumpsLoop;

    private static function api(TempProject $project, ?string $gitPath = null): PluginApi
    {
        $settings = new PluginSettings($project->dir, []);
        $api = new PluginApi('git', new PluginManager(new EventDispatcher(), $settings), $project->dir, $settings);
        new GitTools($api, new GitProcessRunner($project->dir, $gitPath))->register();

        return $api;
    }

    private static function repository(): TempProject
    {
        $project = new TempProject(['a.txt' => "one\n", 'b.txt' => "two\n"]);
        $project->initGit('main');
        $project->commitAll('first');

        return $project;
    }

    public function testStatusNamesTheBranchAndEveryChange(): void
    {
        $this->skipWithoutGit();
        $project = self::repository();
        $project->write('a.txt', "one!\n");
        $project->write('new.txt', "fresh\n");

        $status = $this->callTool(self::api($project), 'status');

        $this->assertSame("## main\n M a.txt\n?? new.txt\n", $status);
    }

    public function testDiffIsUnstagedByDefaultStagedOnRequestAndNarrowsToAPath(): void
    {
        $this->skipWithoutGit();
        $project = self::repository();
        $api = self::api($project);
        $this->assertSame('No changes', $this->callTool($api, 'diff'));

        $project->write('a.txt', "one!\n");
        $project->write('b.txt', "two!\n");
        $project->git('add', 'b.txt');

        $unstaged = $this->callTool($api, 'diff');
        $this->assertIsString($unstaged);
        $this->assertStringContainsString('+one!', $unstaged);
        $this->assertStringNotContainsString('two!', $unstaged);

        $staged = $this->callTool($api, 'diff', ['staged' => true]);
        $this->assertIsString($staged);
        $this->assertStringContainsString('+two!', $staged);
        $this->assertStringNotContainsString('one!', $staged);

        $this->assertSame('No changes', $this->callTool($api, 'diff', ['staged' => true, 'path' => 'a.txt']));
        $this->assertSame($unstaged, $this->callTool($api, 'diff', ['path' => '']), 'an empty path is no path');
    }

    public function testAFailingGitIsTheCallersError(): void
    {
        $this->skipWithoutGit();
        $project = self::repository();

        $this->expectExceptionObject(new \RuntimeException('git diff failed (exit 128)'));

        $this->callTool(self::api($project), 'diff', ['path' => '../outside.txt']);
    }

    public function testWithNoGitItFailsAtOnceWithoutWaiting(): void
    {
        $project = self::repository();

        $this->expectExceptionObject(new \RuntimeException('git status failed (exit 1)'));

        $this->callTool(self::api($project, '/porta-no-such-git'), 'status');
    }
}
