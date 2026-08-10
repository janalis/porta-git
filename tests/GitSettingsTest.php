<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitSettings;

final class GitSettingsTest extends TestCase
{
    public function testDefaultsWhenSeededEmpty(): void
    {
        $git = new GitSettings([]);

        $this->assertTrue($git->showBranch());
        $this->assertTrue($git->gutterMarks());
        $this->assertFalse($git->blameDefault());
        $this->assertFalse($git->signCommits());
        $this->assertFalse($git->noVerify());
        $this->assertFalse($git->signoff());
        $this->assertSame(2, $git->refreshInterval());
    }

    public function testSeedsFromTheResolvedSettings(): void
    {
        $git = new GitSettings([
            'showBranch' => false,
            'gutterMarks' => false,
            'blameDefault' => true,
            'signCommits' => true,
            'noVerify' => true,
            'signoff' => true,
            'refreshInterval' => 10,
        ]);

        $this->assertFalse($git->showBranch());
        $this->assertFalse($git->gutterMarks());
        $this->assertTrue($git->blameDefault());
        $this->assertTrue($git->signCommits());
        $this->assertTrue($git->noVerify());
        $this->assertTrue($git->signoff());
        $this->assertSame(10, $git->refreshInterval());
    }

    public function testSettersRoundTripAndClampTheInterval(): void
    {
        $git = new GitSettings([]);

        $git->setShowBranch(false);
        $git->setGutterMarks(false);
        $git->setBlameDefault(true);
        $git->setSignCommits(true);
        $git->setNoVerify(true);
        $git->setSignoff(true);
        $git->setRefreshInterval(30);

        $this->assertFalse($git->showBranch());
        $this->assertFalse($git->gutterMarks());
        $this->assertTrue($git->blameDefault());
        $this->assertTrue($git->signCommits());
        $this->assertTrue($git->noVerify());
        $this->assertTrue($git->signoff());
        $this->assertSame(30, $git->refreshInterval());

        $git->setRefreshInterval(0); // never below 1
        $this->assertSame(1, $git->refreshInterval());
    }

    public function testSeededIntervalIsClampedToAtLeastOne(): void
    {
        $this->assertSame(1, new GitSettings(['refreshInterval' => 0])->refreshInterval());
    }
}
