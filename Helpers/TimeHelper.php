<?php

namespace Ibinet\Helpers;

use Ibinet\Models\TicketTimer;
use Carbon\Carbon;
use Ibinet\Models\Ticket;
use Ibinet\Helpers\RemoteHelper;
use DB;
use Illuminate\Support\Facades\Log;

class TimeHelper{

    /**
     * Get work time by ticket id
     *
     * @param String $ticket_id
     * @return String
     */
    public static function getWorkTimeTicket($ticket_id)
    {
        $timers = TicketTimer::where('ticket_id', $ticket_id)
            ->where('start_time', '<', Carbon::now())
            ->get();

        $totalDuration = 0;
        foreach ($timers as $timer) {
            $startTime = $timer->start_time;
            $endTime = now();

            if ($timer->end_time != null) {
                $endTime = $timer->end_time;
            }

            $start = new Carbon($startTime);
            $end = new Carbon($endTime);

            $totalDuration += $start->diffInSeconds($end);
        }

        // Konversi total durasi ke format jam:menit:detik
        $hours = floor($totalDuration / 3600);
        $minutes = floor(($totalDuration % 3600) / 60);
        $seconds = $totalDuration % 60;

        return sprintf("%02d Jam %02d Menit %02d Detik", $hours, $minutes, $seconds);
    }

    /**
     * Get average fix time
     *
     * @param String $start_date
     * @param String $end_date
     * @return String
     */
    public static function getAverageFixTime($start_date = null, $end_date = null)
    {
        $avgFixTime = Ticket::when($start_date && $end_date, function ($query) use ($start_date, $end_date) {
                return $query->whereBetween('tickets.created_at', [$start_date, $end_date]);
            })
            ->join('ticket_timers', 'ticket_timers.ticket_id', '=', 'tickets.id')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, ticket_timers.start_time, ticket_timers.end_time)'));

        // Convert seconds to hours
        $hours = floor($avgFixTime / 3600);

        return $hours;
    }

    /**
     * Get work time by ticket id
     *
     * @param String $ticket_id
     * @return String
     */
    public static function getAvailabilityPercentage($remote_id, $start_date, $end_date)
    {
        $project_id = session('project_id');

        $ticketQuery = Ticket::where('project_id', $project_id)
            ->whereHas('remote', function($query) use ($remote_id) {
                if ($remote_id == 'all') {
                    return $query->whereHas('project', function($query) {
                            return $query->where('projects.id', session('project_id'));
                        });
                }

                return $query->where('remote_id', $remote_id)
                    ->whereHas('project', function($query) {
                        return $query->where('projects.id', session('project_id'));
                    });
            })
            ->whereBetween('tickets.created_at', [$start_date, $end_date])
            ->join('ticket_timers', 'ticket_timers.ticket_id', '=', 'tickets.id');

        // Menghitung total waktu gangguan dalam detik
        $totalDowntime = $ticketQuery->sum(DB::raw('TIMESTAMPDIFF(SECOND, ticket_timers.start_time, ticket_timers.end_time)'));

        // Menghitung total ticket
        $ticketCount = $ticketQuery->count();

        //convert second into hours
        $hours = floor($totalDowntime / 3600);

        if ($totalDowntime > 0) {
            $percentageDowntime = 100 - (($hours / (720 * $ticketCount)) * 100);
            return round($percentageDowntime, 2);
        } else {
            return 100;
        }
    }

    /**
     * Validate stop clock
     *
     * @param string $id
     * @return boolean
     */
    public static function checkIfStopClock($ticket_id)
    {
        $timers = TicketTimer::where('ticket_id', $ticket_id)
            ->where('start_time', '>', Carbon::now())
            ->whereNull('end_time')
            ->first();

        if ($timers) {
            return true;
        } else {
            return false;
        }
    }

}
