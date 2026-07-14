<?php

namespace App\Services;

use Morilog\Jalali\Jalalian;
use Carbon\Carbon;

class JalaliService
{
    /**
     * Convert a Gregorian date to Jalali (Shamsi) format.
     *
     * @param string|null $date The Gregorian date in 'Y-m-d' format (optional).
     *                           If null, the current date will be used.
     * @return string The Jalali date in 'Y/m/d' format.
     */
    public function toJalali($date = null)
    {
        // If no date is provided, use the current date
        $carbonDate = $date ? Carbon::parse($date) : Carbon::now();

        // Convert the Gregorian date to Jalali
        $jalaliDate = Jalalian::fromCarbon($carbonDate)->format('Y/m/d');

        return $jalaliDate;
    }
}
