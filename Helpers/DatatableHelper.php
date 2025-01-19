<?php

namespace Ibinet\Helpers;

use Ibinet\Models\RemoteHelpdesk;
use Ibinet\Models\ExpenseReportActivity;
use Ibinet\Models\Ticket;
use Ibinet\Models\TicketTimer;
use Ibinet\Helpers\CustomHelper;
use Ibinet\Helpers\TimeHelper;

class DatatableHelper{

    // /**
    //  * Return datatable html
    //  * 
    //  * @param array $data
    //  * @return string
    //  */
    public static function expenseReportRemoteInfo($data, $isTicket = false)
    {
        $type = "-";
        if($isTicket == false){
            $remoteHelpdesk = RemoteHelpdesk::where('expense_report_id', $data->id)->first();
            if($remoteHelpdesk){
                $remote = $remoteHelpdesk;
            } else{
                $remote = $data->remote;
            }
        } else {
            $remote = $data->remote;
        }

        if($remote){
            if ($remote->remoteType != null){
                $type = $remote->remoteType->name;
            }

            $text = "<b class='text-primary'>{$remote->name}</b><br/>";
            $text .= "<b>IP LAN : </b>" . $remote->ip_lan . "<br/>";
            $text .= "<b>IP P2P Modem : </b>" . $remote->ip_p2p_modem . "<br/>";
            $text .= "<b>Site ID : </b>" . ($remote->site_id ? $remote->site_id : "-"). "<br/>";
            $text .= "<b>BC/TID : </b>" . ($remote->bc_tid ? $remote->bc_tid : "-"). "<br/>";
            $text .= "<b>Tipe Remote : </b>{$type}<br/>";
            $text .= "<b>Supervisi : </b>" . ($remote->supervision->name ?? "-"). "<br/>";
            $text .= "<b>Wilayah : </b>" . ($remote->territory->name ?? "-"). "<br/>";
            $text .= "<b>Unit Kerja : </b>" . ($remote->workUnit->name ?? "-"). "<br/>";
            $text .= "<b>Link : </b>" . ($remote->link->name ?? "-"). "<br/>";
            $text .= "<b>Zona : </b>" . ($remote->zone->name ?? "-"). "<br/>";

            return $text;
        } else{
            return '-';
        }
    }

    /**
     * Ticket status html
     * 
     * @param $data
     * @return string
     */
    public static function ticketStatus($data)
    {
        $statusBadge = '';
        $statusBadgeClass = CustomHelper::setBadgeStatusExpenseReport($data->status);

        $statusText = $data->status;

        if ($statusText == 'PENDING' && $data->user_id == null) {
            $statusBadgeClass = "bg-primary";
            $statusText = "ON SCHEDULE";
        }

        if ($statusText == 'CANCELED') {
            $statusBadgeClass = "bg-danger";
            $statusText = "CANCELED";
        } else{
            if (TimeHelper::checkIfStopClock($data->id)) {
                $statusBadgeClass = "bg-warning";
                $statusText = "ON STOP CLOCK";
            }
        }

        $ticketStatus = "<span class='badge {$statusBadgeClass}'>{$statusText}</span>";

        $workTime = TimeHelper::getWorkTimeTicket($data->id);

        $workBadge = "<span class='badge bg-info'>{$workTime}</span>";

        if($data->status != 'CANCELED'){
            if($data->user_id != null){
                $statusBadge = '<span class="badge bg-success">Sudah ditugaskan</span>';
            } else{
                $statusBadge = '<span class="badge bg-danger">Belum ditugaskan</span>';
            }
        }

        return $ticketStatus.'<br><br>'.$statusBadge.'<br><br>'.$workBadge;
    }
}