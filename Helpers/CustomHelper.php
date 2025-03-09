<?php

namespace Ibinet\Helpers;

class CustomHelper{
    /**
     * Get Array Of Helpdesk Status
     *
     * @return array
     */
    public static function getHelpdeskStatus($isScheduleInclude = false)
    {   
        $status = [
            [
                'value' => 'PENDING',
                'text' => 'Pending'
            ],
            [
                'value' => 'PENDING WITH PROBLEM',
                'text' => 'Done (Note)'
            ],
            [
                'value' => 'DISMANTLE',
                'text' => 'PM-Dismantle'
            ],
            [
                'value' => 'DONE',
                'text' => 'Done'
            ],
        ];

        if($isScheduleInclude){
            array_push($status, [
                'value' => 'ON SCHEDULE',
                'text' => 'On Schedule'
            ]);
        }

        return $status;
    }

    /**
     * Set color by expense report status
     * 
     * @param String $value
     * @return String
     */
    public static function setColorStatusHelpdesk($value)
    {
        if ($value == 'PENDING' || $value == null) {
            return 'bg-warning';
        } else if($value == 'PENDING WITH PROBLEM'){
            return 'bg-success';
        } else if($value == 'DISMANTLE'){
            return 'bg-orange';
        } else if($value == 'DONE'){
            return 'bg-success';
        } else if($value == 'CLOSED'){
            return 'bg-success';
        } else{
            return 'bg-primary';
        }
    }

    /**
     * Set badge color by expense report status
     * 
     * @param String $value
     * @return String
     */
    public static function setBadgeStatusExpenseReport($value)
    {
        if ($value == 'PENDING' || $value == null) {
            return 'bg-warning';
        } else if($value == 'PENDING WITH PROBLEM'){
            return 'bg-success';
        } else if($value == 'DISMANTLE'){
            return 'bg-orange';
        } else if($value == 'DONE'){
            return 'bg-success';
        } else if($value == 'CLOSED'){
            return 'bg-success';
        } else{
            return 'bg-primary';
        }
    }

    /**
     * Mapping status
     * 
     * @param String $status
     * @return String
     */
    public static function mappingHelpdeskStatus($status)
    {
        if ($status == null) {
            $status = 'ON SCHEDULE';
        }
        
        if ($status == 'PENDING WITH PROBLEM') {
            $status = 'DONE (NOTE)';
        }

        if ($status == 'DISMANTLE') {
            $status = 'PM-DISMANTLE';
        }

        return $status;
    }

    /**
     * Get all ticket status
     * 
     * @return array
     */
    public static function getTicketStatus()
    {
        $status = [
            (object)[
                'value' => 'PENDING',
                'text' => 'Pending'
            ],
            (object)[
                'value' => 'ON PROGRESS',
                'text' => 'On Progress'
            ],
            (object)[
                'value' => 'DONE',
                'text' => 'Selesai'
            ],
            (object)[
                'value' => 'CANCELED',
                'text' => 'Dibatalkan'
            ],
            (object)[
                'value' => 'ON STOP CLOCK',
                'text' => 'On Stop Clock'
            ],
        ];

        return $status;
    }
}
