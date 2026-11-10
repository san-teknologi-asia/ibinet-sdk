<?php

namespace Ibinet\Services;

use Ibinet\Models\TechnicianBorrow;
use Ibinet\Models\TechnicianBorrowRemote;
use Ibinet\Models\TechnicianBorrowApproval;
use Ibinet\Models\TechnicianBorrowContractChange;
use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\UserProject;
use Ibinet\Models\User;
use Ibinet\Helpers\TechnicianBorrowHelper;
use Ibinet\Helpers\NotificationHelper;
use DB;

class TechnicianBorrowService
{
    /**
     * Create new borrowing request
     *
     * @param array $data
     * @return array
     */
    public static function createBorrowRequest($data)
    {
        DB::beginTransaction();
        
        try {
            User::whereKey($data['technician_id'])->lockForUpdate()->firstOrFail();
            // Validate inputs
            $validation = self::validateBorrowRequest($data);
            if (!$validation['valid']) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }

            // Generate borrow code
            $borrowCode = TechnicianBorrowHelper::generateBorrowCode();

            // Create technician borrow record
            $borrow = TechnicianBorrow::create([
                'borrow_code' => $borrowCode,
                'borrower_pm_id' => $data['borrower_pm_id'],
                'lender_pm_id' => $data['lender_pm_id'],
                'technician_id' => $data['technician_id'],
                'borrower_project_id' => $data['borrower_project_id'],
                'lender_project_id' => $data['lender_project_id'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'purpose' => $data['purpose'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'PENDING_LENDER_APPROVAL',
                'created_by' => $data['created_by']
            ]);

            // Create borrow remotes
            if (isset($data['remotes']) && is_array($data['remotes'])) {
                foreach ($data['remotes'] as $remote) {
                    TechnicianBorrowRemote::create([
                        'technician_borrow_id' => $borrow->id,
                        'remote_id' => $remote['remote_id'] ?? null,
                        'work_type_id' => $remote['work_type_id'] ?? null,
                        'estimated_duration' => $remote['estimated_duration'] ?? null,
                        'scheduled_date' => $remote['scheduled_date'] ?? null,
                        'notes' => $remote['notes'] ?? null,
                        'status' => 'PENDING',
                        'is_removed' => false
                    ]);
                }
            }

            // Submitting by an authorized requester records the borrowing side's approval.
            TechnicianBorrowApproval::create([
                'technician_borrow_id' => $borrow->id, 'approval_type' => 'INITIAL',
                'approver_role' => 'BORROWER', 'approver_id' => $data['borrower_pm_id'],
                'status' => 'APPROVED', 'approved_at' => now(),
            ]);

            // Create approval records - only lender approval needed
            TechnicianBorrowApproval::create([
                'technician_borrow_id' => $borrow->id,
                'approval_type' => 'INITIAL',
                'approver_role' => 'LENDER',
                'approver_id' => $data['lender_pm_id'],
                'status' => 'PENDING'
            ]);

            DB::commit();

            $technicianName = $borrow->technician->name ?? 'a technician';
            self::notify(
                $borrow->lender_pm_id,
                'Technician borrow request needs your approval',
                "{$borrow->borrow_code}: request to borrow {$technicianName}.",
                $borrow->id
            );

            return [
                'success' => true,
                'message' => 'Borrowing request created successfully',
                'data' => $borrow
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Borrow request creation error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error creating borrow request: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Validate borrow request data
     *
     * @param array $data
     * @return array
     */
    private static function validateBorrowRequest($data)
    {
        $borrower = User::find($data['borrower_pm_id']);
        $lender = User::find($data['lender_pm_id']);
        $technician = User::find($data['technician_id']);
        if (!$borrower || !$borrower->is_active || $borrower->id !== auth()->id()
            || !$borrower->hasPermission('ibos.technician_borrow.create')) {
            return ['valid' => false, 'message' => 'The requester needs permission to borrow technicians'];
        }
        if (!$lender || !$lender->is_active || !$lender->hasPermission('ibos.technician_borrow.approve')) {
            return ['valid' => false, 'message' => 'The lending representative needs approval permission'];
        }
        $lenderProject = $data['lender_project_id'] ?? null;
        if (!$lenderProject || $lenderProject === $data['borrower_project_id'] || $borrower->id === $lender->id
            || !$borrower->project()->whereKey($data['borrower_project_id'])->exists()
            || !$lender->project()->whereKey($lenderProject)->exists()
            || !$technician || !$technician->is_active || !$technician->hasRoleKind('is_technician')
            || !$technician->project()->whereKey($lenderProject)->exists()) {
            return ['valid' => false, 'message' => 'Select representatives and a technician belonging to the two different projects'];
        }
        $dates = TechnicianBorrowHelper::validateDates($data['start_date'], $data['end_date']);
        if (!$dates['valid']) return $dates;
        if (!TechnicianBorrowHelper::canBorrow($technician->id, $data['start_date'], $data['end_date'])) {
            return ['valid' => false, 'message' => 'The technician already has an overlapping borrowing request'];
        }
        return ['valid' => true, 'message' => 'Validation passed'];
    }

    /**
     * Process approval or rejection
     *
     * @param string $borrow_id
     * @param string $approver_id
     * @param string $action ('APPROVED' or 'REJECTED')
     * @param string $note
     * @return array
     */
    public static function processApproval($borrow_id, $approver_id, $action, $note = null)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::with(['lenderApproval', 'borrowerApproval'])->lockForUpdate()->find($borrow_id);
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            $approver = User::find($approver_id);
            if (!in_array($action, ['APPROVED', 'REJECTED'], true) || $approver_id !== auth()->id()
                || !$approver?->hasPermission('ibos.technician_borrow.approve')
                || !$approver->project()->whereKey($borrow->lender_project_id)->exists()
                || $borrow->status !== 'PENDING_LENDER_APPROVAL') {
                DB::rollBack();
                return ['success' => false, 'message' => 'This borrowing approval is not assigned to you'];
            }

            // Find pending approval for this approver
            $approval = TechnicianBorrowApproval::where('technician_borrow_id', $borrow_id)
                ->where('approver_id', $approver_id)
                ->where('approval_type', 'INITIAL')
                ->where('status', 'PENDING')
                ->first();

            if (!$approval) {
                DB::rollBack();
                return ['success' => false, 'message' => 'No pending approval found for this user'];
            }

            // Update approval record
            $approval->update([
                'status' => $action,
                'note' => $note,
                'approved_at' => now()
            ]);

            // Handle rejection
            if ($action == 'REJECTED') {
                $borrow->update(['status' => 'REJECTED']);

                DB::commit();

                self::notify(
                    $borrow->borrower_pm_id,
                    'Borrow request rejected',
                    "{$borrow->borrow_code} was rejected by the lending project manager.",
                    $borrow->id
                );

                return [
                    'success' => true,
                    'message' => 'Borrow request rejected'
                ];
            }

            // Handle approval - only lender approval needed
            if ($action == 'APPROVED') {
                $added = self::assignTechnicianToProject($borrow->technician_id, $borrow->borrower_project_id);
                $borrow->update(['status' => 'APPROVED', 'project_membership_added' => $added]);

                DB::commit();

                self::notify(
                    [$borrow->borrower_pm_id, $borrow->technician_id],
                    'Borrow request approved',
                    "{$borrow->borrow_code} was approved. The borrowing project can now prepare monthly expense reports.",
                    $borrow->id
                );

                return [
                    'success' => true,
                    'message' => 'Borrow request approved. Monthly expense reports can be created by the borrowing project.',
                    'data' => $borrow
                ];
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Approval process error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error processing approval: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Assign technician to project temporarily
     *
     * @param string $technician_id
     * @param string $project_id
     * @return void
     */
    private static function assignTechnicianToProject($technician_id, $project_id)
    {
        $exists = UserProject::where('user_id', $technician_id)
            ->where('project_id', $project_id)
            ->exists();

        if (!$exists) {
            UserProject::create([
                'user_id' => $technician_id,
                'project_id' => $project_id,
                'type' => UserProject::TYPE_PROJECT_MANAGER,
            ]);
        }
        return !$exists;
    }

    /**
     * Remove technician from project
     *
     * @param string $technician_id
     * @param string $project_id
     * @return void
     */
    private static function removeTechnicianFromProject(TechnicianBorrow $borrow)
    {
        if (!$borrow->project_membership_added) return;
        $otherLoan = TechnicianBorrow::whereKeyNot($borrow->id)->where('technician_id', $borrow->technician_id)
            ->where('borrower_project_id', $borrow->borrower_project_id)->whereIn('status', ['APPROVED', 'IN_PROGRESS'])->exists();
        if (!$otherLoan) {
            UserProject::where('user_id', $borrow->technician_id)->where('project_id', $borrow->borrower_project_id)->delete();
        }
    }

    /**
     * Notify one or more users about a borrowing event.
     *
     * Always call this after the transaction commits: the state change has
     * already happened, so a push failure must not surface as a failed
     * approval or roll anything back.
     *
     * @param array $user_ids
     * @param string $title
     * @param string $body
     * @param string $borrow_id
     * @return void
     */
    private static function notify($user_ids, $title, $body, $borrow_id)
    {
        $recipients = array_unique(array_filter((array) $user_ids));

        foreach ($recipients as $userId) {
            try {
                NotificationHelper::send($title, $body, [
                    'user_id'              => $userId,
                    'technician_borrow_id' => $borrow_id,
                ]);
            } catch (\Exception $e) {
                \Log::error("Borrow notification failed for user {$userId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Request contract change (add/remove remote)
     *
     * @param string $borrow_id
     * @param string $change_type
     * @param array $change_data
     * @param string $requested_by
     * @return array
     */
    public static function requestContractChange($borrow_id, $change_type, $change_data, $requested_by)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::whereKey($borrow_id)->lockForUpdate()->first();
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            if (!in_array($borrow->status, ['APPROVED', 'IN_PROGRESS'])) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Contract can only be changed during active borrowing'];
            }

            // Create contract change record
            $contractChange = TechnicianBorrowContractChange::create([
                'technician_borrow_id' => $borrow_id,
                'change_type' => $change_type,
                'change_data' => $change_data,
                'requested_by' => $requested_by,
                'status' => 'PENDING_LENDER_APPROVAL'
            ]);

            // Create approval records for contract change - only lender approval needed
            TechnicianBorrowApproval::create([
                'technician_borrow_id' => $borrow_id,
                'contract_change_id' => $contractChange->id,
                'approval_type' => 'CONTRACT_CHANGE',
                'approver_role' => 'LENDER',
                'approver_id' => $borrow->lender_pm_id,
                'status' => 'PENDING'
            ]);

            DB::commit();

            self::notify(
                $borrow->lender_pm_id,
                'Borrow contract change needs your approval',
                "{$borrow->borrow_code}: " . str_replace('_', ' ', strtolower($change_type)) . ' requested.',
                $borrow->id
            );

            return [
                'success' => true,
                'message' => 'Contract change request created successfully',
                'data' => $contractChange
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Contract change request error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error requesting contract change: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Process contract change approval
     *
     * @param string $change_id
     * @param string $approver_id
     * @param string $action
     * @param string $note
     * @return array
     */
    public static function processContractChangeApproval($change_id, $approver_id, $action, $note = null)
    {
        DB::beginTransaction();
        
        try {
            $contractChange = TechnicianBorrowContractChange::with('approvals')->find($change_id);
            
            if (!$contractChange) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Contract change not found'];
            }

            // Find pending approval for this approver
            $approval = TechnicianBorrowApproval::where('contract_change_id', $change_id)
                ->where('approver_id', $approver_id)
                ->where('status', 'PENDING')
                ->first();

            if (!$approval) {
                DB::rollBack();
                return ['success' => false, 'message' => 'No pending approval found for this user'];
            }

            // Update approval
            $approval->update([
                'status' => $action,
                'note' => $note,
                'approved_at' => now()
            ]);

            // Handle rejection
            if ($action == 'REJECTED') {
                $contractChange->update([
                    'status' => 'REJECTED',
                    'rejection_reason' => $note
                ]);

                DB::commit();

                self::notify(
                    $contractChange->requested_by,
                    'Contract change rejected',
                    'Your borrow contract change request was rejected.',
                    $contractChange->technician_borrow_id
                );

                return ['success' => true, 'message' => 'Contract change rejected'];
            }

            // Handle approval - only lender approval needed
            if ($action == 'APPROVED') {
                // Apply changes
                $result = self::applyContractChanges($contractChange);
                
                if (!$result['success']) {
                    DB::rollBack();
                    return $result;
                }

                $contractChange->update(['status' => 'APPROVED']);

                DB::commit();

                $borrow = $contractChange->technicianBorrow;
                self::notify(
                    [$contractChange->requested_by, $borrow->technician_id ?? null],
                    'Contract change approved',
                    'A borrow contract change was approved and applied.',
                    $contractChange->technician_borrow_id
                );

                return [
                    'success' => true,
                    'message' => 'Contract change approved and applied'
                ];
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Contract change approval error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error processing contract change approval: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Apply approved contract changes
     *
     * @param TechnicianBorrowContractChange $contractChange
     * @return array
     */
    private static function applyContractChanges($contractChange)
    {
        try {
            $borrow = $contractChange->technicianBorrow;
            $changeData = $contractChange->change_data;

            switch ($contractChange->change_type) {
                case 'ADD_REMOTE':
                    return self::addRemoteToContract($borrow, $changeData);
                    
                case 'REMOVE_REMOTE':
                    return self::removeRemoteFromContract($borrow, $changeData);
                    
                case 'EXTEND_PERIOD':
                    return self::extendBorrowPeriod($borrow, $changeData);
                    
                case 'MODIFY_DETAILS':
                    return self::modifyBorrowDetails($borrow, $changeData);
                    
                default:
                    return ['success' => false, 'message' => 'Unknown change type'];
            }
            
        } catch (\Exception $e) {
            \Log::error("Apply contract changes error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error applying changes: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Add remote to borrow contract
     *
     * @param TechnicianBorrow $borrow
     * @param array $changeData
     * @return array
     */
    private static function addRemoteToContract($borrow, $changeData)
    {
        // Create borrow remote
        $borrowRemote = TechnicianBorrowRemote::create([
            'technician_borrow_id' => $borrow->id,
            'remote_id' => $changeData['remote_id'],
            'work_type_id' => $changeData['work_type_id'] ?? null,
            'estimated_duration' => $changeData['estimated_duration'] ?? null,
            'scheduled_date' => $changeData['scheduled_date'] ?? null,
            'notes' => $changeData['notes'] ?? null,
            'status' => 'PENDING',
            'is_removed' => false
        ]);

        // Add to expense report if exists
        if ($borrow->expense_report_id) {
            ExpenseReportRemote::create([
                'expense_report_id' => $borrow->expense_report_id,
                'remote_id' => $changeData['remote_id'] ?? null,
                'project_id' => $borrow->borrower_project_id,
                'work_type_id' => $changeData['work_type_id'] ?? null,
                'status' => 'ONGOING',
                'phase' => 1,
                'date' => $changeData['scheduled_date'] ?? now(),
            ]);
        }

        return ['success' => true, 'message' => 'Remote added to contract'];
    }

    /**
     * Remove remote from borrow contract
     *
     * @param TechnicianBorrow $borrow
     * @param array $changeData
     * @return array
     */
    private static function removeRemoteFromContract($borrow, $changeData)
    {
        // Cancel borrow remote
        $borrowRemote = TechnicianBorrowRemote::where('technician_borrow_id', $borrow->id)
            ->where('remote_id', $changeData['remote_id'])
            ->first();

        if ($borrowRemote) {
            $borrowRemote->update(['status' => 'CANCELLED']);
        }

        // Cancel corresponding expense report remote if exists
        if ($borrow->expense_report_id) {
            ExpenseReportRemote::where('expense_report_id', $borrow->expense_report_id)
                ->where('remote_id', $changeData['remote_id'])
                ->update(['status' => 'CANCELLED']);
        }

        return ['success' => true, 'message' => 'Remote removed from contract'];
    }

    /**
     * Extend borrow period
     *
     * @param TechnicianBorrow $borrow
     * @param array $changeData
     * @return array
     */
    private static function extendBorrowPeriod($borrow, $changeData)
    {
        $borrow->update([
            'end_date' => $changeData['new_end_date']
        ]);

        return ['success' => true, 'message' => 'Borrow period extended'];
    }

    /**
     * Modify borrow details
     *
     * @param TechnicianBorrow $borrow
     * @param array $changeData
     * @return array
     */
    private static function modifyBorrowDetails($borrow, $changeData)
    {
        $borrow->update($changeData);

        return ['success' => true, 'message' => 'Borrow details modified'];
    }

    /**
     * Complete borrowing
     *
     * @param string $borrow_id
     * @return array
     */
    public static function completeBorrowing($borrow_id, bool $scheduled = false)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::whereKey($borrow_id)->lockForUpdate()->first();
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            if (!in_array($borrow->status, ['APPROVED', 'IN_PROGRESS'], true)
                || (!$scheduled && (!in_array(auth()->id(), [$borrow->borrower_pm_id, $borrow->lender_pm_id], true)
                    || !auth()->user()->hasPermission('ibos.technician_borrow.complete')))) {
                DB::rollBack();
                return ['success' => false, 'message' => 'This borrowing cannot be completed by this user'];
            }

            // Update borrow status
            $borrow->update([
                'status' => 'COMPLETED',
                'actual_end_date' => now()
            ]);

            ExpenseReportLifecycleService::lockBorrowReports($borrow, $scheduled ? null : auth()->id());
            self::removeTechnicianFromProject($borrow);

            DB::commit();

            self::notify(
                [$borrow->borrower_pm_id, $borrow->lender_pm_id, $borrow->technician_id],
                'Borrowing completed',
                "{$borrow->borrow_code} has been completed.",
                $borrow->id
            );

            return [
                'success' => true,
                'message' => 'Borrowing completed successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Complete borrowing error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error completing borrowing: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Cancel borrowing
     *
     * @param string $borrow_id
     * @param string $reason
     * @return array
     */
    public static function cancelBorrowing($borrow_id, $reason = null)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::whereKey($borrow_id)->lockForUpdate()->first();
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            // Can only cancel if not yet started or in early stages
            if (!in_array(auth()->id(), [$borrow->borrower_pm_id, $borrow->lender_pm_id], true)
                || !auth()->user()->hasPermission('ibos.technician_borrow.cancel')) {
                DB::rollBack();
                return ['success' => false, 'message' => 'This borrowing cannot be cancelled by this user'];
            }
            if (in_array($borrow->status, ['COMPLETED', 'CANCELLED'])) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Cannot cancel completed or already cancelled borrowing'];
            }

            $borrow->update([
                'status' => 'CANCELLED',
                'notes' => ($borrow->notes ?? '') . "\nCancellation reason: " . ($reason ?? 'Not specified')
            ]);

            
            // Cancel all remotes
            TechnicianBorrowRemote::where('technician_borrow_id', $borrow_id)
            ->update(['status' => 'CANCELLED']);
            
            ExpenseReportLifecycleService::lockBorrowReports($borrow, auth()->id());
            self::removeTechnicianFromProject($borrow);

            DB::commit();

            self::notify(
                [$borrow->borrower_pm_id, $borrow->lender_pm_id, $borrow->technician_id],
                'Borrowing cancelled',
                "{$borrow->borrow_code} was cancelled: " . ($reason ?? 'no reason given') . '.',
                $borrow->id
            );

            return [
                'success' => true,
                'message' => 'Borrowing cancelled successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Cancel borrowing error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error cancelling borrowing: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Start borrowing (change status to IN_PROGRESS)
     *
     * @param string $borrow_id
     * @return array
     */
    public static function startBorrowing($borrow_id, bool $scheduled = false)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::whereKey($borrow_id)->lockForUpdate()->first();
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            if ((!$scheduled && (!in_array(auth()->id(), [$borrow->borrower_pm_id, $borrow->lender_pm_id], true)
                    || !auth()->user()->hasPermission('ibos.technician_borrow.start')))
                || today()->lt($borrow->start_date) || today()->gt($borrow->end_date)) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrowing can only start within its approved dates'];
            }
            if ($borrow->status != 'APPROVED') {
                DB::rollBack();
                return ['success' => false, 'message' => 'Can only start approved borrowing'];
            }

            $borrow->update(['status' => 'IN_PROGRESS']);

            DB::commit();

            self::notify(
                $borrow->technician_id,
                'Borrowing started',
                "{$borrow->borrow_code} is now active.",
                $borrow->id
            );

            return [
                'success' => true,
                'message' => 'Borrowing started successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Start borrowing error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error starting borrowing: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Mark a remote as completed
     *
     * @param string $remote_id
     * @return array
     */
    public static function completeRemote($remote_id)
    {
        DB::beginTransaction();
        
        try {
            $remote = TechnicianBorrowRemote::find($remote_id);
            
            if (!$remote) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Remote not found'];
            }

            if (!in_array($remote->status, ['PENDING', 'IN_PROGRESS'])) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Remote is already completed or cancelled'];
            }

            $remote->update(['status' => 'COMPLETED']);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Remote marked as completed'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Complete remote error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error completing remote: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Mark a remote as cancelled
     *
     * @param string $remote_id
     * @return array
     */
    public static function cancelRemote($remote_id)
    {
        DB::beginTransaction();
        
        try {
            $remote = TechnicianBorrowRemote::find($remote_id);
            
            if (!$remote) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Remote not found'];
            }

            if (!in_array($remote->status, ['PENDING', 'IN_PROGRESS'])) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Remote is already completed or cancelled'];
            }

            $remote->update(['status' => 'CANCELLED']);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Remote marked as cancelled'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Cancel remote error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error cancelling remote: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Start scheduled borrows (Cron Job)
     *
     * @return array
     */
    public static function startScheduledBorrows()
    {
        $count = 0;
        foreach (TechnicianBorrow::where('status', 'APPROVED')->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->get() as $borrow) {
            if (self::startBorrowing($borrow->id, true)['success']) $count++;
        }
        return ['count' => $count];
    }

    public static function completeScheduledBorrows()
    {
        $count = 0;
        foreach (TechnicianBorrow::whereIn('status', ['APPROVED', 'IN_PROGRESS'])->whereDate('end_date', '<', today())->get() as $borrow) {
            if (self::completeBorrowing($borrow->id, true)['success']) $count++;
        }
        return ['count' => $count];
    }
}
