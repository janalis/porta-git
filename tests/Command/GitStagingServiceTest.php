<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\GitStagingService;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class GitStagingServiceTest extends TestCase
{
    use StatusReads;

    private function service(string $rootDir, StatusBarWidget $status): GitStagingService
    {
        $translator = new Translator('en');

        return new GitStagingService(
            new GitInfo($rootDir),
            new ProjectPaths($rootDir),
            new CommitBoxWidget($translator),
            GitDoubles::flash(new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)))),
            $translator,
        );
    }

    public function testDiscardingAnUnremovableUntrackedFileStillFlashes(): void
    {
        $project = new TempProject();
        // An untracked file inside a directory we make read-only, so the unlink
        // fails and Filesystem::remove throws — the delete is best-effort and the
        // "Deleted" flash still fires (the IOException is swallowed).
        $project->write('locked/x.txt', 'data');
        chmod($project->path('locked'), permissions: 0o500); // no write on the parent → unlink denied

        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);
        $entry = new ChangeEntry('locked/x.txt', '?', '?'); // untracked

        $refreshed = false;
        $service->discard($entry, static function () use (&$refreshed): void {
            $refreshed = true;
        });

        chmod($project->path('locked'), permissions: 0o755); // restore so cleanup can remove the tree

        $this->assertTrue($refreshed, 'the status refresh callback still ran');
        $this->assertStringContainsString('Deleted', $this->flashText($status));
    }
}
