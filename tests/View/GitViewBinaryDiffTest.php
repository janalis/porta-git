<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Tests\Support\GitViewRig;

/**
 * A change to a binary file has no hunk to show: the view says the files
 * differ, where an empty diff read as "No changes", "identical" or "No patch".
 * Its own class because the controller's test is at the 60-method cap.
 */
final class GitViewBinaryDiffTest extends TestCase
{
    use GitViewRig;

    /** A changed binary file has no hunk to show — and is not "No changes". */
    public function testAChangedBinaryFileSaysSo(): void
    {
        $controller = $this->controller();
        $this->project->write('logo.png', "\x89PNG\x00\x01");
        $this->project->git('add', 'logo.png');
        $this->project->git('commit', '-q', '-m', 'logo');
        $this->project->write('logo.png', "\x89PNG\x00\x02");
        $controller->open();

        $this->await(fn (): bool => \str_contains($this->diffText(), 'Binary files differ'));
        $this->addToAssertionCount(1); // the throwing await is the assertion
    }

    /** Two binary files compared: they differ, they are not "identical". */
    public function testComparingTwoDifferentBinaryFilesSaysTheyDiffer(): void
    {
        $controller = $this->controller();
        $left = $this->project->write('.porta/left.png', "\x89PNG\x00\x01");
        $right = $this->project->write('.porta/right.png', "\x89PNG\x00\x02");

        $controller->openComparison($left, $right, static function (): void {});

        $this->await(fn (): bool => \str_contains($this->diffText(), 'Binary files differ'));
        $this->addToAssertionCount(1); // the throwing await is the assertion
    }

    /** A commit that changed a binary file shows that it did, in History too. */
    public function testAHistoryCommitThatChangedABinaryFileSaysSo(): void
    {
        $controller = $this->controller();
        $this->project->write('logo.png', "\x89PNG\x00\x01");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'logo');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History: the logo commit auto-loads logo.png

        $this->await(fn (): bool => \str_contains($this->diffText(), 'Binary files differ'));
        $this->addToAssertionCount(1); // the throwing await is the assertion
    }
}
