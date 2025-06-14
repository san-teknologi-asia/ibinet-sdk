<?php

namespace Ibinet\Helpers;

use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\Remote;
use Ibinet\Models\Ticket;
use Ibinet\Models\TicketTimer;
use Ibinet\Models\User;
use Ibinet\Models\WorkType;

class CustomHelper
{
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

        if ($isScheduleInclude) {
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
        } else if ($value == 'PENDING WITH PROBLEM') {
            return 'bg-success';
        } else if ($value == 'DISMANTLE') {
            return 'bg-orange';
        } else if ($value == 'DONE') {
            return 'bg-success';
        } else if ($value == 'CLOSED') {
            return 'bg-success';
        } else {
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
        } else if ($value == 'PENDING WITH PROBLEM') {
            return 'bg-success';
        } else if ($value == 'DISMANTLE') {
            return 'bg-orange';
        } else if ($value == 'DONE') {
            return 'bg-success';
        } else if ($value == 'CLOSED') {
            return 'bg-success';
        } else {
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

    /**
     * Check and recreate ticket for teknisi
     *
     * @return boolean
     */
    public static function assignToExpenseReport($request, $ticket_id)
    {
        $activeExpenseReport = ExpenseReport::where([
            'assignment_to' => $request->user_id,
            'status'        => 'ONGOING'
        ])->first();

        $user = User::find($request->user_id);

        $workType = WorkType::where('code', 'CM')->first();
        $ticket = Ticket::find($ticket_id);
        $remote_id = $ticket->remote_id ?? $request->remote_id;

        $remote = Remote::with([
            'workUnit',
            // 'remoteType',
            // 'territory',
            // 'supervision',
            'homeBase',
            // 'link'
        ])->where('id', $remote_id)->first();

        $homeBase = $remote->homeBase->name ?? '';
        $technician = $user->name ?? null;

        if ($technician != null) {
            $expenseName = "Progress CM - {$technician}";

            if (!$activeExpenseReport) {
                $expenseReport = ExpenseReport::create([
                    'code'          => ExpenseReportHelper::generateERCode(),
                    'name'          => $expenseName,
                    'amount'        => $ticket->initial_amount ?? 100000,
                    'assignment_to' => $request->user_id,
                    'remark'        => $expenseName,
                    'created_by'    => auth()->user()->id ?? auth('api')->user()->id
                ]);

                ExpenseReportRequest::create([
                    'expense_report_id' => $expenseReport->id,
                    'amount'            => $ticket->initial_amount ?? 100000,
                    'code'              => $ticket->code . '-' . $expenseReport->code,
                    'remark'            => "Request Untuk Progress CM - {$technician} - {$ticket->code}",
                    'status'            => 'WAITING CONFIRMATION'
                ]);

                $activeExpenseReport = $expenseReport;
            }

            ExpenseReportRemote::create([
                'expense_report_id'   => $activeExpenseReport->id,
                'remote_id'           => $remote_id,
                'project_id'          => $ticket->project_id,
                'ticket_id'           => $ticket_id,
                'phase'               => $ticket->phase,
                'work_type_id'        => $workType->id,
                'work_unit'           => $remote->workUnit->name ?? null,
                'bc_tid'              => $remote->bc_tid ?? '-',
                'name'                => $remote->name,
                'ip_lan'              => $remote->ip_lan,
                'ip_p2p_modem'        => $remote->ip_p2p_modem,
                'site_id'             => $remote->site_id,
                'remote_type'         => $remote->remoteType->name ?? null,
                'link'                => $remote->link->name ?? null,
                'remote_territory'    => $remote->territory->name ?? null,
                'supervision'         => $remote->supervision->name ?? null,
                'home_base'           => $remote->homeBase->name ?? null,
                'address'             => $remote->address,
                'province_code'       => $remote->province_code,
                'city_code'           => $remote->city_code,
                'district_code'       => $remote->district_code,
                'village_code'        => $remote->village_code,
                'date'                => now(),
                'is_process_helpdesk' => false,
                'is_process_admin'    => false,
            ]);

            $checkTimer = TicketTimer::where('ticket_id', $ticket_id)
                ->whereNull('end_time')
                ->first();

            if (!$checkTimer) {
                TicketTimer::create([
                    'ticket_id'     => $ticket_id,
                    'start_user_id' => $request->user_id,
                    'start_time'    => now()
                ]);
            }
        }
    }
}
