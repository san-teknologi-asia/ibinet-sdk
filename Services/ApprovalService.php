<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ApprovalFlow;
use Ibinet\Models\ApprovalFlowCondition;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\User;
use Ibinet\Models\Project;

class ApprovalService{

    private const NO_ROLE_CONDITION = 'NO-ROLE-CONDITION';
    private const SAME_REGION = 'SAME-REGION';
    private const SAME_PROJECT = 'SAME-PROJECT';

    private const REF_EXPENSE = 'EXPENSE';
    private const REF_FUND_REQUEST = 'FUND_REQUEST';

    /**
     * Create approval step initialization
     * 
     * @return void
     */
    public static function initStep($refId, $refType, $data)
    {
        if($refType == self::REF_EXPENSE){
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

            $defineLocation = self::defineProjectAndRegionByLocation($expenseReportBalance->location_type, $expenseReportBalance->location_id);

            if($defineLocation != null){
                $projectId = $defineLocation['projectId'];
                $regionId = $defineLocation['regionId'];
            } else{
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
        } else if($refType == self::REF_FUND_REQUEST){
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
            'status' => 'ACTION', // ACTION : Just For First Step
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
        $currentActivity = self::fetchCurrentActivity($refId, $refType);

        if($currentActivity == null){
            return [
                'success' => false,
                'message' => 'Approval step not found'
            ];
        }

        $projectId = null;
        $regionId = null;

        if($refType == self::REF_EXPENSE){
            $expenseReportBalance = ExpenseReportBalance::find($refId);

            // first step
            $currentStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('id', $currentActivity->approval_flow_detail_id)
                ->first();

            // get second step
            $nextStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('step', $currentStep->step + 1)
                ->get();

            if(count($nextStep) == 0){
                return [
                    'success' => false,
                    'message' => 'Approval step not found'
                ];
            } else if (count($nextStep) == 1){
                $nextStep = $nextStep[0];
            } else if (count($nextStep) > 1){
                // TECH DEBT : Adding process and ask if when request fund it should place location id
                foreach ($nextStep as $key => $value) {
                    if($value->condition_id == 'ER_EXPENSE_AMOUNT'){
                        $condition = $value->condition;
                        $conditionValue = $value->condition_value;

                        // Build a dynamic PHP condition
                        if (eval("return \$expenseReportBalance->credit $condition $conditionValue;")) {
                            $nextStep = $value; // Assign the current step as the next step
                            break; // Exit the loop once the condition is satisfied
                        }
                    }
                }
            }

            $defineLocation = self::defineProjectAndRegionByLocation($expenseReportBalance->location_type, $expenseReportBalance->location_id);

            if($defineLocation != null){
                $projectId = $defineLocation['projectId'];
                $regionId = $defineLocation['regionId'];
            } else{
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
        } else if($refType == self::REF_FUND_REQUEST){
            // TECH DEBT : Adding process and ask if when request fund it should place location id
        }

        $nextAssignmentUser = self::fetchUserByCondition(
            $nextStep->status, 
            $nextStep->role_id,
            $projectId,
            $regionId
        );

        if ($nextAssignmentUser == null){
            return [
                'success' => false,
                'message' => 'User not available'
            ];
        }


        ApprovalActivity::find($currentActivity->id)->update([
            'status' => $data['status'],
            'note' => $data['note'],
            'process_at' => now()
        ]);

        ApprovalActivity::create([
            'ref_id' => $refId,
            'ref_type' => $refType,
            'step' => $nextStep->step,
            'step_name' => $nextStep->name,
            'status' => 'PENDING',
            'role_id' => $nextStep->role_id,
            'user_id' => $nextAssignmentUser->id,
            'process_at' => now()
        ]);

        return [
            'success' => true,
            'message' => 'Approval has been made'
        ];
    }

    /**
     * Get current step of approval
     * 
     * @return void
     */
    public static function fetchCurrentActivity($refId, $refType)
    {
        $currentActivity = ApprovalActivity::where('ref_id', $refId)
            ->where('ref_type', $refType)
            ->orderBy('step', 'desc')
            ->first();

        return $currentActivity;
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

    /**
     * Define project and region by location
     * 
     * @param String $locationType
     * @param String $locationId
     */
    public static function defineProjectAndRegionByLocation($locationType, $locationId)
    {
        if($locationType == 'REGION'){
            $expenseReportLocation = ExpenseReportLocation::find($locationId);
            $projectId = $expenseReportLocation->project_id;
            $regionId = $expenseReportLocation->region_id;
        } else if($locationType == 'REMOTE'){
            $expenseReportLocation = ExpenseReportRemote::find($locationId);
            $projectId = $expenseReportLocation->project_id;
            $project = Project::find($projectId);
            $regionId = $project->region_id;
        } else{
            return null;
        }

        return [
            'projectId' => $projectId,
            'regionId' => $regionId
        ];
    }
}