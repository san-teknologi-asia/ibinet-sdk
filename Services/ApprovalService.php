<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\ApprovalRevisionHistory;
use Ibinet\Models\User;

class ApprovalService{

    private const NO_ROLE_CONDITION = 'NO-ROLE-CONDITION';
    private const SAME_REGION = 'SAME-REGION';
    private const SAME_PROJECT = 'SAME-PROJECT';
    private const SAME_HOMEBASE = 'SAME-HOMEBASE';

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
                } else{
                    $condition = $value->condition;
                    $conditionValue = $value->condition_value;

                    // Build a dynamic PHP condition
                    if (eval("return \$expenseReportBalance->credit $condition $conditionValue;")) {
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
     * Process approval for a request
     *
     * @param string $refId Reference ID of the request
     * @param string $refType Type of the request (EXPENSE, FUND_REQUEST)
     * @param array $data Approval data containing status, note, etc.
     * @return array Status and message of the approval process
     */
    public static function processApproval($refId, $refType, $data)
    {
        try {
            $approvalStatus = $data['status'];
            $currentActivity = self::fetchCurrentActivity($refId, $refType);
            
            if ($currentActivity == null) {
                return [
                    'success' => false,
                    'message' => 'Approval step not found'
                ];
            }
            
            // Validate if revision is allowed
            if ($approvalStatus == 'REVISION') {
                $revisionCount = ApprovalRevisionHistory::where('ref_id', $refId)
                    ->where('ref_type', $refType)
                    ->count();
                    
                if ($revisionCount > 0) {
                    return [
                        'success' => false,
                        'message' => 'This request has already been revised'
                    ];
                }
                
                // Record revision history
                ApprovalRevisionHistory::create([
                    'ref_id' => $refId,
                    'ref_type' => $refType,
                    'approval_activity_id' => $currentActivity->id,
                    'user_id' => auth()->id(),
                    'note' => $data['note'],
                    'created_at' => now()
                ]);
            }
            
            // Get reference data and location information
            list($approvalFlow, $entityData, $expenseReportAmount, $defineLocation) = self::getReferenceData($refId, $refType);
            
            if ($defineLocation == null) {
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
            
            $projectId = $defineLocation['projectId'];
            $regionId = $defineLocation['regionId'];
            
            // Update current activity status
            ApprovalActivity::find($currentActivity->id)->update([
                'processed_at' => now(),
                'status' => $approvalStatus,
                'note' => $data['note'],
                'process_at' => now()
            ]);
            
            // Handle based on approval status
            if ($approvalStatus == 'REJECTED') {
                return self::handleRejection($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow);
            } else if ($approvalStatus == 'REVISION') {
                return self::handleRevision($refId, $refType, $currentActivity, $data, $approvalFlow, $projectId, $regionId);
            } else {
                // For APPROVED or other statuses
                return self::handleNextStep($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow, $projectId, $regionId, $expenseReportAmount);
            }
        } catch (\Exception $e) {
            \Log::error("Approval process error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error processing approval: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Handle rejection of an approval
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @param object $currentActivity Current approval activity
     * @param array $data Approval data
     * @param object $entityData Entity data (expense report or fund request)
     * @param string $approvalFlow Approval flow ID
     * @return array Result of the rejection process
     */
    private static function handleRejection($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow)
    {
        // Update entity status
        $entityData->update(['status' => 'REJECTED']);
        
        // Create end activity
        ApprovalActivity::create([
            'ref_id' => $refId,
            'ref_type' => $refType,
            'approval_flow_id' => $approvalFlow,
            'approval_flow_detail_id' => null,
            'step' => $currentActivity->step + 1,
            'step_name' => "Approval Finished With Rejected By " . $currentActivity->user->name,
            'status' => 'END',
            'role_id' => $currentActivity->role_id,
            'user_id' => $currentActivity->user_id,
            'note' => $data['note'],
            'process_at' => now()
        ]);
        
        return [
            'success' => true,
            'message' => 'Request has been rejected'
        ];
    }

    /**
     * Handle revision of an approval
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @param object $currentActivity Current approval activity
     * @param array $data Approval data
     * @param string $approvalFlow Approval flow ID
     * @param string $projectId Project ID
     * @param string $regionId Region ID
     * @return array Result of the revision process
     */
    private static function handleRevision($refId, $refType, $currentActivity, $data, $approvalFlow, $projectId, $regionId)
    {
        // Find the previous step
        $currentStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
            ->where('id', $currentActivity->approval_flow_detail_id)
            ->first();
            
        if (!$currentStep || $currentActivity->step <= 1) {
            // If first step or step not found, return to initiator
            $firstActivity = ApprovalActivity::where('ref_id', $refId)
                ->where('ref_type', $refType)
                ->where('step', 0)
                ->first();
                
            if (!$firstActivity) {
                return [
                    'success' => false,
                    'message' => 'Cannot find initiator for revision'
                ];
            }
            
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $approvalFlow,
                'approval_flow_detail_id' => null,
                'step' => 0,
                'step_name' => "Revision Requested",
                'status' => 'REVISION',
                'role_id' => $firstActivity->role_id,
                'user_id' => $firstActivity->user_id,
                'note' => "Revision requested: " . $data['note'],
                'process_at' => now()
            ]);
        } else {
            // Find previous step
            $previousStepOrder = $currentStep->order - 1;
            $previousStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('order', $previousStepOrder)
                ->first();
                
            if (!$previousStep) {
                return [
                    'success' => false,
                    'message' => 'Previous approval step not found'
                ];
            }
            
            // Find previous approver
            $previousActivity = ApprovalActivity::where('ref_id', $refId)
                ->where('ref_type', $refType)
                ->where('approval_flow_detail_id', $previousStep->id)
                ->orderBy('created_at', 'desc')
                ->first();
                
            if (!$previousActivity) {
                // If previous activity not found, find a user based on role conditions
                $previousUser = self::fetchUserByCondition(
                    $previousStep->status,
                    $previousStep->role_id,
                    $projectId,
                    $regionId
                );
                
                if (!$previousUser) {
                    return [
                        'success' => false,
                        'message' => 'Previous approver not found'
                    ];
                }
                
                $previousUserId = $previousUser->id;
                $previousRoleId = $previousStep->role_id;
            } else {
                $previousUserId = $previousActivity->user_id;
                $previousRoleId = $previousActivity->role_id;
            }
            
            // Create new approval activity for the previous step
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $approvalFlow,
                'approval_flow_detail_id' => $previousStep->id,
                'step' => $previousStep->order,
                'step_name' => $previousStep->name,
                'status' => 'REVISION',
                'role_id' => $previousRoleId,
                'user_id' => $previousUserId,
                'note' => "Revision requested: " . $data['note'],
                'process_at' => now()
            ]);
        }
        
        // Update entity status
        if ($refType == self::REF_EXPENSE) {
            ExpenseReportBalance::find($refId)->update(['status' => 'REVISION']);
        } else if ($refType == self::REF_FUND_REQUEST) {
            ExpenseReportRequest::find($refId)->update(['status' => 'REVISION']);
        }
        
        return [
            'success' => true,
            'message' => 'Request has been sent for revision'
        ];
    }

    /**
     * Handle next step in approval process
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @param object $currentActivity Current approval activity
     * @param array $data Approval data
     * @param object $entityData Entity data
     * @param string $approvalFlow Approval flow ID
     * @param string $projectId Project ID
     * @param string $regionId Region ID
     * @param float $expenseReportAmount Amount for condition checking
     * @return array Result of the next step process
     */
    private static function handleNextStep($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow, $projectId, $regionId, $expenseReportAmount)
    {
        $currentStep = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
            ->where('id', $currentActivity->approval_flow_detail_id)
            ->first();
            
        $nextStepOrder = $currentStep ? $currentStep->order + 1 : 1;
        
        // Get next steps based on the order
        $nextSteps = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
            ->where('order', $nextStepOrder)
            ->get();
            
        // Check if we have next steps
        if ($nextSteps->isEmpty()) {
            // This is the last step, mark as completed
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $approvalFlow,
                'approval_flow_detail_id' => null,
                'step' => $nextStepOrder,
                'step_name' => "Approval Completed",
                'status' => 'END',
                'role_id' => $currentActivity->role_id,
                'user_id' => $currentActivity->user_id,
                'note' => "Approval completed with final note: " . $data['note'],
                'process_at' => now()
            ]);
            
            // Update entity status
            $entityData->update(['status' => 'APPROVED']);
            
            return [
                'success' => true,
                'message' => 'Approval process completed successfully'
            ];
        }
        
        // Determine the next step based on conditions
        $nextStep = null;
        
        if ($nextSteps->count() == 1) {
            $nextStep = $nextSteps->first();
        } else {
            // Evaluate conditions to find the appropriate next step
            foreach ($nextSteps as $step) {
                // Skip steps without conditions
                if (empty($step->condition) || empty($step->condition_value)) {
                    continue;
                }
                
                $condition = $step->condition;
                $conditionValue = $step->condition_value;
                
                // Evaluate the condition
                if (eval("return \$expenseReportAmount $condition $conditionValue;")) {
                    $nextStep = $step;
                    break;
                }
            }
            
            // If no condition matched, take the first step (fallback)
            if (!$nextStep && $nextSteps->isNotEmpty()) {
                $nextStep = $nextSteps->first();
            }
        }
        
        if (!$nextStep) {
            return [
                'success' => false,
                'message' => 'Could not determine next approval step'
            ];
        }
        
        // Find the next assignee
        $nextAssignmentUser = self::fetchUserByCondition(
            $nextStep->status,
            $nextStep->role_id,
            $projectId,
            $regionId
        );
        
        if (!$nextAssignmentUser) {
            return [
                'success' => false,
                'message' => 'No user available for the next approval step'
            ];
        }
        
        // Create next approval activity
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
            'note' => null,
            'process_at' => now()
        ]);
        
        return [
            'success' => true,
            'message' => 'Request moved to next approval step'
        ];
    }

    /**
     * Get reference data for the approval
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @return array Array containing approval flow, entity data, amount, and location
     */
    private static function getReferenceData($refId, $refType)
    {
        $approvalFlow = null;
        $entityData = null;
        $expenseReportAmount = 0;
        $defineLocation = null;
        
        if ($refType == self::REF_EXPENSE) {
            $approvalFlow = setting('APPROVAL_EXPENSE_ER');
            $entityData = ExpenseReportBalance::find($refId);
            $expenseReportAmount = $entityData->credit;
            $defineLocation = self::defineProjectAndRegionByLocation($entityData->location_type, $entityData->location_id);
        } else if ($refType == self::REF_FUND_REQUEST) {
            $approvalFlow = setting('APPROVAL_FUND_REQUEST');
            $entityData = ExpenseReportRequest::find($refId);
            $expenseReportAmount = $entityData->amount;
            $defineLocation = self::defineProjectAndRegionByFundRequest($entityData);
        }
        
        return [$approvalFlow, $entityData, $expenseReportAmount, $defineLocation];
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
            ->orderBy('created_at', 'desc')
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
        } else if ($status == self::SAME_HOMEBASE){
            // $nextAssignmentUser = User::where('role_id', $roleId)
            //     ->whereHas('homebase', function($query) use ($homebaseId) {
            //         $query->where('homebases.id', $homebaseId);
            //     })
            //     ->where('is_active', true)
            //     ->first();
            $nextAssignmentUser = null;
        } else{
            $nextAssignmentUser = null;
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
