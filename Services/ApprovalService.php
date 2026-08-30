<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlow;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\ApprovalRevisionHistory;
use Ibinet\Models\User;
use DB;
use Ibinet\Helpers\PeriodHelper;
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
            $homeBaseId = $defineLocation['homeBaseId'] ?? null;
            $technicianUserId = $defineLocation['technicianUserId'] ?? null;

            // Get first step in approval flow
            $firstStageOrder = self::fetchFirstStageOrder($approvalFlow);

            if ($firstStageOrder === null) {
                return [
                    'success' => false,
                    'message' => 'Approval flow steps not found'
                ];
            }

            // Get next step(s) - could be multiple depending on conditions
            $nextSteps = self::fetchStageSteps($approvalFlow, $firstStageOrder);

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
                $regionId,
                $homeBaseId,
                $refType,
                $technicianUserId
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
                'processed_at' => $now,
                'order' => 0
            ]);
            
            // Create first approval step activity. The flow id recorded here is
            // the version the request runs on for its whole life, even if the
            // active setting later moves to a newer version.
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $approvalFlow,
                'approval_flow_detail_id' => $nextStep->id,
                'step_snapshot' => self::buildStepSnapshot($nextStep),
                'step' => $nextStep->order,
                'step_name' => $nextStep->name,
                'status' => 'PENDING',
                'role_id' => $nextStep->role_id,
                'user_id' => $nextAssignmentUser->id,
                'processed_at' => $now,
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
     * Compare an amount against a step's configured operator and threshold.
     *
     * The operator and threshold are operator-supplied strings from the approval
     * flow form, so they are matched against a fixed set rather than executed.
     * An unrecognised operator or non-numeric threshold never matches.
     *
     * @param  mixed  $entityAmount
     * @param  string|null  $condition
     * @param  string|null  $conditionValue
     * @return bool
     */
    private static function compareAmount($entityAmount, $condition, $conditionValue)
    {
        if (!is_numeric($entityAmount) || !is_numeric($conditionValue)) {
            return false;
        }

        $left = (float) $entityAmount;
        $right = (float) $conditionValue;

        switch (trim((string) $condition)) {
            case '<':
                return $left < $right;
            case '<=':
                return $left <= $right;
            case '>':
                return $left > $right;
            case '>=':
                return $left >= $right;
            case '=':
            case '==':
                return $left == $right;
            case '!=':
            case '<>':
                return $left != $right;
            default:
                return false;
        }
    }

    /**
     * Freeze a step definition so the activity row stays readable after the
     * flow moves to a newer version.
     *
     * @param  \Ibinet\Models\ApprovalFlowDetail  $step
     * @return array
     */
    private static function buildStepSnapshot($step)
    {
        return [
            'approval_flow_detail_id' => $step->id,
            'approval_flow_id' => $step->approval_flow_id,
            'name' => $step->name,
            'role_id' => $step->role_id,
            'status' => $step->status,
            'condition_id' => $step->condition_id,
            'condition' => $step->condition,
            'condition_value' => $step->condition_value,
            'order' => (int) $step->order,
        ];
    }

    /**
     * Work out which stage the given activity sits at.
     *
     * Returns null when the activity is not a flow step at all — the initiator
     * row and the revision bounce both carry no detail id, and both mean the
     * request should enter the flow again from the first stage.
     *
     * @param  \Ibinet\Models\ApprovalActivity  $currentActivity
     * @return int|null
     */
    private static function resolveCurrentStepOrder($currentActivity)
    {
        if (empty($currentActivity->approval_flow_detail_id)) {
            return null;
        }

        // The snapshot is authoritative: it survives the flow being superseded,
        // which a lookup against the master row does not.
        $snapshot = $currentActivity->step_snapshot;

        if (is_array($snapshot) && isset($snapshot['order'])) {
            return (int) $snapshot['order'];
        }

        $step = ApprovalFlowDetail::where('approval_flow_id', $currentActivity->approval_flow_id)
            ->where('id', $currentActivity->approval_flow_detail_id)
            ->first();

        if ($step) {
            return (int) $step->order;
        }

        // Written before snapshots existed, and its detail row was destroyed by
        // an in-place flow edit. `step` recorded the order at creation time, so
        // the chain can still move forward rather than silently restarting at
        // stage one and asking everyone to approve again.
        return (int) $currentActivity->step;
    }

    /**
     * The order of the first stage in a flow.
     *
     * Flows built through the form are renumbered from 1, but nothing in the
     * schema guarantees it, and assuming 1 would quietly finish a request that
     * re-enters the flow instead of restarting it.
     *
     * @param  string  $approvalFlowId
     * @return int|null
     */
    private static function fetchFirstStageOrder($approvalFlowId)
    {
        $firstStep = ApprovalFlowDetail::where('approval_flow_id', $approvalFlowId)
            ->orderBy('order')
            ->first();

        return $firstStep ? (int) $firstStep->order : null;
    }

    /**
     * Load the steps that make up one stage of a flow.
     *
     * Ordered explicitly: alternatives are evaluated first-match-wins, so an
     * unordered fetch would let the database decide which branch a request
     * takes when more than one condition matches.
     *
     * @param  string  $approvalFlowId
     * @param  int  $order
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private static function fetchStageSteps($approvalFlowId, $order)
    {
        return ApprovalFlowDetail::where('approval_flow_id', $approvalFlowId)
            ->where('order', $order)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Determine which next step to use based on conditions
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
                
                if (self::compareAmount($entityAmount, $step->condition, $step->condition_value)) {
                    return $step;
                }
            }
            
            // If no matching condition found, return the first step as fallback
            return $nextSteps->first();
        }
        
        return null;
    }
    
    /**
     * Get appropriate step name based on reference type
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
            
            // Revision bookkeeping runs for whichever step raised it. This used
            // to be wrapped in `if ($currentActivity->step - 1 == 1)`, i.e. only
            // when the reviser happened to sit at step 2, so in a
            // technician -> spv -> pm -> finance flow a revision from finance
            // recorded nothing at all and resubmission had no "before" to
            // compare against.
            if ($approvalStatus == 'REVISION') {
                $limit = self::maxRevisionsFor($currentActivity->approval_flow_id);

                if ($limit !== null) {
                    $revisionCount = ApprovalRevisionHistory::where('ref_id', $refId)
                        ->where('ref_type', $refType)
                        ->count();

                    if ($revisionCount >= $limit) {
                        DB::rollBack();
                        return [
                            'success' => false,
                            'message' => "This request has already been revised {$revisionCount} time(s), which is the limit for this approval flow"
                        ];
                    }
                }

                $previousData = self::fetchReleatedData($refId, $refType);

                // Snapshot taken before the submitter edits anything, so the
                // resubmission can tell whether a material field moved.
                ApprovalRevisionHistory::create([
                    'ref_id' => $refId,
                    'ref_type' => $refType,
                    'requested_activity_id' => $currentActivity->id,
                    'requested_step' => $currentActivity->step,
                    'requested_user_id' => $currentActivity->user_id,
                    'data' => [
                        'approval_activity_id' => $currentActivity->id,
                        'user_id' => auth()->id(),
                        'note' => $data['note'] ?? null,
                        'previous' => $previousData ? $previousData->toArray() : null,
                        'approval_flow_detail_id' => $currentActivity->approval_flow_detail_id,
                    ],
                ]);
            }
            
            // Get reference data and location information. The flow the setting
            // currently points at is deliberately discarded here: a running
            // approval resolves its steps against the version recorded on its
            // own activity rows, not against whatever is active right now.
            list(, $entityData, $expenseReportAmount, $defineLocation) = self::getReferenceData($refId, $refType);

            if ($defineLocation == null) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Location type is not valid'
                ];
            }
            
            $projectId = $defineLocation['projectId'];
            $regionId = $defineLocation['regionId'];
            $homeBaseId = $defineLocation['homeBaseId'] ?? null;
            $technicianUserId = $defineLocation['technicianUserId'] ?? null;

            // Update current activity status
            ApprovalActivity::find($currentActivity->id)->update([
                'status' => $approvalStatus,
                'note' => $data['note'],
                'processed_at' => now()
            ]);
            
            // Handle based on approval status
            $result = null;
            if ($approvalStatus == 'REJECTED') {
                $result = self::handleRejection($refId, $refType, $currentActivity, $data, $entityData);
            } else if ($approvalStatus == 'REVISION') {
                $result = self::handleRevision($refId, $refType, $currentActivity, $data);
            } else {
                // For APPROVED or other statuses
                $result = self::handleNextStep($refId, $refType, $currentActivity, $data, $entityData, $projectId, $regionId, $expenseReportAmount, $homeBaseId, $technicianUserId);
            }
            
            // Check if handler returned success
            if ($result['success'] == false) {
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
     * @return array Result of the rejection process
     */
    private static function handleRejection($refId, $refType, $currentActivity, $data, $entityData)
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
                'approval_flow_id' => $currentActivity->approval_flow_id,
                'approval_flow_detail_id' => null,
                'step' => $currentActivity->step + 1,
                'step_name' => "Approval Finished With Rejected By " . $currentActivity->user->name,
                'status' => 'END',
                'role_id' => $currentActivity->role_id,
                'user_id' => $currentActivity->user_id,
                'note' => $data['note'],
                'order' => $currentActivity->order + 1,
                'processed_at' => now()
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
     * @return array Result of the revision process
     */
    private static function handleRevision($refId, $refType, $currentActivity, $data)
    {
        try {
            // Always return to the first step (initiator) when revision is requested
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
            
            // Create new approval activity returning to step 0 (initiator)
            ApprovalActivity::create([
                'ref_id' => $refId,
                'ref_type' => $refType,
                'approval_flow_id' => $currentActivity->approval_flow_id,
                'approval_flow_detail_id' => null,
                'step' => 0,
                'step_name' => "Revision Requested",
                'status' => 'REVISION',
                'role_id' => $firstActivity->role_id,
                'user_id' => $firstActivity->user_id,
                'note' => "Revision requested: " . $data['note'],
                'processed_at' => now(),
                'order' => $currentActivity->order + 1
            ]);
            
            // Update entity status to indicate revision
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
                
                // Re-evaluate the condition with the historical entity amount
                if (self::compareAmount($entityAmount, $step->condition, $step->condition_value)) {
                    $selectedStep = $step;
                    break;
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
     * @param string $projectId Project ID
     * @param string $regionId Region ID
     * @param float $expenseReportAmount Amount for condition checking
     * @param string|null $homeBaseId Home base ID
     * @param string|null $technicianUserId Technician the reference belongs to
     * @return array Result of the next step process
     */
    private static function handleNextStep($refId, $refType, $currentActivity, $data, $entityData, $projectId, $regionId, $expenseReportAmount, $homeBaseId = null, $technicianUserId = null)
    {
        try {
            // A running approval stays on the flow version it started on. The
            // active setting may already point at a newer version, but adopting
            // it mid-chain would splice two definitions into one request.
            $runningFlow = $currentActivity->approval_flow_id;

            $currentStepOrder = self::resolveCurrentStepOrder($currentActivity);

            // A null order means the activity is the initiator row or a revision
            // bounce, so the request enters the flow again at the first stage --
            // unless it is a resubmission where nothing material changed, in
            // which case it goes straight back to the step that asked for it.
            if ($currentStepOrder === null) {
                $resubmission = self::resolveResubmissionTarget($refId, $refType, $runningFlow, $entityData);

                if ($resubmission['fast_return']) {
                    return self::applyFastReturn(
                        $refId,
                        $refType,
                        $currentActivity,
                        $data,
                        $resubmission,
                        $projectId,
                        $regionId,
                        $homeBaseId,
                        $technicianUserId
                    );
                }

                if ($resubmission['revision']) {
                    $resubmission['revision']->update([
                        'resolved_at' => now(),
                        'resolution' => 'RESTART',
                    ]);
                }

                $nextStepOrder = self::fetchFirstStageOrder($runningFlow);

                if ($nextStepOrder === null) {
                    return [
                        'success' => false,
                        'message' => 'Approval flow steps not found'
                    ];
                }
            } else {
                $nextStepOrder = $currentStepOrder + 1;
            }

            // Get next steps based on the order
            $nextSteps = self::fetchStageSteps($runningFlow, $nextStepOrder);

            // Check if we have next steps
            if ($nextSteps->isEmpty()) {
                // This is the last step, mark as completed
                ApprovalActivity::create([
                    'ref_id' => $refId,
                    'ref_type' => $refType,
                    'approval_flow_id' => $runningFlow,
                    'approval_flow_detail_id' => null,
                    'step' => $nextStepOrder,
                    'step_name' => "Approval Completed",
                    'status' => 'END',
                    'role_id' => $currentActivity->role_id,
                    'user_id' => $currentActivity->user_id,
                    'note' => "Approval completed with final note: " . $data['note'],
                    'processed_at' => now(),
                    'order' => $currentActivity->order + 1
                ]);
                
                // Update entity status
                $entityData->update(['status' => 'APPROVED']);
                
                return [
                    'success' => true,
                    'message' => 'Approval process completed successfully'
                ];
            }
            
            // Determine the next step based on conditions. Shared with initStep
            // so the first stage and every later stage branch the same way.
            $nextStep = self::determineNextStep($nextSteps, $refType, $expenseReportAmount);

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
                $regionId,
                $homeBaseId,
                $refType,
                $technicianUserId
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
                'approval_flow_id' => $runningFlow,
                'approval_flow_detail_id' => $nextStep->id,
                'step_snapshot' => self::buildStepSnapshot($nextStep),
                'step' => $nextStep->order,
                'step_name' => $nextStep->name,
                'status' => 'PENDING',
                'role_id' => $nextStep->role_id,
                'user_id' => $nextAssignmentUser->id,
                'note' => null,
                'order' => $currentActivity->order + 1,
                'processed_at' => now()
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
     * How many revisions a flow tolerates. Null means unlimited.
     *
     * @param  string  $approvalFlowId
     * @return int|null
     */
    private static function maxRevisionsFor($approvalFlowId)
    {
        $flow = ApprovalFlow::find($approvalFlowId);

        return $flow ? $flow->max_revisions : null;
    }

    /**
     * The revision this request still owes a correction for, if any.
     *
     * @param  string  $refId
     * @param  string  $refType
     * @return \Ibinet\Models\ApprovalRevisionHistory|null
     */
    private static function openRevisionFor($refId, $refType)
    {
        return ApprovalRevisionHistory::where('ref_id', $refId)
            ->where('ref_type', $refType)
            ->whereNull('resolved_at')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * The column carrying the money for a reference type. Always material:
     * approval_flow_details branch on it, so a new amount can imply a
     * different set of approvers.
     *
     * @param  string  $refType
     * @return string
     */
    private static function amountFieldFor($refType)
    {
        return $refType == self::REF_EXPENSE ? 'credit' : 'amount';
    }

    /**
     * Fields that force a full replay when the flow has not configured its own.
     * These are the ones an approver actually signed off on, so a change to any
     * of them means the earlier approvals no longer describe what is being paid.
     *
     * @param  string  $refType
     * @return array<int, string>
     */
    private static function defaultMaterialFields($refType)
    {
        if ($refType == self::REF_EXPENSE) {
            return ['expense_category_id', 'location_type', 'location_id'];
        }

        return [];
    }

    /**
     * Material fields for a running flow: the amount, plus whatever the flow
     * configured, falling back to the per-ref-type defaults.
     *
     * @param  string  $approvalFlowId
     * @param  string  $refType
     * @return array<int, string>
     */
    private static function materialFieldsFor($approvalFlowId, $refType)
    {
        $fields = [self::amountFieldFor($refType)];

        $flow = ApprovalFlow::find($approvalFlowId);
        $configured = $flow ? $flow->revision_material_fields : null;

        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }

        $fields = array_merge(
            $fields,
            is_array($configured) && !empty($configured)
                ? $configured
                : self::defaultMaterialFields($refType)
        );

        return array_values(array_unique(array_filter($fields)));
    }

    /**
     * Decode the revision payload.
     *
     * Two writers disagree on shape: this service stores a real array into a
     * json-cast column, while ier's fund-request controller json_encodes first,
     * which lands as a string inside the cast. Both have to be readable.
     *
     * @param  \Ibinet\Models\ApprovalRevisionHistory  $revision
     * @return array
     */
    private static function revisionPayload($revision)
    {
        $payload = $revision->data;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * The entity as it stood when the revision was raised.
     *
     * @param  \Ibinet\Models\ApprovalRevisionHistory  $revision
     * @return array
     */
    private static function revisionSnapshot($revision)
    {
        $payload = self::revisionPayload($revision);

        // The fund-request controller stores the entity at the top level rather
        // than under a 'previous' key.
        $snapshot = $payload['previous'] ?? $payload;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * Whether two stored values represent a real change.
     *
     * Money arrives as a digit string once the thousands separators are
     * stripped, so '1000' and '1000.00' are the same amount and must not read
     * as an edit.
     *
     * @param  mixed  $before
     * @param  mixed  $after
     * @return bool
     */
    private static function valuesDiffer($before, $after)
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return abs((float) $before - (float) $after) > 0.00001;
        }

        return (string) $before !== (string) $after;
    }

    /**
     * Decide how a resubmitted request re-enters the chain.
     *
     * Returning to the requester is only safe when nothing an approver relied
     * on has moved. The amount is always checked because steps branch on it --
     * a changed amount can select a different approver entirely, so replaying
     * the flow is a correctness requirement, not a preference.
     *
     * @param  string  $refId
     * @param  string  $refType
     * @param  string  $runningFlow
     * @param  object  $entityData
     * @return array
     */
    private static function resolveResubmissionTarget($refId, $refType, $runningFlow, $entityData)
    {
        $result = [
            'revision' => null,
            'fast_return' => false,
            'target_step' => null,
            'changed_fields' => [],
            'reason' => 'NO_OPEN_REVISION',
        ];

        $revision = self::openRevisionFor($refId, $refType);

        if (!$revision) {
            return $result;
        }

        $result['revision'] = $revision;
        $snapshot = self::revisionSnapshot($revision);

        if (empty($snapshot)) {
            // Nothing to compare against, so replay rather than guess.
            $result['reason'] = 'NO_SNAPSHOT';
            return $result;
        }

        foreach (self::materialFieldsFor($runningFlow, $refType) as $field) {
            if (!array_key_exists($field, $snapshot)) {
                continue;
            }

            if (self::valuesDiffer($snapshot[$field], $entityData->{$field} ?? null)) {
                $result['changed_fields'][] = $field;
            }
        }

        if (!empty($result['changed_fields'])) {
            $result['reason'] = 'MATERIAL_CHANGE';
            return $result;
        }

        $targetStep = self::resolveRevisionOriginStep($revision);

        if (!$targetStep) {
            $result['reason'] = 'ORIGIN_STEP_UNKNOWN';
            return $result;
        }

        // Splicing in a step from a different flow version would mix two
        // definitions into one request.
        if ($targetStep->approval_flow_id !== $runningFlow) {
            $result['reason'] = 'ORIGIN_STEP_FOREIGN';
            return $result;
        }

        $firstOrder = self::fetchFirstStageOrder($runningFlow);

        // A step may insist on seeing every resubmission. If one of the stages
        // being bypassed says so, the shortcut is off.
        if ($firstOrder !== null) {
            $optedOut = ApprovalFlowDetail::where('approval_flow_id', $runningFlow)
                ->where('order', '>=', $firstOrder)
                ->where('order', '<', $targetStep->order)
                ->where('allow_fast_return', false)
                ->exists();

            if ($optedOut) {
                $result['reason'] = 'STEP_REQUIRES_REAPPROVAL';
                return $result;
            }
        }

        $result['fast_return'] = true;
        $result['target_step'] = $targetStep;
        $result['reason'] = 'NO_MATERIAL_CHANGE';

        return $result;
    }

    /**
     * The flow step that raised a revision.
     *
     * @param  \Ibinet\Models\ApprovalRevisionHistory  $revision
     * @return \Ibinet\Models\ApprovalFlowDetail|null
     */
    private static function resolveRevisionOriginStep($revision)
    {
        $payload = self::revisionPayload($revision);
        $detailId = $payload['approval_flow_detail_id'] ?? null;

        if ($detailId) {
            $step = ApprovalFlowDetail::find($detailId);

            if ($step) {
                return $step;
            }
        }

        // Rows written before the requester columns existed, or by the
        // fund-request controller, only leave the activity to trace back from.
        if ($revision->requested_activity_id) {
            $originActivity = ApprovalActivity::find($revision->requested_activity_id);

            if ($originActivity && $originActivity->approval_flow_detail_id) {
                return ApprovalFlowDetail::find($originActivity->approval_flow_detail_id);
            }
        }

        return null;
    }

    /**
     * Send a resubmitted request straight back to the step that asked for it.
     *
     * @return array
     */
    private static function applyFastReturn($refId, $refType, $currentActivity, $data, $resubmission, $projectId, $regionId, $homeBaseId, $technicianUserId)
    {
        $targetStep = $resubmission['target_step'];
        $revision = $resubmission['revision'];
        $runningFlow = $currentActivity->approval_flow_id;
        $order = $currentActivity->order;

        // Mark every bypassed stage rather than jumping silently: the activity
        // chain is the audit trail, and a gap in it reads as if those approvals
        // never happened.
        $firstOrder = self::fetchFirstStageOrder($runningFlow);

        if ($firstOrder !== null) {
            for ($stepOrder = $firstOrder; $stepOrder < $targetStep->order; $stepOrder++) {
                $previous = ApprovalActivity::where('ref_id', $refId)
                    ->where('ref_type', $refType)
                    ->where('step', $stepOrder)
                    ->orderBy('order', 'desc')
                    ->first();

                if (!$previous) {
                    continue;
                }

                $order++;

                ApprovalActivity::create([
                    'ref_id' => $refId,
                    'ref_type' => $refType,
                    'approval_flow_id' => $runningFlow,
                    'approval_flow_detail_id' => $previous->approval_flow_detail_id,
                    'step_snapshot' => $previous->step_snapshot,
                    'step' => $stepOrder,
                    'step_name' => $previous->step_name,
                    'status' => 'SKIPPED',
                    'role_id' => $previous->role_id,
                    'user_id' => $previous->user_id,
                    'note' => 'Auto-skipped on resubmission: nothing material changed since this approval',
                    'processed_at' => now(),
                    'order' => $order,
                ]);
            }
        }

        // Prefer the person who asked for the change; they already hold the
        // context. Only fall back to normal assignment if they cannot act.
        $assignee = null;

        if ($revision && $revision->requested_user_id) {
            $assignee = User::where('id', $revision->requested_user_id)
                ->where('is_active', true)
                ->first();
        }

        if (!$assignee) {
            $assignee = self::fetchUserByCondition(
                $targetStep->status,
                $targetStep->role_id,
                $projectId,
                $regionId,
                $homeBaseId,
                $refType,
                $technicianUserId
            );
        }

        if (!$assignee) {
            return [
                'success' => false,
                'message' => "No user available to review the revision, Step Is: {$targetStep->name}"
            ];
        }

        $order++;

        ApprovalActivity::create([
            'ref_id' => $refId,
            'ref_type' => $refType,
            'approval_flow_id' => $runningFlow,
            'approval_flow_detail_id' => $targetStep->id,
            'step_snapshot' => self::buildStepSnapshot($targetStep),
            'step' => $targetStep->order,
            'step_name' => $targetStep->name,
            'status' => 'PENDING',
            'role_id' => $targetStep->role_id,
            'user_id' => $assignee->id,
            'note' => null,
            'processed_at' => now(),
            'order' => $order,
        ]);

        if ($revision) {
            $revision->update([
                'resolved_at' => now(),
                'resolution' => 'FAST_RETURN',
            ]);
        }

        return [
            'success' => true,
            'message' => "Revision returned directly to {$targetStep->name}, nothing material changed"
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
     * @param string $status Assignment condition of the step
     * @param string $roleId Role the step is resolved for
     * @param string|null $projectId
     * @param string|null $regionId
     * @param string|null $homeBaseId
     * @param string|null $refType Reference type, scopes the workload count
     * @param string|null $technicianUserId Technician the reference belongs to
     */
    private static function fetchUserByCondition($status ,$roleId, $projectId = null, $regionId = null, $homeBaseId = null, $refType = null, $technicianUserId = null)
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
            // NOTE: project() is the unfiltered user_projects membership, so a
            // PROJECT_MANAGER or HELPDESK row also counts as membership here.
            // The narrow User::financeProject() relation is the right filter for
            // a finance step, but nothing in the flow marks a step's role as
            // finance (roles carry is_project_manager and is_technician flags
            // only), and the type backfill typed plain project_id rows as
            // PROJECT_MANAGER. Narrowing without knowing the role is finance
            // could empty the eligible set and hard-stop the approval, so this
            // stays unfiltered until a finance role marker exists.
            $eligibleUsers = User::where('role_id', $roleId)
                ->whereHas('project', function($query) use ($projectId) {
                    $query->where('projects.id', $projectId);
                })
                ->where('is_active', true)
                ->get();
        } else if ($status == self::SAME_HOMEBASE){
            if ($homeBaseId) {
                $eligibleUsers = User::where('role_id', $roleId)
                    ->whereHas('homebase', function($query) use ($homeBaseId) {
                        $query->where('home_bases.id', $homeBaseId);
                    })
                    ->where('is_active', true)
                    ->get();
            }
        }

        if ($eligibleUsers->isEmpty()) {
            return null;
        }

        // If only one user, return that user
        if ($eligibleUsers->count() == 1) {
            return $eligibleUsers->first();
        }

        $currentPeriod = PeriodHelper::current();

        // An explicit distribution outranks the balancer: it is generated per
        // period on purpose so the same technician does not land on the same
        // finance user forever. It can only ever pick somebody already in the
        // eligible set, so a deactivated or unassigned finance user in a stale
        // map row falls through to the balancer instead of being routed to.
        if ($status == self::SAME_PROJECT && $projectId && $technicianUserId) {
            $mappedUserId = FinanceDistributionService::resolveFinanceFor(
                $currentPeriod,
                $projectId,
                $technicianUserId
            );

            if ($mappedUserId) {
                $mappedUser = $eligibleUsers->firstWhere('id', $mappedUserId);

                if ($mappedUser && $mappedUser->is_active) {
                    return $mappedUser;
                }
            }
        }

        // Load balance: find user with the least approval work still open.
        // Only PENDING rows count. Without that filter this is a lifetime total
        // of every activity the user ever touched, including the APPROVED and
        // REJECTED history and the step 0 initiator rows, which only ever grows
        // and so can never rebalance.
        $userWorkloads = $eligibleUsers->map(function ($user) use ($refType, $currentPeriod) {
            $workloadQuery = ApprovalActivity::where('user_id', $user->id)
                ->where('status', 'PENDING');

            // A fund request queue and an expense queue are different work, so
            // being busy with one must not starve the user out of the other.
            if ($refType) {
                $workloadQuery->where('ref_type', $refType);
            }

            $activeApprovals = $workloadQuery->count();

            return [
                'user' => $user,
                'workload' => $activeApprovals,
                // Equal workloads used to resolve to whatever order the database
                // handed back, which is the same person every time. Hash the id
                // with the period instead: still fully deterministic, so a
                // retried request resolves identically, but the winner of a tie
                // changes from one period to the next.
                'tiebreak' => md5($user->id . '|' . $currentPeriod)
            ];
        });

        // Sort by workload (ascending), then by the period tiebreak
        $selectedUser = $userWorkloads->sortBy(function ($entry) {
            return str_pad((string) $entry['workload'], 12, '0', STR_PAD_LEFT) . '|' . $entry['tiebreak'];
        })->first();

        return $selectedUser['user'];
    }

    /**
     * Define project and region by location
     */
    public static function defineProjectAndRegionByLocation($locationType, $locationId)
    {
        if($locationType == 'REGION'){
            $expenseReportLocation = ExpenseReportLocation::find($locationId);
        } else if($locationType == 'REMOTE'){
            $expenseReportLocation = ExpenseReportRemote::find($locationId);
        } else{
            return null;
        }

        if (!$expenseReportLocation) {
            return null;
        }

        $projectId = $expenseReportLocation->project_id;

        // An expense record no longer has to name a remote, and a region-based
        // record may not carry a region either, so fall back to the project's
        // region the same way a fund request resolves it.
        $regionId = $locationType == 'REMOTE'
            ? $expenseReportLocation->remote?->region_id
            : $expenseReportLocation->region_id;

        if (!$regionId && $projectId) {
            $regionId = Project::with('regions')->find($projectId)?->regions->first()?->id;
        }

        // Home base comes from the remote when there is one, otherwise from the
        // person the expense report is assigned to.
        $homeBaseId = $locationType == 'REMOTE'
            ? $expenseReportLocation->remote?->home_base_id
            : null;

        if (!$homeBaseId) {
            $homeBaseId = self::resolveHomeBaseByExpenseReport($expenseReportLocation->expense_report_id);
        }

        return [
            'projectId' => $projectId,
            'regionId' => $regionId,
            'homeBaseId' => $homeBaseId,
            // Who the expense report belongs to, used to look the reference up
            // in the finance distribution for the period.
            'technicianUserId' => self::resolveTechnicianByExpenseReport($expenseReportLocation->expense_report_id)
        ];
    }

    /**
     * Resolve the user an expense report is assigned to.
     *
     * @param  string|null  $expenseReportId
     * @return string|null
     */
    private static function resolveTechnicianByExpenseReport($expenseReportId)
    {
        if (!$expenseReportId) {
            return null;
        }

        return ExpenseReport::find($expenseReportId)?->assignment_to;
    }

    /**
     * Resolve the home base of the user an expense report is assigned to.
     *
     * @param  string|null  $expenseReportId
     * @return string|null
     */
    private static function resolveHomeBaseByExpenseReport($expenseReportId)
    {
        if (!$expenseReportId) {
            return null;
        }

        $expenseReport = ExpenseReport::with('assignmentTo.homebase')->find($expenseReportId);

        return $expenseReport?->assignmentTo?->homebase->first()?->id;
    }

    /**
     * Define project and region by fund request
     */
    public static function defineProjectAndRegionByFundRequest($expenseReportRequest)
    {
        $projectId = $expenseReportRequest->project_id;
        $project =  Project::with('regions')->find($projectId);

        // Get the first region ID from the project's regions collection
        $regionId = $project?->regions->first()?->id;

        return [
            'projectId' => $projectId,
            'regionId' => $regionId ?? null,
            'homeBaseId' => self::resolveHomeBaseByExpenseReport($expenseReportRequest->expense_report_id),
            'technicianUserId' => self::resolveTechnicianByExpenseReport($expenseReportRequest->expense_report_id)
        ];
    }
}
