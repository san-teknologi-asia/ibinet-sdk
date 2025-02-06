<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\User;

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
        $projectId = null;
        $regionId = null;

        if($refType == self::REF_EXPENSE){
            $approvalFlow = setting('APPROVAL_EXPENSE_ER');

            // first step
            $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->orderBy('order')
                ->first();

            // get second step
            $secondStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->where('order', $firstStep->order)
                ->get();

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

            // first step
            $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->orderBy('order')
                ->first();

            // get second step
            $secondStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->where('order', $firstStep->order)
                ->get();

            // expense report balance
            $expenseReportRequest = ExpenseReportRequest::find($refId);

            $defineLocation = self::defineProjectAndRegionByFundRequest($expenseReportRequest);

            if($defineLocation != null){
                $projectId = $defineLocation['projectId'];
                $regionId = $defineLocation['regionId'];
            } else{
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
        }

        if (count($secondStep) == 1){
            $secondStep = $secondStep[0];
        } else if (count($secondStep) > 1){
            // TECH DEBT : Adding process and ask if when request fund it should place location id
            foreach ($secondStep as $key => $value) {
                if($value->condition_id == 'ER_AMOUNT_FUND_REQUEST'){
                    $condition = $value->condition;
                    $conditionValue = $value->condition_value;

                    // Build a dynamic PHP condition
                    if (eval("return \$expenseReportRequest->amount $condition $conditionValue;")) {
                        $secondStep = $value; // Assign the current step as the next step
                        break; // Exit the loop once the condition is satisfied
                    }
                }
            }
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
            'approval_flow_id' => $approvalFlow,
            'approval_flow_detail_id' => null,
            'step' => 0,
            'step_name' => "Expense Report Request Initialization",
            'status' => 'ACTION', // ACTION : Just For First Step
            'role_id' => $data['role_id'],
            'user_id' => $data['user_id'],
            'note' => $data['note'],
            'process_at' => now()
        ]);

        ApprovalActivity::create([
            'ref_id' => $refId,
            'ref_type' => $refType,
            'approval_flow_id' => $approvalFlow,
            'approval_flow_detail_id' => $secondStep->id,
            'step' => $secondStep->order,
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
     * @return array
     */
    public static function processApproval($refId, $refType, $data)
    {
        try{
            $approvalStatus = $data['status'];
            $projectId = null;
            $regionId = null;
            $isLastStep = false;

            $currentActivity = self::fetchCurrentActivity($refId, $refType);
            $currentStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('id', $currentActivity->approval_flow_detail_id)
                ->first();

            if($currentActivity == null){
                return [
                    'success' => false,
                    'message' => 'Approval step not found'
                ];
            }

            $projectId = null;
            $regionId = null;
            if ($approvalStatus == 'REVISION'){
                $nextStepOrder = $currentStep->order - 1;
            } else{
                $nextStepOrder = $currentStep->order + 1;
            }

            // get second step
            $nextStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('order', $nextStepOrder)
                ->get();

            if($refType == self::REF_EXPENSE){
                $approvalFlow = setting('APPROVAL_EXPENSE_ER');
                $expenseReportBalance = ExpenseReportBalance::find($refId);
                $expenseReportAmount = $expenseReportBalance->credit;

                $defineLocation = self::defineProjectAndRegionByLocation($expenseReportBalance->location_type, $expenseReportBalance->location_id);

                // Check approval status
                if($approvalStatus == 'REJECTED'){
                    $expenseReportBalance->update([
                        'status' => $data['status']
                    ]);
                }
            } else if($refType == self::REF_FUND_REQUEST){
                $approvalFlow = setting('APPROVAL_FUND_REQUEST');
                $expenseReportRequest = ExpenseReportRequest::find($refId);
                $expenseReportAmount = $expenseReportRequest->amount;

                $defineLocation = self::defineProjectAndRegionByFundRequest($expenseReportRequest);

                // Check approval status
                if($approvalStatus == 'REJECTED'){
                    $expenseReportRequest->update([
                        'status' => $data['status']
                    ]);
                }
            }

            if($defineLocation != null){
                $projectId = $defineLocation['projectId'];
                $regionId = $defineLocation['regionId'];
            } else{
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }

            // Check for next step
            $numberOfNextStep = count($nextStep);
            if($numberOfNextStep == 0){
                $isLastStep = true;
            } elseif ($numberOfNextStep == 1){
                $nextStep = $nextStep[0];
            } elseif ($numberOfNextStep > 1){ // Condition For Multiple Step Ahead
                foreach ($nextStep as $key => $value) {
                    $condition = $value->condition;
                    $conditionValue = $value->condition_value;

                    if (eval("return \$expenseReportAmount $condition $conditionValue;")) {
                        $nextStep = $value; // Assign the current step as the next step
                        break; // Exit the loop once the condition is satisfied
                    }
                }
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

            if (!$isLastStep){
                ApprovalActivity::find($currentActivity->id)->update([
                    'processed_at' => now(),
                    'status' => $data['status'],
                    'note' => $data['note'],
                    'process_at' => now()
                ]);

                if($data['status'] == 'REJECTED'){
                    ApprovalActivity::create([
                        'ref_id' => $refId,
                        'ref_type' => $refType,
                        'approval_flow_id' => $approvalFlow,
                        'approval_flow_detail_id' => null,
                        'step' => 0,
                        'step_name' => "Approval Finished With Rejected By ".$currentActivity->user->name,
                        'status' => 'END',
                        'role_id' => $currentActivity->role_id,
                        'user_id' => $currentActivity->user_id,
                        'note' => $data['note'],
                        'process_at' => now()
                    ]);
                } else{
                    ApprovalActivity::create([
                        'ref_id' => $refId,
                        'ref_type' => $refType,
                        'approval_flow_id' => $approvalFlow,
                        'approval_flow_detail_id' => $nextStep->id,
                        'step' => $nextStep->order,
                        'step_name' => $nextStep->name,
                        'status' => 'PENDING',
                        'role_id' => $nextStep->role_id,
                        'user_id' => $nextAssignmentUser->id,
                        'process_at' => now()
                    ]);
                }
            } else{
                ApprovalActivity::find($currentActivity->id)->update([
                    'processed_at' => now(),
                    'status' => $data['status'],
                    'note' => $data['note'],
                    'process_at' => now()
                ]);

                ApprovalActivity::create([
                    'ref_id' => $refId,
                    'ref_type' => $refType,
                    'approval_flow_id' => $approvalFlow,
                    'approval_flow_detail_id' => null,
                    'step' => 0,
                    'step_name' => "Approval Finished With ".$data['status']." By ".$currentActivity->user->name,
                    'status' => 'END',
                    'role_id' => $currentActivity->role_id,
                    'user_id' => $currentActivity->user_id,
                    'note' => $data['note'],
                    'process_at' => now()
                ]);

                $expenseReportRequest->update([
                    'status' => $data['status']
                ]);
            }

            return [
                'success' => true,
                'message' => 'Approval has been made'
            ];
        } catch (\Exception $e){
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get current step of approval
     *
     * @return object
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
                    $query->where('projects.id', $projectId);
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
            $regionId = $expenseReportLocation->remote->region_id;
        } else{
            return null;
        }

        return [
            'projectId' => $projectId,
            'regionId' => $regionId
        ];
    }

    /**
     * Define project and region by fund request
     *
     * @return void
     */
    public static function defineProjectAndRegionByFundRequest($expenseReportRequest)
    {
        $projectId = $expenseReportRequest->project_id;

        return [
            'projectId' => $projectId,
            'regionId' => null
        ];
    }
}
