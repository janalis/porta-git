<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Support\RelativeTime;
use Porta\I18n\Translator;

/**
 * Dates the way GitHub Desktop does: how long ago while it is recent, the
 * date once a count of days would say less.
 */
final class RelativeTimeTest extends TestCase
{
    private const int NOW = 1_760_000_000;

    /** @return iterable<string, array{int, string, string}> seconds ago, long form, short form */
    public static function ages(): iterable
    {
        yield 'this second' => [0, 'just now', 'now'];
        yield 'a minute short' => [59, 'just now', 'now'];
        yield 'one minute' => [60, '1 minute ago', '1m'];
        yield 'minutes' => [59 * 60, '59 minutes ago', '59m'];
        yield 'one hour' => [3_600, '1 hour ago', '1h'];
        yield 'hours' => [86_399, '23 hours ago', '23h'];
        yield 'one day' => [86_400, '1 day ago', '1d'];
        yield 'days' => [(30 * 86_400) - 1, '29 days ago', '29d'];
        yield 'the date' => [30 * 86_400, \date('Y-m-d', self::NOW - (30 * 86_400)), \date('Y-m-d', self::NOW - (30 * 86_400))];
        yield 'a clock ahead of the commit' => [-120, 'just now', 'now'];
    }

    #[DataProvider('ages')]
    public function testAnAgeReadsAsGithubDesktopDatesIt(int $ago, string $long, string $short): void
    {
        $time = new RelativeTime(new Translator('en'));

        $this->assertSame($long, $time->long(self::NOW - $ago, self::NOW));
        $this->assertSame($short, $time->short(self::NOW - $ago, self::NOW));
    }
}
