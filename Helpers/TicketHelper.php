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
     */
    public static function assignToExpenseReport($request, $ticket_id)
    {
        if ($request->user_id) {
            $activeExpenseReport = ExpenseReport::where([
                'assignment_to' => $request->user_id,
                'status' => 'ONGOING'
            ])->first();

            $user = User::find($request->user_id);
        }

        $workType = WorkType::where('code', 'CM')->first();
        $ticket = Ticket::find($ticket_id);
        $remote_id = $ticket->remote_id ?? $request->remote_id;

        $technician = $user->name ?? null;

        if ($technician != null) {
            $expenseName = "Progress CM - {$technician}";

            if (!$activeExpenseReport) {
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
                'schedule_id' => null,
                'date' => now(),
                'is_process_helpdesk' => false,
                'is_process_admin' => false,
                'helpdesk_status' => 'PENDING'
            ]);

            $checkTimer = TicketTimer::where('ticket_id', $ticket_id)
                ->whereNull('end_time')
                ->first();

            if (!$checkTimer) {
                TicketTimer::create([
                    'ticket_id' => $ticket_id,
                    'start_user_id' => $request->user_id,
                    'start_time' => now()
                ]);
            }
        } else {
            $expenseName = "First handling not assigned to any technician";
            $expenseReport = ExpenseReport::create([
                'code' => ExpenseReportHelper::generateERCode(),
                'name' => $expenseName,
                'amount' => $ticket->initial_amount ?? 100000,
                'assignment_to' => "-",
                'remark' => $expenseName,
                'created_by' => auth()->user()->id ?? auth('api')->user()->id,
                'is_tech' => false
            ]);

            ExpenseReportRequest::create([
                'expense_report_id' => $expenseReport->id,
                'amount' => $ticket->initial_amount ?? 100000,
                'code' => $ticket->code,
                'remark' => "Request For CM - {$technician} - {$ticket->code}",
                'status' => 'WAITING CONFIRMATION'
            ]);

            $activeExpenseReport = $expenseReport;

            ExpenseReportRemote::create([
                'expense_report_id' => $activeExpenseReport->id,
                'remote_id' => $remote_id,
                'project_id' => $ticket->project_id,
                'ticket_id' => $ticket_id,
                'phase' => $ticket->phase,
                'work_type_id' => $workType->id,
                'schedule_id' => null,
                'date' => now(),
                'is_process_helpdesk' => false,
                'is_process_admin' => false,
                'helpdesk_status' => 'PENDING'
            ]);
        }
    }

    /**
     * Create log ticket
     */
    public static function createLogTicket($request, $description)
    {
        $logName = $request->log_name ?? 'Ticket';
        $description = $description ?? 'Ticket Activity';
        $properties = $request->properties ?? [];

        $activity = activity()
            ->useLog($logName)
            ->withProperties($properties);

        if (isset($request->ticket_id)) {
            $ticket = Ticket::find($request->ticket_id);
            if ($ticket) {
                $activity->performedOn($ticket);
            }
        }

        if (isset($request->user_id)) {
            $user = User::find($request->user_id);
            if ($user) {
                $activity->causedBy($user);
            }
        } elseif (auth()->check()) {
            $activity->causedBy(auth()->user());
        }

        return $activity->log($description);
    }
}
