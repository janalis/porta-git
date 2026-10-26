<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Parse;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Parse\BranchParser;

final class BranchParserTest extends TestCase
{
    public function testParsesLocalsThenUnshadowedRemotes(): void
    {
        $out =
            \implode("\n", [
                "refs/heads/main\t*",
                "refs/heads/feature/login\t",
                "refs/tags/v1.0\t",
                "refs/remotes/origin/HEAD\t",
                "refs/remotes/origin/main\t",
                "refs/remotes/origin/release-1.0\t",
            ])."\n";

        $this->assertSame(
            [
                ['name' => 'main', 'current' => true, 'remote' => false],
                ['name' => 'feature/login', 'current' => false, 'remote' => false],
                ['name' => 'origin/release-1.0', 'current' => false, 'remote' => true],
            ],
            BranchParser::parse($out),
            'a tag skipped, and the remotes after it still read; origin/HEAD skipped; origin/main shadowed by the local main',
        );
    }

    public function testEmptyAndMalformedLinesAreSkipped(): void
    {
        $this->assertSame([], BranchParser::parse(''));
        $this->assertSame([], BranchParser::parse("\n \n\t\n"));
        $this->assertSame([], BranchParser::parse("not-a-refname\t*\n"), 'lines outside refs/heads|remotes are ignored');
        $this->assertSame(
            [['name' => 'solo', 'current' => false, 'remote' => false]],
            BranchParser::parse("refs/heads/solo\r\n"),
            'a line without the tab still parses',
        );
    }
}
