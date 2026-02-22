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
        $text .= "<b>Technician : </b>" . ($data->expenseReport->assignmentTo->name ?? '-') . "<br/>";
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
            $text .= "<b>Work Unit : </b>" . ($remote->workUnit->name ?? "-") . "<br/>";

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
            if (($data->remoteHelpdesk->process_date ?? null) != null) {
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
     * Return datatable html
     *
     * @param $data
     * @return string
     */
    public static function expenseReportLocationOMCAction($data)
    {
        $action = "";
        $doneStatusConditional = ConditionalHelper::checkHelpdeskDoneStatus($data->helpdesk_status);
        $isHelpdesk = has('omc.dashboard.process_helpdesk') && $data->helpdesk_status != 'DONE' && $data->helpdesk_status != 'CLOSED';
        $isAdmin = has('omc.dashboard.process_admin') && $doneStatusConditional && $data->admin_status != 'DONE' && $data->admin_status != 'CLOSED';
        // $isHelpdeskSupervisor = ConditionalHelper::checkHelpdeskSupervisorRole(auth()->user()->role_id);
        // $isAdminSupervisor = ConditionalHelper::checkAdminSupervisorRole(auth()->user()->role_id) && $doneStatusConditional;
        // $isSuperAdmin = ConditionalHelper::checkSuperAdminRole(auth()->user()->role_id);

        if($isHelpdesk){
            $action .= '<a href="'.route('secure.helpdesk.project.remote.form', ['expense_report_remote_id' => $data->id]).'" class="btn btn-primary">
                <i class="dripicons-enter"></i> Proses Helpdesk
            </a>';
        }else if($isAdmin) {
            $action .= '<a href="'.route('secure.admin.project.remote.form', ['expense_report_remote_id' => $data->id]).'" class="btn btn-primary">
                <i class="dripicons-enter"></i> Proses Admin
            </a>';
        } else {
            $action .= '-';
        }

        // TODO: PREVIOUS CODE, NEED TO CHECK MORE CONDITION
        // if($isHelpdesk || $isHelpdeskSupervisor || $isSuperAdmin){
        //     if (
        //         ($isHelpdesk && !ConditionalHelper::checkHelpdeskDoneStatus($data->helpdesk_status)) ||
        //         $isHelpdeskSupervisor ||
        //         $isSuperAdmin
        //         ) {
        //             if (!TimeHelper::checkIfStopClock($data->ticket_id)) {
        //                 $action .= '
        //                 <a href="'.route('helpdesk.project.remote.form', ['id' => $data->project_id, 'expense_report_location_id' => $data->id]).'" class="btn btn-primary">
        //                     <i class="dripicons-enter"></i> Proses Helpdesk
        //                 </a>';
        //             }
        //     }

        //     if(TimeHelper::checkIfStopClock($data->ticket_id)){
        //         $latestTimer = TicketTimer::where('ticket_id', $data->ticket_id)
        //             ->orderBy('created_at', 'desc')
        //             ->first();
        //         $action .= '<a href="'.route('helpdesk.ticket.start', $data->ticket_id).'" class="btn btn-success">
        //             <i class="mdi mdi-timer-play"></i>
        //             Mulai Waktu
        //         </a><br>'.date('d F Y H:i', strtotime($latestTimer->created_at));
        //     }
        // }

        // if($isAdmin || $isAdminSupervisor || ($isSuperAdmin && $doneStatusConditional)){
        //     $action .= '<br><br>';

        //     if(($data->phase != null || $data->phase != '') && $data->admin_status != 'DONE'){
        //         $action .= '
        //         <a href="'.route('admin.project.remote.form', ['expense_location_id' => $data->id]).'" class="btn btn-success">
        //             <i class="dripicons-enter"></i> Proses Admin
        //         </a>' ;
        //     }

        //     if (($data->phase != null || $data->phase != '') && $data->admin_status == 'DONE' && $isAdminSupervisor) {
        //         $action .= '
        //         <a href="'.route('admin.project.remote.form', ['expense_location_id' => $data->id]).'" class="btn btn-success">
        //             <i class="dripicons-enter"></i> Proses Admin
        //         </a>' ;
        //     }
        // }

        // if ($isSuperAdmin) {
        //     $action .= '<br><br>';

        //     $action .= '<a class="btn btn-danger delete-item" href="#" data-label="pengguna" data-url="'.route('helpdesk.dashboard.destroy', $data->id).'">
        //         <i class="dripicons-trash"></i> Hapus
        //     </a> ';
        // }

        // $action .= '<a class="btn btn-danger delete-item" href="#" data-label="pengguna" data-url="'.route('helpdesk.dashboard.destroy', $data->id).'">
        //     <i class="dripicons-trash"></i> Hapus
        // </a> ';
        // $action .= '<a href="'.route('admin.project.remote.form', ['expense_location_id' => $data->id]).'" class="btn btn-success">
        //     <i class="dripicons-enter"></i> Proses Admin
        // </a>' ;
        // $action .= '<a href="'.route('helpdesk.ticket.start', $data->ticket_id).'" class="btn btn-success">
        //     <i class="mdi mdi-timer-play"></i>
        //     Mulai Waktu
        // </a>';

        return $action;
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

        return $ticketStatus . '<br><br>' . $statusBadge . $workBadge;
    }
}
