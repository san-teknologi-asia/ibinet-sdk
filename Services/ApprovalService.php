<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\User;
use Ibinet\Models\Project;

class ApprovalService{

    private const NO_ROLE_CONDITION = 'NO-ROLE-CONDITION';
    private const SAME_REGION = 'SAME-REGION';
    private const SAME_PROJECT = 'SAME-PROJECT';

    /**
     * Create approval step initialization
     * 
     * @return void
     */
    public static function initStep($refId, $refType, $data)
    {
        if($refType == 'EXPENSE'){
            $approvalFlow = setting('APPROVAL_EXPENSE_ER');
            $projectId = null;
            $regionId = null;

            // first step
            $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow->value)
                ->orderBy('step')
                ->first();

            // get second step
            $secondStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow->value)
                ->where('step', $firstStep->step + 1)
                ->first();

            // expense report balance
            $expenseReportBalance = ExpenseReportBalance::find($refId);

            if($expenseReportBalance->location_type == 'REGION'){
                $expenseReportLocation = ExpenseReportLocation::find($expenseReportBalance->location_id);
                $projectId = $expenseReportLocation->project_id;
                $regionId = $expenseReportLocation->region_id;
            } else if($expenseReportBalance->location_type == 'REMOTE'){
                $expenseReportLocation = ExpenseReportRemote::find($expenseReportBalance->location_id);
                $projectId = $expenseReportLocation->project_id;
                $project = Project::find($projectId);
                $regionId = $project->region_id;
            } else{
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
        } else if($refType == 'FUND_REQUEST'){
            $approvalFlow = setting('APPROVAL_FUND_REQUEST');

            // TECH DEBT : Adding process and ask if when request fund it should place location id
        }

        $nextAssignmentUser = self::fetchUserByCondition(
            $secondStep->status, 
            $secondStep->role_id,
            $projectId,
            $regionId
        );

        if ($nextAssignmentUser == null){
            return [
                'success' => false,
                'message' => 'User not available'
            ];
        }

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
            'user_id' => $nextAssignmentUser->id,
            'process_at' => now()
        ]);

        return [
            'success' => true,
            'message' => 'Approval has been made'
        ];
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

    /**
     * Fetch user by condition
     * 
     * @param String $roleId
     * @param String $projectId
     * @param String $regionId
     */
    private static function fetchUserByCondition($status ,$roleId, $projectId = null, $regionId = null)
    {
         // check role status condition 
         if($status == self::NO_ROLE_CONDITION){
            $nextAssignmentUser = User::where('role_id', $roleId)
                ->where('is_active', true)
                ->first();
        } else if($status == self::SAME_REGION){
            $nextAssignmentUser = User::where('role_id', $roleId)
                ->whereHas('region', function($query) use ($regionId) {
                    $query->where('id', $regionId);
                })
                ->where('is_active', true)
                ->first();
        } else if($status == self::SAME_PROJECT){
            $nextAssignmentUser = User::where('role_id', $roleId)
                ->whereHas('project', function($query) use ($projectId) {
                    $query->where('id', $projectId);
                })
                ->where('is_active', true)
                ->first();
        }

        return $nextAssignmentUser;
    }

}