<?php

namespace Ibinet\Helpers;

use Ibinet\Models\RemoteHelpdesk;
use Ibinet\Models\ExpenseReportActivity;
use Ibinet\Models\Ticket;
use Ibinet\Models\TicketTimer;
use Ibinet\Helpers\CustomHelper;
use Ibinet\Helpers\TimeHelper;

class DatatableHelper
{

    /**
     * Return datatable html
     *
     * @param array $data
     * @return string
     */
    public static function expenseReportLocationInfo($data)
    {
        $text = "";
        $text .= "<b>Project Name : </b>" . ($data->project->name ?? '-') . "<br/>";
        $text .= "<b>ER Number : </b>" . ($data->expenseReport->code ?? '-') . "<br/>";
        $text .= "<b>Technician : </b>" . ($data->expenseReport?->assignmentTo?->name ?? '-') . "<br/>";
        $text .= "<b>Work Type : </b>" . ($data->workType->name ?? '-') . "<br/>";
        $text .= "<b>Home Base : </b>" . ($data->home_base ?? '-') . "<br/>";
        $text .= "<b>Ticket Ref : </b>" . ($data->ticket->code ?? '-') . "(" . ($data->ticket->client_code ?? '-') . ")<br/>";
        $text .= "<b>Visit : </b>" . ($data->phase ?? '-') . "<br/>";
        return $text;
    }

    /**
     * Return datatable html
     *
     * @param object $data
     * @param bool $isTicket
     * @return string
     */
    public static function expenseReportRemoteInfo($data, $isTicket = false)
    {
        $type = "-";
        if ($isTicket == false) {
            $remoteHelpdesk = $data->remoteHelpdesk;
            if (!$remoteHelpdesk) {
                $remoteHelpdesk = RemoteHelpdesk::where('expense_report_id', $data->id)->first();
            }

            if ($remoteHelpdesk) {
                $remote = $remoteHelpdesk;
            } else {
                $remote = $data->remote;
            }
        } else {
            $remote = $data->remote;
        }

        if ($remote) {
            $text = "<b class='text-primary'>{$remote->name}</b><br/>";
            $text .= "<b>IP LAN : </b>" . $remote->ip_lan . "<br/>";
            $text .= "<b>Site ID : </b>" . ($remote->site_id ? $remote->site_id : "-") . "<br/>";
            $text .= "<b>Work Unit : </b>" . ($remote->workUnit?->name ?? "-") . "<br/>";

            return $text;
        } else {
            return '-';
        }
    }

    /**
     * Return datatable html
     *
     * @param $data
     * @return string
     */
    public static function expenseReportLocationDate($data)
    {
        $text = "";
        $scheduleDate = date('d F Y H:i', strtotime($data->created_at));
        $progressDate = "";
        $taskDate = "-";
        $visitDate = "-";

        if ($data->helpdesk_process_date != null && $data->helpdesk_process_date != '') {
            $progressDate = date('d F Y H:i', strtotime($data->helpdesk_process_date));
        } else {
            $progressDate = '-';
        }

        // TODO: Not understand where the relation should be
        if ($data->phase != null) {
            if (filled($data->remoteHelpdesk?->process_date)) {
                $visitDate = date('d F Y', strtotime($data->remoteHelpdesk->process_date));
            }
        }

        if ($data->admin_process_date != null) {
            $taskDate = date('d F Y H:i', strtotime($data->admin_process_date));
        }

        $text .= "<b>Schedule Date</b> : {$scheduleDate}<br/>";
        $text .= "<b>Visit Date</b> : {$visitDate}<br/>";
        $text .= "<b>Progress Helpdesk Date</b> : {$progressDate}<br/>";
        $text .= "<b>Task Date</b> : {$taskDate}<br/>";

        if ($data->ticket_id != null) {
            $ticket = Ticket::where('id', $data->ticket_id)->first();
            $text .= "<b>Tanggal Tiket</b> : " . ($ticket != null ? date('d F Y H:i', strtotime($ticket->created_at)) : '-') . "<br/>";
        }

        return $text;
    }

    /**
     * Return datatable html
     *
     * @param array $data
     * @return string
     */
    public static function expenseReportLocationStatus($data)
    {
        $text = "";
        $helpdeskStatus = $data->helpdesk_status ?? 'ON SCHEDULE';
        $adminStatus = $data->admin_status ?? 'PENDING';
        $financeStatus = $data->finance_status ?? 'PENDING';

        $helpdeskBadge = CustomHelper::setBadgeStatusExpenseReport($helpdeskStatus);
        $adminBadge = CustomHelper::setBadgeStatusExpenseReport($adminStatus);
        $financeBadge = CustomHelper::setBadgeStatusExpenseReport($financeStatus);
        $helpdeskStatus = CustomHelper::mappingHelpdeskStatus($helpdeskStatus);

        $text .= "<b>HD</b> : <span class='badge {$helpdeskBadge}'>{$helpdeskStatus}</span><br/>";
        $text .= "<b>ADMIN</b> : <span class='badge {$adminBadge}'>{$adminStatus}</span><br/>";
        $text .= "<b>FINANCE</b> : <span class='badge {$financeBadge}'>{$financeStatus}</span>";

        return $text;
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
        } else {
            if (TimeHelper::checkIfStopClock($data->id)) {
                $statusBadgeClass = "bg-warning";
                $statusText = "ON STOP CLOCK";
            }
        }

        $ticketStatus = "<span class='badge {$statusBadgeClass}'>{$statusText}</span>";

        $workTime = TimeHelper::getWorkTimeTicket($data->id);

        $workBadge = $statusText != 'CANCELED' ? "<br><br> <span class='badge bg-info'>{$workTime}</span>" : ' ';

        if ($data->status != 'CANCELED') {
            if ($data->user_id != null) {
                $statusBadge = '<span class="badge bg-success">Already assigned</span>';
            } else {
                $statusBadge = '<span class="badge bg-danger">Not yet assigned</span>';
            }
        }

        // Add first handling status badge
        $firstHandlingBadge = '';
        if (isset($data->first_handling_status) && $data->first_handling_status != null) {
            $fhBadgeClass = CustomHelper::getFirstHandlingBadge($data->first_handling_status);
            $fhText = CustomHelper::mappingFirstHandlingStatus($data->first_handling_status);
            $firstHandlingBadge = "<br><br><span class='badge {$fhBadgeClass}'>FH: {$fhText}</span>";
        }

        return $ticketStatus . '<br><br>' . $statusBadge . $workBadge . $firstHandlingBadge;
    }
}
