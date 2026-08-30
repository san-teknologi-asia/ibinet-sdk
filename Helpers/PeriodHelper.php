<?php

namespace Ibinet\Helpers;

use Carbon\Carbon;
use DateTimeInterface;

class PeriodHelper
{
    /**
     * Day of month after which a date belongs to the next period.
     *
     * @return int
     */
    public static function cutoffDay(): int
    {
        return (int) env('ER_PERIOD_CUTOFF_DAY', 25);
    }

    /**
     * Resolve the period code (YYYYMM) a date falls into.
     *
     * Anything dated after the cutoff day rolls into the next month.
     *
     * @param null|string|\DateTimeInterface|\Carbon\Carbon $date
     * @return string
     */
    public static function periodFor($date = null): string
    {
        if ($date instanceof Carbon) {
            $dateObj = $date->copy();
        } elseif ($date instanceof DateTimeInterface) {
            $dateObj = Carbon::instance($date);
        } elseif ($date === null || $date === '') {
            $dateObj = Carbon::now();
        } else {
            $dateObj = Carbon::parse($date);
        }

        if ($dateObj->day > self::cutoffDay()) {
            // startOfMonth() first, then addMonthNoOverflow(), so 31 January
            // rolls to February instead of skipping to March.
            $dateObj = $dateObj->startOfMonth()->addMonthNoOverflow();
        }

        return $dateObj->format('Ym');
    }

    /**
     * The period code (YYYYMM) that today falls into.
     *
     * @return string
     */
    public static function current(): string
    {
        return self::periodFor(Carbon::now());
    }

    /**
     * Human readable period label, e.g. '202608' becomes 'August 2026'.
     *
     * @param string $period
     * @return string
     */
    public static function label(string $period): string
    {
        if (!preg_match('/^\d{6}$/', $period)) {
            return $period;
        }

        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 4, 2);

        if ($month < 1 || $month > 12) {
            return $period;
        }

        setlocale(LC_TIME, 'en_US');
        Carbon::setLocale('en');

        return Carbon::create($year, $month, 1, 0, 0, 0)->translatedFormat('F Y');
    }
}
