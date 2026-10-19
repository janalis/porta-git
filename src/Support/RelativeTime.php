<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Support;

use Porta\I18n\Translator;

/**
 * "5 hours ago", the way GitHub Desktop dates a commit and a fetch: minutes,
 * hours and days while they are recent, then the date itself. The short form
 * ("5h") is for a compact screen, where the long one would take the subject's
 * room.
 */
final readonly class RelativeTime
{
    private const int MINUTE = 60;
    private const int HOUR = 3_600;
    private const int DAY = 86_400;

    /** Past this, a count of days says less than the date does. */
    private const int DAYS_SHOWN = 30;

    public function __construct(
        private Translator $translator,
    ) {}

    public function long(int $timestamp, int $now): string
    {
        $ago = \max(0, $now - $timestamp);

        return match (true) {
            $ago < self::MINUTE => $this->translator->trans('just now'),
            $ago < self::HOUR => $this->translator->trans('%count% minute ago|%count% minutes ago', ['%count%' => \intdiv($ago, self::MINUTE)]),
            $ago < self::DAY => $this->translator->trans('%count% hour ago|%count% hours ago', ['%count%' => \intdiv($ago, self::HOUR)]),
            $ago < (self::DAYS_SHOWN * self::DAY) => $this->translator->trans('%count% day ago|%count% days ago', ['%count%' => \intdiv($ago, self::DAY)]),
            default => \date('Y-m-d', $timestamp),
        };
    }

    public function short(int $timestamp, int $now): string
    {
        $ago = \max(0, $now - $timestamp);

        return match (true) {
            $ago < self::MINUTE => $this->translator->trans('now'),
            $ago < self::HOUR => $this->translator->trans('%count%m', ['%count%' => \intdiv($ago, self::MINUTE)]),
            $ago < self::DAY => $this->translator->trans('%count%h', ['%count%' => \intdiv($ago, self::HOUR)]),
            $ago < (self::DAYS_SHOWN * self::DAY) => $this->translator->trans('%count%d', ['%count%' => \intdiv($ago, self::DAY)]),
            default => \date('Y-m-d', $timestamp),
        };
    }
}
