<?php

namespace Ibinet\Services;

use Ibinet\Models\TechnicianBorrow;
use Ibinet\Models\TechnicianBorrowRemote;
use Ibinet\Models\TechnicianBorrowApproval;
use Ibinet\Models\TechnicianBorrowContractChange;
use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportRemote;
use Ibinet\Models\UserProject;
use Ibinet\Helpers\TechnicianBorrowHelper;
use Ibinet\Helpers\ExpenseReportHelper;
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
        // Check if both users are project managers
        if (!TechnicianBorrowHelper::isProjectManager($data['borrower_pm_id'])) {
            return ['valid' => false, 'message' => 'Borrower must be a project manager'];
        }

        if (!TechnicianBorrowHelper::isProjectManager($data['lender_pm_id'])) {
            return ['valid' => false, 'message' => 'Lender must be a project manager'];
        }

        // Check if borrower PM manages the borrower project
        if (!TechnicianBorrowHelper::managesProject($data['borrower_pm_id'], $data['borrower_project_id'])) {
            return ['valid' => false, 'message' => 'Borrower does not manage the borrower project'];
        }

        // Check if technician can be borrowed
        if (!TechnicianBorrowHelper::canBorrow($data['technician_id'])) {
            return ['valid' => false, 'message' => 'Technician is currently borrowed'];
        }

        // Validate dates
        $dateValidation = TechnicianBorrowHelper::validateDates($data['start_date'], $data['end_date']);
        if (!$dateValidation['valid']) {
            return $dateValidation;
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
            $borrow = TechnicianBorrow::with(['lenderApproval', 'borrowerApproval'])->find($borrow_id);
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
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
                // Create expense report
                $result = self::createExpenseReport($borrow);
                
                if (!$result['success']) {
                    DB::rollBack();
                    return $result;
                }

                $borrow->update([
                    'status' => 'APPROVED',
                    'expense_report_id' => $result['expense_report_id']
                ]);

                // Add technician to borrower's project temporarily
                self::assignTechnicianToProject($borrow->technician_id, $borrow->borrower_project_id);

                DB::commit();

                self::notify(
                    [$borrow->borrower_pm_id, $borrow->technician_id],
                    'Borrow request approved',
                    "{$borrow->borrow_code} was approved. An expense report has been opened.",
                    $borrow->id
                );

                return [
                    'success' => true,
                    'message' => 'Borrow request approved. Expense report created.',
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
     * Create expense report for approved borrowing
     *
     * @param TechnicianBorrow $borrow
     * @return array
     */
    private static function createExpenseReport($borrow)
    {
        try {
            $borrow->load(['technician', 'borrowerProject', 'remotes.remote', 'lenderApproval.approver']);

            // Borrowing a technician is free, so the report starts with no budget.
            // Funds are added afterwards through the normal fund request flow.
            $totalCost = 0;

            // Generate ER code
            $erCode = ExpenseReportHelper::generateERCode();

            // Create Expense Report
            $lenderInfo = $borrow->lenderApproval ? $borrow->lenderApproval->approver->name : 'External';

            $expenseReport = ExpenseReport::create([
                'code' => $erCode,
                'name' => "Technician Borrowing - {$borrow->technician->name} - {$borrow->borrow_code}",
                'amount' => $totalCost,
                'assignment_to' => $borrow->technician_id,
                'created_by' => $borrow->borrower_pm_id,
                'status' => 'ONGOING',
                'remark' => "Borrowed from {$lenderInfo} for {$borrow->borrowerProject->name}. Period: {$borrow->start_date->format('d M Y')} - {$borrow->end_date->format('d M Y')}"
            ]);

            // Create one expense record per borrowed remote. A borrow does not
            // have to name any remote, in which case the report simply carries
            // no expense records until the technician files transactions.
            // ONGOING is what the mobile dashboard lists as active work; the
            // schedule pick-up path relies on the same column default.
            foreach ($borrow->remotes as $borrowRemote) {
                ExpenseReportRemote::create([
                    'expense_report_id' => $expenseReport->id,
                    'remote_id' => $borrowRemote->remote_id,
                    'project_id' => $borrow->borrower_project_id,
                    'work_type_id' => $borrowRemote->work_type_id,
                    'status' => 'ONGOING',
                    'phase' => 1,
                    'date' => $borrowRemote->scheduled_date ?? $borrow->start_date,
                ]);
            }

            return [
                'success' => true,
                'expense_report_id' => $expenseReport->id,
                'expense_report_code' => $erCode
            ];
            
        } catch (\Exception $e) {
            \Log::error("ER creation error: {$e->getMessage()} on line {$e->getLine()}");
            return [
                'success' => false,
                'message' => "Error creating expense report: {$e->getMessage()}"
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
                'project_id' => $project_id
            ]);
        }
    }

    /**
     * Remove technician from project
     *
     * @param string $technician_id
     * @param string $project_id
     * @return void
     */
    private static function removeTechnicianFromProject($technician_id, $project_id)
    {
        UserProject::where('user_id', $technician_id)
            ->where('project_id', $project_id)
            ->delete();
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
            $borrow = TechnicianBorrow::find($borrow_id);
            
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
    public static function completeBorrowing($borrow_id)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::with('remotes')->find($borrow_id);
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            // Check if all remotes are completed or cancelled
            $pendingRemotes = $borrow->remotes->whereIn('status', ['PENDING', 'IN_PROGRESS']);
            
            if ($pendingRemotes->count() > 0) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Cannot complete borrowing while remotes are still pending or in progress'
                ];
            }

            // Update borrow status
            $borrow->update([
                'status' => 'COMPLETED',
                'actual_end_date' => now()
            ]);

            // Close expense report
            if ($borrow->expense_report_id) {
                ExpenseReport::find($borrow->expense_report_id)
                    ->update(['status' => 'DONE']);
            }

            // Remove technician from borrower's project
            self::removeTechnicianFromProject($borrow->technician_id, $borrow->borrower_project_id);

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
            $borrow = TechnicianBorrow::find($borrow_id);
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
            }

            // Can only cancel if not yet started or in early stages
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
            
            // Cancel expense report if exists
            if ($borrow->expense_report_id) {
                ExpenseReport::where('id', $borrow->expense_report_id)
                ->update(['status' => 'CANCELLED']);
            }

            // Remove technician from project if assigned
            self::removeTechnicianFromProject($borrow->technician_id, $borrow->borrower_project_id);

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
    public static function startBorrowing($borrow_id)
    {
        DB::beginTransaction();
        
        try {
            $borrow = TechnicianBorrow::find($borrow_id);
            
            if (!$borrow) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Borrow request not found'];
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
        $borrows = TechnicianBorrow::where('status', 'APPROVED')
            ->whereDate('start_date', '<=', now())
            ->get();

        foreach ($borrows as $borrow) {
            DB::beginTransaction();
            try {
                $borrow->update(['status' => 'IN_PROGRESS']);
                
                // Ensure technician is assigned
                self::assignTechnicianToProject($borrow->technician_id, $borrow->borrower_project_id);

                DB::commit();
                $count++;

                self::notify(
                    $borrow->technician_id,
                    'Borrowing started',
                    "{$borrow->borrow_code} is now active.",
                    $borrow->id
                );
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error("Error starting borrow {$borrow->borrow_code}: {$e->getMessage()}");
            }
        }

        return ['count' => $count];
    }

    /**
     * Complete scheduled borrows (Cron Job)
     *
     * @return array
     */
    public static function completeScheduledBorrows()
    {
        $count = 0;
        // Find borrows that are in progress and end date has passed
        $borrows = TechnicianBorrow::where('status', 'IN_PROGRESS')
            ->whereDate('end_date', '<', now())
            ->get();

        foreach ($borrows as $borrow) {
            DB::beginTransaction();
            try {
                // $borrow->update([
                //     'status' => 'COMPLETED',
                //     'actual_end_date' => $borrow->end_date // Assume completed on planned date
                // ]);
                
                // Remove technician from project
                self::removeTechnicianFromProject($borrow->technician_id, $borrow->borrower_project_id);
                
                DB::commit();
                $count++;
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error("Error completing borrow {$borrow->borrow_code}: {$e->getMessage()}");
            }
        }

        return ['count' => $count];
    }
}
