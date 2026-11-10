<?php

namespace Ibinet\Services;

use Ibinet\Models\ExpenseReport;
use Ibinet\Models\TechnicianBorrow;
use Illuminate\Validation\ValidationException;

class ExpenseReportLifecycleService
{
    public static function borrowForReport(string $technicianId, string $projectId, ?string $borrowId = null): ?TechnicianBorrow
    {
        if (!\Ibinet\Models\UserProject::where('user_id', $technicianId)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages(['assignment_to' => 'The technician is not currently assigned to this project.']);
        }
        $borrow = TechnicianBorrow::where('technician_id', $technicianId)->where('borrower_project_id', $projectId)
            ->whereIn('status', ['APPROVED', 'IN_PROGRESS'])->whereDate('end_date', '>=', today())
            ->when($borrowId, fn ($q) => $q->whereKey($borrowId))->orderByDesc('start_date')->first();
        if ($borrowId && !$borrow) {
            throw ValidationException::withMessages(['technician_borrow_id' => 'This borrowing is no longer available for expense entry.']);
        }
        if (!$borrow && TechnicianBorrow::where('technician_id', $technicianId)->where('borrower_project_id', $projectId)
            ->where('project_membership_added', true)->whereIn('status', ['APPROVED', 'IN_PROGRESS'])
            ->whereDate('end_date', '<', today())->exists()) {
            throw ValidationException::withMessages(['assignment_to' => 'This technician\'s borrowing period has ended.']);
        }
        return $borrow;
    }

    public static function closePreviousPeriods(ExpenseReport $report, ?string $actorId): int
    {
        return ExpenseReport::where('assignment_to', $report->assignment_to)->whereKeyNot($report->id)
            ->forProject($report->project_id)->where('technician_borrow_id', $report->technician_borrow_id)
            ->where('status', 'ONGOING')->where('is_locked', false)->where('period', '<', $report->period)
            ->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $actorId]);
    }

    public static function lockBorrowReports(TechnicianBorrow $borrow, ?string $actorId = null): int
    {
        return ExpenseReport::where(fn ($q) => $q->where('technician_borrow_id', $borrow->id)
            ->when($borrow->expense_report_id, fn ($q) => $q->orWhere('id', $borrow->expense_report_id)))
            ->where('is_locked', false)->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $actorId]);
    }
}
