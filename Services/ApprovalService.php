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
use DB;
use Ibinet\Models\Project;

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
     * @param string $refId Reference ID of the request
     * @param string $refType Type of the request (EXPENSE, FUND_REQUEST)
     * @param array $data Approval data containing role_id, user_id, note
     * @return array Status and message of the initialization process
     */
    public static function initStep($refId, $refType, $data)
    {
        try {
            // Get reference data
            list($approvalFlow, $entityData, $entityAmount, $defineLocation) = self::getInitialReferenceData($refId, $refType);
            
            if (!$entityData) {
                return [
                    'success' => false,
                    'message' => 'Reference entity not found'
                ];
            }
            
            if ($defineLocation == null) {
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }

            $projectId = $defineLocation['projectId'];
            $regionId = $defineLocation['regionId'];
            
            // Get first step in approval flow
            $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->orderBy('order')
                ->first();
                
            if (!$firstStep) {
                return [
                    'success' => false,
                    'message' => 'Approval flow steps not found'
                ];
            }
            
            // Get next step(s) - could be multiple depending on conditions
            $nextSteps = ApprovalFlowDetail::where('approval_flow_id', $approvalFlow)
                ->where('order', $firstStep->order)
                ->get();
                
            // Determine the appropriate next step based on conditions
            $nextStep = self::determineNextStep($nextSteps, $refType, $entityAmount);
            
            if (!$nextStep) {
                return [
                    'success' => false,
                    'message' => 'Could not determine next approval step'
                ];
            }
            // Find the next assignee based on conditions
            $nextAssignmentUser = self::fetchUserByCondition(
                $nextStep->status,
                $nextStep->role_id,
                $projectId,
                $regionId
            );
            
            if (!$nextAssignmentUser) {
                return [
                    'success' => false,
                    'message' => 'No user available for approval. Please check role configuration.'
                ];
            }
            
            // Create timestamped entries with microseconds to avoid duplicate timestamps
            $now = now();
            $microTime = microtime(true);
            
            // Create initiator activity
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $approvalFlow,
                'approval_flow_detail_id' => null,
                'step' => 0,
                'step_name' => self::getInitStepName($refType),
                'status' => 'ACTION', // ACTION: Just For First Step
                'role_id' => $data['role_id'],
                'user_id' => $data['user_id'],
                'note' => $data['note'],
                'process_at' => $now,
                'order' => 0
            ]);
            
            // Create first approval step activity
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
                'process_at' => $now,
                'order' => 1
            ]);
            
            // Update entity status
            // self::updateEntityStatus($refType, $refId, 'PENDING');
            
            return [
                'success' => true,
                'message' => 'Approval process has been initialized'
            ];
        } catch (\Exception $e) {
            \Log::error("Approval initialization error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error initializing approval: {$e->getMessage()}"
            ];
        }
    }
    
    /**
     * Get initial reference data for approval initialization
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @return array Array containing approval flow, entity data, amount, and location
     */
    private static function getInitialReferenceData($refId, $refType)
    {
        $approvalFlow = null;
        $entityData = null;
        $entityAmount = 0;
        $defineLocation = null;
        
        if ($refType == self::REF_EXPENSE) {
            $approvalFlow = setting('APPROVAL_EXPENSE_ER');
            $entityData = ExpenseReportBalance::find($refId);
            
            if ($entityData) {
                $entityAmount = $entityData->credit;
                $defineLocation = self::defineProjectAndRegionByLocation($entityData->location_type, $entityData->location_id);
            }
        } else if ($refType == self::REF_FUND_REQUEST) {
            $approvalFlow = setting('APPROVAL_FUND_REQUEST');
            $entityData = ExpenseReportRequest::find($refId);
            if ($entityData) {
                $entityAmount = $entityData->amount;
                $defineLocation = self::defineProjectAndRegionByFundRequest($entityData);
            }
        }
        
        return [$approvalFlow, $entityData, $entityAmount, $defineLocation];
    }
    
    /**
     * Determine which next step to use based on conditions
     * 
     * @param Collection $nextSteps Collection of possible next steps
     * @param string $refType Reference type
     * @param float $entityAmount Amount for condition checking
     * @return object|null Selected next step or null if none found
     */
    private static function determineNextStep($nextSteps, $refType, $entityAmount)
    {
        if ($nextSteps->count() == 1) {
            return $nextSteps->first();
        }
        
        if ($nextSteps->count() > 1) {
            // Look for a step with matching conditions
            foreach ($nextSteps as $step) {
                if (empty($step->condition) || empty($step->condition_value)) {
                    continue;
                }
                
                $condition = $step->condition;
                $conditionValue = $step->condition_value;
                
                if ($step->condition_id == 'ER_AMOUNT_FUND_REQUEST' && $refType == self::REF_FUND_REQUEST) {
                    if (eval("return \$entityAmount $condition $conditionValue;")) {
                        return $step;
                    }
                } else {
                    if (eval("return \$entityAmount $condition $conditionValue;")) {
                        return $step;
                    }
                }
            }
            
            // If no matching condition found, return the first step as fallback
            return $nextSteps->first();
        }
        
        return null;
    }
    
    /**
     * Get appropriate step name based on reference type
     * 
     * @param string $refType Reference type
     * @return string Step name
     */
    private static function getInitStepName($refType)
    {
        if ($refType == self::REF_EXPENSE) {
            return "Expense Report Request Initialization";
        } else if ($refType == self::REF_FUND_REQUEST) {
            return "Fund Request Initialization";
        }
        
        return "Request Initialization";
    }
    
    /**
     * Update entity status based on reference type
     * 
     * @param string $refType Reference type
     * @param string $refId Reference ID
     * @param string $status Status to set
     * @return void
     */
    private static function updateEntityStatus($refType, $refId, $status)
    {
        if ($refType == self::REF_EXPENSE) {
            ExpenseReportBalance::where('id', $refId)->update(['status' => $status]);
        } else if ($refType == self::REF_FUND_REQUEST) {
            ExpenseReportRequest::where('id', $refId)->update(['status' => $status]);
        }
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
        DB::beginTransaction();
        
        try {
            $approvalStatus = $data['status'];
            $currentActivity = self::fetchCurrentActivity($refId, $refType);
            
            if ($currentActivity == null) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Approval step not found'
                ];
            }
            
            if ($currentActivity->step - 1 == 1){
                // Validate if revision is allowed
                if ($approvalStatus == 'REVISION') {
                    $revisionCount = ApprovalRevisionHistory::where('ref_id', $refId)
                        ->where('ref_type', $refType)
                        ->count();
                        
                    if ($revisionCount > 0) {
                        DB::rollBack();
                        return [
                            'success' => false,
                            'message' => 'This request has already been revised'
                        ];
                    }

                    $previousData = self::fetchReleatedData($refId, $refType);
                    
                    // Record revision history
                    ApprovalRevisionHistory::create([
                        'ref_id' => $refId,
                        'ref_type' => $refType,
                        'approval_activity_id' => $currentActivity->id,
                        'user_id' => auth()->id(),
                        'data' => $previousData,
                        'note' => $data['note'],
                        'created_at' => now()
                    ]);
                }
            }
            
            // Get reference data and location information
            list($approvalFlow, $entityData, $expenseReportAmount, $defineLocation) = self::getReferenceData($refId, $refType);
            
            if ($defineLocation == null) {
                DB::rollBack();
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
            $result = null;
            if ($approvalStatus == 'REJECTED') {
                $result = self::handleRejection($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow);
            } else if ($approvalStatus == 'REVISION') {
                $result = self::handleRevision($refId, $refType, $currentActivity, $data, $approvalFlow, $projectId, $regionId);
            } else {
                // For APPROVED or other statuses
                $result = self::handleNextStep($refId, $refType, $currentActivity, $data, $entityData, $approvalFlow, $projectId, $regionId, $expenseReportAmount);
            }
            
            // Check if handler returned success
            if (!$result['success']) {
                DB::rollBack();
                return $result;
            }
            
            // Commit transaction if everything succeeded
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Approval process error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error processing approval: {$e->getMessage()}"
            ];
        } catch (\Throwable $t) {
            DB::rollBack();
            \Log::error("Approval process error: {$t->getMessage()} on line {$t->getLine()}");
            return [
                'success' => false,
                'message' => "Error processing approval: {$t->getMessage()}"
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
        try {
            // Validate current activity user exists
            if (!$currentActivity->user) {
                return [
                    'success' => false,
                    'message' => 'Current activity user not found'
                ];
            }
            
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
                'order' => $currentActivity->order + 1,
                'process_at' => now()
            ]);
            
            return [
                'success' => true,
                'message' => 'Request has been rejected'
            ];
        } catch (\Exception $e) {
            \Log::error("Handle rejection error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error handling rejection: {$e->getMessage()}"
            ];
        }
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
        try {
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
                    'process_at' => now(),
                    'order' => $currentActivity->order + 1
                ]);
            } else {
                // Find previous step using our new function
                $previousStepResult = self::findPreviousConditionalStep($refId, $refType, $currentActivity, $currentStep);
                
                if (!$previousStepResult['success']) {
                    return [
                        'success' => false,
                        'message' => $previousStepResult['message']
                    ];
                }
                
                $previousStep = $previousStepResult['step'];
                
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
                    'order' => $currentActivity->order + 1,
                    'process_at' => now()
                ]);
            }
            
            // Update entity status
            // TODO: Consider condition by revision status
            
            return [
                'success' => true,
                'message' => 'Request has been sent for revision'
            ];
        } catch (\Exception $e) {
            \Log::error("Handle revision error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error handling revision: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Find the previous step in the approval flow, considering conditional branching
     * 
     * @param string $refId Reference ID
     * @param string $refType Reference type
     * @param object $currentActivity Current approval activity
     * @param object $currentStep Current approval flow step
     * @return array Result containing success flag, message, and previous step
     */
    private static function findPreviousConditionalStep($refId, $refType, $currentActivity, $currentStep)
    {
        try {
            // If we don't have a current step or it's the first step, return null with a message
            if (!$currentStep || $currentActivity->step <= 1) {
                return [
                    'success' => false,
                    'message' => 'No previous step available',
                    'step' => null
                ];
            }
            
            $previousStepOrder = $currentStep->order - 1;
            
            // Get all previous steps with the previous order number
            $previousSteps = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
                ->where('order', $previousStepOrder)
                ->get();
            
            // If no previous steps found, return error
            if ($previousSteps->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'Previous approval step not found',
                    'step' => null
                ];
            }
            
            // If only one previous step exists, return it
            if ($previousSteps->count() == 1) {
                return [
                    'success' => true,
                    'message' => 'Previous step found',
                    'step' => $previousSteps->first()
                ];
            }
            
            // Complex case - need to find which conditional branch was taken
            // Find the actual previous activity to see which step was taken
            $previousActivity = ApprovalActivity::where('ref_id', $refId)
                ->where('ref_type', $refType)
                ->where('step', $previousStepOrder)
                ->orderBy('created_at', 'desc')
                ->orderBy('order', 'desc')
                ->first();
            
            if ($previousActivity && $previousActivity->approval_flow_detail_id) {
                // We found a record of which step was taken
                $previousStep = ApprovalFlowDetail::find($previousActivity->approval_flow_detail_id);
                
                if ($previousStep) {
                    return [
                        'success' => true,
                        'message' => 'Previous step found from activity history',
                        'step' => $previousStep
                    ];
                }
            }
            
            // No activity record found, try to determine based on conditions
            // Get reference data for condition evaluation
            list(, $entityData, $entityAmount, ) = self::getReferenceData($refId, $refType);
            
            $selectedStep = null;
            
            foreach ($previousSteps as $step) {
                if (empty($step->condition) || empty($step->condition_value)) {
                    continue;
                }
                
                $condition = $step->condition;
                $conditionValue = $step->condition_value;
                
                // Re-evaluate the condition with the historical entity amount
                if ($step->condition_id == 'ER_AMOUNT_FUND_REQUEST' && $refType == self::REF_FUND_REQUEST) {
                    if (eval("return \$entityAmount $condition $conditionValue;")) {
                        $selectedStep = $step;
                        break;
                    }
                } else {
                    if (eval("return \$entityAmount $condition $conditionValue;")) {
                        $selectedStep = $step;
                        break;
                    }
                }
            }
            
            // If no condition matched, take the first step (fallback)
            if (!$selectedStep && $previousSteps->isNotEmpty()) {
                $selectedStep = $previousSteps->first();
            }
            
            if ($selectedStep) {
                return [
                    'success' => true,
                    'message' => 'Previous step determined by conditions',
                    'step' => $selectedStep
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Could not determine previous step',
                'step' => null
            ];
        } catch (\Exception $e) {
            \Log::error("Error finding previous step: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error finding previous step: {$e->getMessage()}",
                'step' => null
            ];
        }
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
        try {
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
                    'process_at' => now(),
                    'order' => $currentActivity->order + 1
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
                    'message' => "No user available for the next approval step, Next Step Is: {$nextStep->name}"
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
                'order' => $currentActivity->order + 1,
                'process_at' => now()
            ]);
            
            return [
                'success' => true,
                'message' => 'Request moved to next approval step'
            ];
        } catch (\Exception $e) {
            \Log::error("Error handling next step: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error handling next step: {$e->getMessage()}"
            ];
        } catch (\Throwable $t) {
            \Log::error("Error handling next step: {$t->getMessage()} on line {$t->getLine()}");
            return [
                'success' => false,
                'message' => "Error handling next step: {$t->getMessage()}"
            ];
        }
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
            ->orderBy('order', 'desc')
            ->first();

        return $currentActivity;
    }

    /**
     * Fetch releated data for revision histories
     * 
     * @param $refId Reference ID
     * @param $refType Reference type
     * @return object|null
     */
    public static function fetchReleatedData($refId, $refType)
    {
        if ($refType == self::REF_EXPENSE) {
            return ExpenseReportBalance::find($refId);
        } else if ($refType == self::REF_FUND_REQUEST) {
            return ExpenseReportRequest::find($refId);
        }

        return null;
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
         // Get all eligible users based on conditions
         $eligibleUsers = collect();
         
         if($status == self::NO_ROLE_CONDITION){
            $eligibleUsers = User::where('role_id', $roleId)
                ->where('is_active', true)
                ->get();
        } else if($status == self::SAME_REGION){
            $eligibleUsers = User::where('role_id', $roleId)
                ->whereHas('region', function($query) use ($regionId) {
                    $query->where('regions.id', $regionId);
                })
                ->where('is_active', true)
                ->get();
        } else if($status == self::SAME_PROJECT){
            $eligibleUsers = User::where('role_id', $roleId)
                ->whereHas('project', function($query) use ($projectId) {
                    $query->where('projects.id', $projectId);
                })
                ->where('is_active', true)
                ->get();
        } else if ($status == self::SAME_HOMEBASE){
            // $eligibleUsers = User::where('role_id', $roleId)
            //     ->whereHas('homebase', function($query) use ($homebaseId) {
            //         $query->where('homebases.id', $homebaseId);
            //     })
            //     ->where('is_active', true)
            //     ->get();
            $eligibleUsers = collect();
        }

        if ($eligibleUsers->isEmpty()) {
            return null;
        }

        // If only one user, return that user
        if ($eligibleUsers->count() == 1) {
            return $eligibleUsers->first();
        }

        // Load balance: find user with least approval activities
        $userWorkloads = $eligibleUsers->map(function ($user) {
            $activeApprovals = ApprovalActivity::where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->count();
            
            return [
                'user' => $user,
                'workload' => $activeApprovals
            ];
        });

        // Sort by workload (ascending) and return user with least workload
        $selectedUser = $userWorkloads->sortBy('workload')->first();
        
        return $selectedUser['user'];
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
        $project =  Project::with('regions')->find($projectId);
        
        // Get the first region ID from the project's regions collection
        $regionId = $project->regions->first()?->id;
        
        return [
            'projectId' => $projectId,
            'regionId' => $regionId ?? null
        ];
    }
}
