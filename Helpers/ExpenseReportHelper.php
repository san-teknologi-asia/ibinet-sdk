<?php

namespace Ibinet\Helpers;

use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\Ticket;
use Ibinet\Models\ProjectRemote;
use Ibinet\Models\Project;

class ExpenseReportHelper
{
    /**
     * Generate ER code
     *
     * @return String
     */
    public static function generateERCode()
    {
        $expenseReportCount = ExpenseReport::whereMonth('created_at', date('m'))
            ->whereYear('created_at', date('Y'))
            ->count();

        $expenseReportCode = "IER" . date('Ymd') . str_pad($expenseReportCount + 1, 4, '0', STR_PAD_LEFT);

        return $expenseReportCode;
    }

    /**
     * Generate Rquest code
     *
     * @param String $er_id
     * @return String
     */
    public static function generateRequestCode($er_id)
    {
        $expenseReport = ExpenseReport::find($er_id);
        $expenseReportRequest = ExpenseReportRequest::where('expense_report_id', $er_id)->count();

        $erRequestCode = "FR-" . $expenseReport->code . '-' . str_pad($expenseReportRequest + 1, 2, '0', STR_PAD_LEFT);

        return $erRequestCode;
    }

    /**
     * Generate transaction code
     *
     * @param String $er_id
     * @param String
     */
    public static function generateTransactionCode($er_id)
    {
        $expenseReport = ExpenseReport::find($er_id);
        $expenseReportTransaction = ExpenseReportBalance::where('expense_report_id', $er_id)
            ->where('code', 'like', 'TR-%')
            // ->where('credit', '>', 0)
            ->count();

        $transactionCode = "TR-" . $expenseReport->code . "-" . str_pad($expenseReportTransaction + 1, 3, '0', STR_PAD_LEFT);
        return $transactionCode;
    }

    /**
     * Generate Ticket code
     *
     * @return String
     */
    public static function generateTicketCode()
    {
        $dataCount = Ticket::whereMonth('created_at', date('m'))
            ->whereYear('created_at', date('Y'))
            ->count();

        $ticketCode = "TICKET" . date('Ymd') . str_pad($dataCount + 1, 4, '0', STR_PAD_LEFT);

        return $ticketCode;
    }

    /**
     * Generate phase list
     *
     * @param String $remote_id
     */
    public static function generatePhaseList($id)
    {
        $projectArray = ProjectRemote::where('remote_id', $id)
            ->get()
            ->pluck('project_id')
            ->toArray();

        $project = Project::whereIn('id', $projectArray)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();


        $phaseArray = array();

        foreach ($project as $key => $value) {
            if ($value->type == 'PROJECT') {
                for ($i = 1; $i <= $value->number_of_visits; $i++) {
                    array_push($phaseArray, ([
                        "text" => "Kunjungan {$i} ({$value->name})",
                        "phase" => $i,
                        "project_id" => $value->id
                    ]));
                }
            } else {
                array_push($phaseArray, ([
                    "text" => "Kunjungan {$value->name}",
                    "phase" => 1,
                    "project_id" => $value->id
                ]));
            }
        }

        return $phaseArray;
    }
}
