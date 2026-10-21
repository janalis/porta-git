<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\GitStagingService;
use Porta\GitPlugin\Diff\DiffSide;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class GitStagingServiceTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;
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
        \chmod($project->path('locked'), permissions: 0o500); // no write on the parent → unlink denied

        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);
        $entry = new ChangeEntry('locked/x.txt', '?', '?'); // untracked

        $refreshed = false;
        $service->discard($entry, static function () use (&$refreshed): void {
            $refreshed = true;
        });

        \chmod($project->path('locked'), permissions: 0o755); // restore so cleanup can remove the tree

        $this->assertTrue($refreshed, 'the status refresh callback still ran');
        $this->assertStringContainsString('Deleted', $this->flashText($status));
    }

    public function testStagingASelectionFromAnUntrackedDiffRefusesWithGuidance(): void
    {
        // Untracked files have no partial (index) form — this guard exists so
        // the diff pane's line-stage shortcut can't silently no-op instead of
        // telling the user to use the changes list's whole-file stage.
        $this->skipWithoutGit();
        $project = new TempProject();
        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);
        $entry = new ChangeEntry('new.txt', '?', '?');

        $called = false;
        $service->stageSelection($entry, DiffDocument::parse(''), DiffSide::Untracked, WhitespaceMode::Shown, [], static function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called, 'the guard returns before ever reaching git');
        $this->assertStringContainsString('Untracked: stage the whole file with Space in the changes list', $this->flashText($status));
    }

    public function testDiscardingATrackedPathGitDoesNotKnowFlashesFailure(): void
    {
        // discard() on a real (non-untracked) status letter still asks git to
        // restore the path — a path git itself doesn't recognize (mismatched
        // status, already gone from the index) is the failure branch, which
        // nothing else in this plugin's tests exercises.
        $this->skipWithoutGit();
        $project = new TempProject(['a.txt' => "one\n"]);
        $project->initGit();
        $project->commitAll('init');
        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);
        $entry = new ChangeEntry('does-not-exist.txt', ' ', 'M'); // not untracked, but git has never heard of it

        $refreshed = false;
        $service->discard($entry, static function () use (&$refreshed): void {
            $refreshed = true;
        });
        $this->await(static function () use (&$refreshed): bool {
            return $refreshed;
        }, what: 'the refresh callback to run after the failed restore');

        $this->assertStringContainsString('Discard failed', $this->flashText($status));
    }

    public function testAConflictedFileWithMarkersLeftIsNotMarkedResolved(): void
    {
        // Staging an unmerged file is what marks it resolved — git would take
        // the markers with it.
        $project = new TempProject(['m.php' => "<?php\n<<<<<<< HEAD\na\n=======\nb\n>>>>>>> topic\n"]);
        $status = new StatusBarWidget();
        $changed = false;

        $this->service($project->dir, $status)->toggleStage(new ChangeEntry('m.php', 'U', 'U'), static function () use (&$changed): void {
            $changed = true;
        });

        $this->assertStringContainsString('m.php still has conflict markers: resolve them first', $this->flashText($status));
        $this->assertFalse($changed, 'nothing asked of git');
    }

    public function testAConflictedFileResolvedOrGoneIsStagedAsAnyOther(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['m.php' => "<?php\nresolved\n"]);
        $project->initGit();
        $status = new StatusBarWidget();
        $changed = 0;
        $service = $this->service($project->dir, $status);

        // One at a time: two adds at once would race for the index lock.
        foreach ([new ChangeEntry('m.php', 'U', 'U'), new ChangeEntry('gone.php', 'D', 'D')] as $n => $entry) {
            $service->toggleStage($entry, static function () use (&$changed): void {
                ++$changed;
            });
            $this->await(static function () use (&$changed, $n): bool {
                return ($n + 1) === $changed;
            }, what: 'staged');
        }

        $this->assertSame('m.php', $project->git('diff', '--cached', '--name-only'));
    }

    public function testAMarkerInAFileNotInConflictIsNoneOfItsBusiness(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['doc.md' => "<<<<<<< an example in prose\n"]);
        $project->initGit();
        $changed = false;

        $this->service($project->dir, new StatusBarWidget())->toggleStage(new ChangeEntry('doc.md', '?', '?'), static function () use (&$changed): void {
            $changed = true;
        });
        $this->await(static function () use (&$changed): bool {
            return $changed;
        }, what: 'staged');

        $this->assertSame('doc.md', $project->git('diff', '--cached', '--name-only'));
    }

    public function testStagingEverythingWaitsForEveryMarkerToGo(): void
    {
        // The header box stages every file — and so marks every conflicted
        // one resolved, markers and all.
        $this->skipWithoutGit();
        $project = new TempProject(['a.txt' => "base\n", 'b.txt' => "b\n"]);
        $project->initGit(branch: 'main');
        $project->commitAll('base');
        $project->git('checkout', '-q', '-b', 'topic');
        $project->write('a.txt', "theirs\n");
        $project->commitAll('theirs');
        $project->git('checkout', '-q', 'main');
        $project->write('a.txt', "ours\n");
        $project->commitAll('ours');
        new Process(['git', 'merge', '-q', 'topic'], $project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH])->run();
        $project->write('b.txt', "b2\n");
        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);

        $service->stageAll(true, static function (): void {});
        $this->await(fn (): bool => \str_contains($this->flashText($status), 'conflict markers'), what: 'the refusal');
        $this->assertSame('a.txt', $project->git('diff', '--name-only', '--diff-filter=U'), 'still conflicted');
        $this->assertSame('', $project->git('diff', '--cached', '--name-only', '--', 'b.txt'), 'nothing staged');

        $project->write('a.txt', "both\n");
        $changed = false;
        $service->stageAll(true, static function () use (&$changed): void {
            $changed = true;
        });
        $this->await(static function () use (&$changed): bool {
            return $changed;
        }, what: 'the stage');
        $this->assertSame('', $project->git('diff', '--name-only', '--diff-filter=U'), 'resolved now');
    }
}
