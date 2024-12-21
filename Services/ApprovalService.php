<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReport;

class ApprovalService{

    /**
     * Create approval step initialization
     * 
     * @return void
     */
    public static function initStep($refId, $refType, $data)
    {
        if($refType == 'EXPENSE'){
            $approvalFlow = setting('APPROVAL_EXPENSE_ER');
            $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow->value)
                ->orderBy('step')
                ->first();

            // get second step
            $secondStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow->value)
                ->where('step', $firstStep->step + 1)
                ->first();

            // expense report balance
            $expenseReportBalance = ExpenseReportBalance::find($refId);
            $expenseReport = ExpenseReport::find($expenseReportBalance->expense_report_id);

            // get user region

            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'step' => $firstStep->step,
                'step_name' => $firstStep->role->name,
                'status' => 'ACTION',
                'role_id' => $data['role_id'],
                'user_id' => $data['user_id'],
                'note' => $data['note'],
                'process_at' => now()
            ]);

            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'step' => $secondStep->step,
                'step_name' => $secondStep->name,
                'status' => 'PENDING',
                'role_id' => $secondStep->role_id,
                'process_at' => now()
            ]);
        } else if($refType == 'FUND_REQUEST'){
            $approvalFlow = setting('APPROVAL_FUND_REQUEST');
        }
    }

    /**
     * Made first of step by mapping who made this data
     * 
     * @return void
     */
    public static function processApproval($refId, $refType, $data)
    {
        if($refType == 'EXPENSE'){

        } else if($refType == 'REQUEST_AMOUNT'){

        }
    }

    /**
     * Get current step of approval
     * 
     * @return void
     */
    public static function getCurrentStep($refId, $refType)
    {
        
    }

}