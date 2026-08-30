<?php

namespace Ibinet\Services;

use DB;
use Ibinet\Models\FinanceDistribution;
use Ibinet\Models\UserProject;
use Ramsey\Uuid\Uuid;

/**
 * Builds the explicit technician -> finance map for a period.
 *
 * Without this map the assignee of an expense report is whatever the load
 * balancer in ApprovalService picks, and a balancer can only ever react to the
 * work already queued. Writing the pairing down up front lets the workload
 * rotate on purpose: the same technician deliberately lands on a different
 * finance user next period instead of on whoever happens to be least busy at
 * the moment the report is submitted.
 *
 * Shared SDK code: nothing from an App\ namespace may be referenced here, the
 * four consuming apps each have their own.
 */
class FinanceDistributionService
{
    /**
     * Largest number of rows sent to the database in a single upsert.
     */
    private const UPSERT_CHUNK = 500;

    /**
     * Build (or rebuild) the distribution for a period.
     *
     * @param  string       $period     Period code, 'YYYYMM'.
     * @param  string|null  $projectId  Limit to one project, or null for every
     *                                  project that has a finance roster.
     * @param  string       $method     ROTATE or RANDOM.
     * @param  string|null  $byUserId   Causer, null when a scheduler ran it.
     * @return array
     */
    public static function generateForPeriod(string $period, ?string $projectId = null, string $method = 'ROTATE', ?string $byUserId = null): array
    {
        $period = trim($period);

        if (!self::isValidPeriod($period)) {
            return [
                'success' => false,
                'message' => "Invalid period '{$period}', expected a YYYYMM code such as 202608",
                'period' => $period,
                'method' => $method,
                'projects' => [],
                'totals' => [
                    'projects' => 0,
                    'assigned' => 0,
                    'skipped' => 0,
                ],
            ];
        }

        $method = strtoupper(trim($method));

        if (!in_array($method, [FinanceDistribution::METHOD_ROTATE, FinanceDistribution::METHOD_RANDOM], true)) {
            return [
                'success' => false,
                'message' => "Unsupported distribution method '{$method}', expected ROTATE or RANDOM",
                'period' => $period,
                'method' => $method,
                'projects' => [],
                'totals' => [
                    'projects' => 0,
                    'assigned' => 0,
                    'skipped' => 0,
                ],
            ];
        }

        $projectIds = $projectId ? [$projectId] : self::fetchProjectIdsWithFinance();

        if (empty($projectIds)) {
            return [
                'success' => true,
                'message' => 'No project has a finance roster, nothing to distribute',
                'period' => $period,
                'method' => $method,
                'projects' => [],
                'totals' => [
                    'projects' => 0,
                    'assigned' => 0,
                    'skipped' => 0,
                ],
            ];
        }

        return DB::transaction(function () use ($period, $projectIds, $method, $byUserId) {
            $projectSummaries = [];
            $totalAssigned = 0;
            $totalSkipped = 0;

            foreach ($projectIds as $currentProjectId) {
                $financeUserIds = self::fetchFinanceUserIds($currentProjectId);
                $technicianUserIds = self::fetchTechnicianUserIds($currentProjectId);

                $summary = [
                    'project_id' => $currentProjectId,
                    'finance_count' => count($financeUserIds),
                    'technician_count' => count($technicianUserIds),
                    'assigned' => 0,
                    'skipped_reason' => null,
                    'pairs' => [],
                ];

                if (empty($financeUserIds)) {
                    $summary['skipped_reason'] = 'NO_FINANCE';
                    $projectSummaries[] = $summary;
                    $totalSkipped++;
                    continue;
                }

                if (empty($technicianUserIds)) {
                    $summary['skipped_reason'] = 'NO_TECHNICIAN';
                    $projectSummaries[] = $summary;
                    $totalSkipped++;
                    continue;
                }

                $pairs = self::deal($period, $method, $financeUserIds, $technicianUserIds);

                self::persist($period, $currentProjectId, $method, $byUserId, $pairs);

                $summary['assigned'] = count($pairs);
                $summary['pairs'] = $pairs;
                $projectSummaries[] = $summary;
                $totalAssigned += count($pairs);
            }

            return [
                'success' => true,
                'message' => "Distribution generated for period {$period}",
                'period' => $period,
                'method' => $method,
                'projects' => $projectSummaries,
                'totals' => [
                    'projects' => count($projectSummaries),
                    'assigned' => $totalAssigned,
                    'skipped' => $totalSkipped,
                ],
            ];
        });
    }

    /**
     * The finance user a technician's work belongs to for a period.
     *
     * @param  string  $period
     * @param  string  $projectId
     * @param  string  $technicianUserId
     * @return string|null
     */
    public static function resolveFinanceFor(string $period, string $projectId, string $technicianUserId): ?string
    {
        $financeUserId = FinanceDistribution::where('period', $period)
            ->where('project_id', $projectId)
            ->where('technician_user_id', $technicianUserId)
            ->value('finance_user_id');

        return $financeUserId ?: null;
    }

    /**
     * Pair every technician with a finance user.
     *
     * Both lists arrive sorted by id so a re-run of the generator reproduces the
     * same pairing. ROTATE then adds an offset derived from the period itself,
     * which is the whole point of the feature: a plain round robin over two
     * stably sorted lists hands the same technician to the same finance user
     * every single period, which is the behaviour being fixed.
     *
     * @param  string  $period
     * @param  string  $method
     * @param  array<int, string>  $financeUserIds
     * @param  array<int, string>  $technicianUserIds
     * @return array<int, array<string, string>>
     */
    private static function deal(string $period, string $method, array $financeUserIds, array $technicianUserIds): array
    {
        $financeCount = count($financeUserIds);

        if ($method === FinanceDistribution::METHOD_RANDOM) {
            // A genuine shuffle, deliberately not seeded: RANDOM is asked for
            // when the operator wants a fresh draw, not a reproducible one.
            shuffle($technicianUserIds);
            $offset = 0;
        } else {
            $offset = self::periodOffset($period) % $financeCount;
        }

        $pairs = [];

        foreach (array_values($technicianUserIds) as $index => $technicianUserId) {
            $pairs[] = [
                'technician_user_id' => $technicianUserId,
                'finance_user_id' => $financeUserIds[($index + $offset) % $financeCount],
            ];
        }

        return $pairs;
    }

    /**
     * Write the pairs down, replacing whatever the period already held.
     *
     * unique(period, project_id, technician_user_id) means a re-run updates the
     * finance user in place rather than stacking a second row a lookup could
     * pick either side of.
     *
     * @param  string  $period
     * @param  string  $projectId
     * @param  string  $method
     * @param  string|null  $byUserId
     * @param  array<int, array<string, string>>  $pairs
     * @return void
     */
    private static function persist(string $period, string $projectId, string $method, ?string $byUserId, array $pairs): void
    {
        if (empty($pairs)) {
            return;
        }

        $rows = [];

        foreach ($pairs as $pair) {
            $rows[] = [
                // Only used when the row is new; on a conflict the existing id
                // is kept because id is not in the update column list.
                'id' => (string) Uuid::uuid4(),
                'period' => $period,
                'project_id' => $projectId,
                'technician_user_id' => $pair['technician_user_id'],
                'finance_user_id' => $pair['finance_user_id'],
                'generated_by' => $byUserId,
                'method' => $method,
            ];
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            FinanceDistribution::upsert(
                $chunk,
                ['period', 'project_id', 'technician_user_id'],
                ['finance_user_id', 'generated_by', 'method']
            );
        }
    }

    /**
     * Every project that has at least one finance member.
     *
     * @return array<int, string>
     */
    private static function fetchProjectIdsWithFinance(): array
    {
        return DB::table('user_projects')
            ->where('type', UserProject::TYPE_FINANCE)
            ->whereNotNull('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->pluck('project_id')
            ->all();
    }

    /**
     * Finance roster of a project, active accounts only.
     *
     * A deactivated or deleted finance user must never enter the map: the map
     * is consulted ahead of the balancer, so a stale entry would park an
     * expense report on somebody who can no longer sign in.
     *
     * @param  string  $projectId
     * @return array<int, string>
     */
    private static function fetchFinanceUserIds(string $projectId): array
    {
        return DB::table('user_projects')
            ->join('users', 'users.id', '=', 'user_projects.user_id')
            ->where('user_projects.type', UserProject::TYPE_FINANCE)
            ->where('user_projects.project_id', $projectId)
            ->whereNull('users.deleted_at')
            ->where('users.is_active', true)
            ->distinct()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->all();
    }

    /**
     * Technicians who have expense reports on a project.
     *
     * expense_reports carries no project of its own, the project is on the
     * detail rows, and a report is detailed either by remote or by location
     * depending on who raised it. Both are checked so a technician is not
     * missed because their work was recorded through the other one.
     *
     * @param  string  $projectId
     * @return array<int, string>
     */
    private static function fetchTechnicianUserIds(string $projectId): array
    {
        return DB::table('expense_reports as er')
            ->join('users as u', 'u.id', '=', 'er.assignment_to')
            ->whereNull('u.deleted_at')
            ->where('u.is_active', true)
            ->where(function ($query) use ($projectId) {
                $query->whereExists(function ($sub) use ($projectId) {
                    $sub->select(DB::raw(1))
                        ->from('expense_report_remotes as err')
                        ->whereColumn('err.expense_report_id', 'er.id')
                        ->where('err.project_id', $projectId);
                })->orWhereExists(function ($sub) use ($projectId) {
                    $sub->select(DB::raw(1))
                        ->from('expense_report_locations as erl')
                        ->whereColumn('erl.expense_report_id', 'er.id')
                        ->where('erl.project_id', $projectId);
                });
            })
            ->distinct()
            ->orderBy('er.assignment_to')
            ->pluck('er.assignment_to')
            ->all();
    }

    /**
     * Month number of a period, counted from year 0.
     *
     * Consecutive periods differ by exactly one, which is what makes the ROTATE
     * offset move by one seat per period including across a year boundary.
     *
     * @param  string  $period
     * @return int
     */
    private static function periodOffset(string $period): int
    {
        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 4, 2);

        return ($year * 12) + ($month - 1);
    }

    /**
     * A period is six digits whose last two are a real month.
     *
     * @param  string  $period
     * @return bool
     */
    private static function isValidPeriod(string $period): bool
    {
        if (!preg_match('/^\d{6}$/', $period)) {
            return false;
        }

        $month = (int) substr($period, 4, 2);

        return $month >= 1 && $month <= 12;
    }
}
