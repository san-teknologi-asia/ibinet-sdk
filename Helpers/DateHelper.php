<?php

namespace Ibinet\Helpers;

use Carbon\Carbon;

class DateHelper
{
    /**
     * Parse string to date
     * 
     * @param string $dateString
     * @return string
     */
    public static function parseStringToDateMySQL($dateString)
    {
        $formats = [
            'd F Y',   // 15 April 2025
            'd-M-Y',   // 15-Apr-2025
            'd/m/Y',   // 15/04/2025
            'd-m-Y',   // 15-04-2025
            'Y-m-d',   // 2025-04-15 (MySQL format)
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, trim($dateString));
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Format date
     * 
     * @param string $date
     * @param string $format
     * @return string
     */
    public static function formatDate($date, $format = 'd F Y')
    {
        if ($date) {
            setlocale(LC_TIME, 'en_US');
            Carbon::setLocale('en');

            $dateObj = Carbon::parse($date);
            return $dateObj->translatedFormat($format);
        }

        return "-";
    }
}
