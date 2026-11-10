<?php

namespace Ibinet\Services;

use Ibinet\Models\ExpenseReport;
use Ibinet\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class FinanceExpenseReportService
{
    public static function projectsFor(User $user)
    {
        if (!$user->hasPermission('ifas.expense-report.lock')) throw new AuthorizationException;
        $projects = $user->financeProject()->where('is_active', true)->orderBy('name')->get();
        if ($projects->isEmpty()) throw new AuthorizationException('No finance projects are assigned to you.');
        return $projects;
    }

    public static function reportsFor(User $user)
    {
        $ids = self::projectsFor($user)->pluck('id');
        // A legacy report may span several projects. Collect its contexts once
        // and require every recorded project to be in this user's finance scope.
        $contexts = DB::table('expense_report_locations')->select('expense_report_id', 'project_id')->whereNotNull('project_id');
        foreach (['expense_report_remotes', 'expense_report_requests'] as $table) {
            $contexts->unionAll(DB::table($table)->select('expense_report_id', 'project_id')->whereNotNull('project_id'));
        }
        $placeholders = implode(',', array_fill(0, $ids->count(), '?'));
        $legacy = DB::query()->fromSub($contexts, 'report_projects')->select('expense_report_id')->groupBy('expense_report_id')
            ->havingRaw("SUM(CASE WHEN project_id IN ({$placeholders}) THEN 0 ELSE 1 END) = 0", $ids->all());
        return ExpenseReport::where(fn ($q) => $q->whereIn('project_id', $ids)
            ->orWhere(fn ($q) => $q->whereNull('project_id')->whereIn('id', $legacy)));
    }

    public static function lockThroughPeriod(User $user, string $period): int
    {
        if (!preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $period) || $period > today()->format('Ym')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['period' => 'Choose the current month or an earlier month.']);
        }
        return self::reportsFor($user)->where('status', 'ONGOING')->where('is_locked', false)
            ->where(fn ($q) => $q->where('period', '<=', $period)->orWhereNull('period'))
            ->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $user->id]);
    }
}
