<?php

namespace Ibinet\Helpers;

use Ibinet\Models\ExpenseReport;
use Ibinet\Models\User;
use Ibinet\Models\WorkType;
use Ibinet\Models\Ticket;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\TicketTimer;
use Ibinet\Helpers\ExpenseReportHelper;
use Ibinet\Models\ExpenseReportRequest;

class TicketHelper
{
    /**
     * Check and recreate ticket for teknisi
     *
     * @return boolean
     */
    public static function assignToExpenseReport($request, $ticket_id)
    {
        $activeExpenseReport = ExpenseReport::where([
                'assignment_to' => $request->user_id,
                'status' => 'ONGOING'
            ])->first();

        $user = User::find($request->user_id);

        $workType = WorkType::where('code', 'CM')->first();
        $ticket = Ticket::find($ticket_id);
        $remote_id = $ticket->remote_id ?? $request->remote_id;

        $technician = $user->name ?? null;

        if ($technician != null) {
            $expenseName = "Progress CM - {$technician}";

            if(!$activeExpenseReport){
                $expenseReport = ExpenseReport::create([
                    'code' => ExpenseReportHelper::generateERCode(),
                    'name' => $expenseName,
                    'amount' => $ticket->initial_amount ?? 100000,
                    'assignment_to' => $request->user_id,
                    'remark' => $expenseName,
                    'created_by' => auth()->user()->id ?? auth('api')->user()->id
                ]);

                ExpenseReportRequest::create([
                    'expense_report_id' => $expenseReport->id,
                    'amount' => $ticket->initial_amount ?? 100000,
                    'code' => $ticket->code,
                    'remark' => "Request For CM - {$technician} - {$ticket->code}",
                    'status' => 'WAITING CONFIRMATION'
                ]);

                $activeExpenseReport = $expenseReport;
            } 

            ExpenseReportRemote::create([
                'expense_report_id' => $activeExpenseReport->id,
                'remote_id' => $remote_id,
                'project_id' => $ticket->project_id,
                'ticket_id' => $ticket_id,
                'phase' => $ticket->phase,
                'work_type_id' => $workType->id,
                'schedule_id' => $ticket->id, // TODO: Change it into null
                'date' => now(),
                'is_process_helpdesk' => false,
                'is_process_admin' => false,
            ]);

            $checkTimer = TicketTimer::where('ticket_id', $ticket_id)
                ->whereNull('end_time')
                ->first();

            if(!$checkTimer){
                TicketTimer::create([
                    'ticket_id' => $ticket_id,
                    'start_user_id' => $request->user_id,
                    'start_time' => now()
                ]);
            }
        }
    }
}