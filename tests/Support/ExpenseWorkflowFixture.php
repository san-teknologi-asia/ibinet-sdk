<?php

namespace Ibinet\Tests\Support;

use Ibinet\Models\ApprovalActivity;
use Ibinet\Models\ApprovalFlowDetail;
use Ibinet\Models\ExpenseReport;
use Ibinet\Models\ExpenseReportBalance;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRequest;
use Ibinet\Models\User;
use Ibinet\Services\ApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Synthetic data only: never migrate, truncate or write to the configured database. */
trait ExpenseWorkflowFixture
{
    protected array $people;
    protected ExpenseReport $report;
    protected ExpenseReportLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'workflow_test', 'database.connections.workflow_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'activitylog.enabled' => false]);
        Cache::flush();

        $tables = [
            'roles' => ['name', 'parent_id', 'is_technician', 'is_project_manager'],
            'users' => ['name', 'email', 'password', 'role_id', 'is_active', 'deleted_at'],
            'role_permissions' => ['role_id', 'permission_id'],
            'user_projects' => ['user_id', 'project_id', 'type'],
            'user_regions' => ['user_id', 'region_id'],
            'user_homebases' => ['user_id', 'home_base_id'],
            'home_bases' => ['name', 'deleted_at'],
            'regions' => ['name', 'deleted_at'],
            'projects' => ['name', 'is_active', 'start_date', 'end_date', 'deleted_at'],
            'project_regions' => ['project_id', 'region_id'],
            'settings' => ['code', 'value'],
            'approval_flows' => ['name', 'family_id', 'version', 'is_latest', 'max_revisions', 'revision_material_fields', 'deleted_at'],
            'approval_flow_details' => ['approval_flow_id', 'name', 'role_id', 'status', 'condition_id', 'condition', 'condition_value', 'order', 'allow_fast_return', 'is_project_finance'],
            'approval_activities' => ['ref_id', 'ref_type', 'approval_flow_id', 'approval_flow_detail_id', 'step_snapshot', 'step', 'step_name', 'status', 'role_id', 'user_id', 'note', 'processed_at', 'order'],
            'approval_revision_histories' => ['ref_id', 'ref_type', 'requested_activity_id', 'requested_step', 'requested_user_id', 'data', 'resolved_at', 'resolution'],
            'expense_reports' => ['code', 'name', 'amount', 'assignment_to', 'created_by', 'remark', 'period', 'status', 'is_locked', 'locked_at', 'locked_by', 'finish_date', 'is_verified_by_finance', 'finance_verified_by', 'transfer_image', 'project_id', 'technician_borrow_id'],
            'expense_report_locations' => ['expense_report_id', 'region_id', 'project_id'],
            'expense_report_remotes' => ['expense_report_id', 'project_id', 'remote_id', 'schedule_id'],
            'expense_report_balances' => ['expense_report_id', 'code', 'credit', 'debit', 'expense_category_id', 'location_id', 'location_type', 'created_by', 'status', 'remark', 'transfer_image', 'transfer_image_other', 'is_verified_by_supervisor', 'upload_lat_long', 'upload_location'],
            'expense_report_requests' => ['expense_report_id', 'project_id', 'code', 'amount', 'status', 'remark', 'approve_by', 'reject_reason', 'transfer_image'],
            'expense_categories' => ['name', 'is_fund_transfer', 'deleted_at'],
            'expense_report_balance_images' => ['expense_report_balance_id', 'path', 'sort_order'],
            'notifications' => ['user_id', 'title', 'body', 'expense_report_id', 'transaction_id', 'approval_activity_id', 'technician_borrow_id', 'is_read'],
            'user_devices' => ['user_id', 'device_id'],
            'technician_borrows' => ['borrow_code', 'borrower_pm_id', 'lender_pm_id', 'technician_id', 'borrower_project_id', 'lender_project_id', 'expense_report_id', 'start_date', 'end_date', 'actual_end_date', 'status', 'purpose', 'notes', 'created_by', 'project_membership_added', 'deleted_at'],
            'technician_borrow_approvals' => ['technician_borrow_id', 'contract_change_id', 'approval_type', 'approver_role', 'approver_id', 'status', 'note', 'approved_at'],
            'technician_borrow_remotes' => ['technician_borrow_id', 'remote_id', 'work_type_id', 'estimated_duration', 'scheduled_date', 'notes', 'status', 'is_removed', 'deleted_at'],
            'technician_borrow_contract_changes' => ['technician_borrow_id', 'change_type', 'change_data', 'requested_by', 'status', 'rejection_reason', 'deleted_at'],
            'finance_distributions' => ['period', 'project_id', 'technician_user_id', 'finance_user_id', 'method', 'generated_by'],
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns, $name) {
                if ($name === 'user_projects') $table->increments('id');
                else $table->string('id')->primary();
                foreach ($columns as $column) {
                    if ($column === 'status' && in_array($name, ['expense_report_balances', 'expense_report_requests'])) {
                        $table->enum($column, ['WAITING CONFIRMATION', 'APPROVED', 'REJECTED']);
                    } elseif ($column === 'status' && $name === 'expense_reports') {
                        $table->enum($column, ['ONGOING', 'DONE', 'CLOSED'])->default('ONGOING');
                    } elseif (in_array($column, ['order', 'step', 'requested_step', 'version', 'max_revisions', 'sort_order'])) {
                        $table->integer($column)->nullable();
                    } elseif (str_starts_with($column, 'is_') || in_array($column, ['allow_fast_return', 'project_membership_added'])) {
                        $table->boolean($column)->default($column === 'allow_fast_return');
                    } elseif (in_array($column, ['credit', 'debit', 'amount'])) {
                        $table->decimal($column, 16, 2)->default(0);
                    } else {
                        $table->text($column)->nullable();
                    }
                }
                $table->timestamps();
            });
        }
        foreach (['tech', 'spv', 'pm', 'finance', 'other'] as $kind) {
            DB::table('roles')->insert(['id' => $kind, 'name' => $kind, 'is_technician' => $kind === 'tech', 'is_project_manager' => $kind === 'pm']);
            DB::table('users')->insert(['id' => $kind, 'name' => $kind, 'role_id' => $kind, 'is_active' => true]);
            $this->people[$kind] = User::findOrFail($kind);
            DB::table('user_projects')->insert(['user_id' => $kind, 'project_id' => 'project', 'type' => $kind === 'finance' ? 'FINANCE' : 'PROJECT_MANAGER']);
            $permissions = ['ibos.expense_report.index', 'ibos.expense_report.create', 'ibos.expense_report.finish', 'ibos.expense_report.transaction.create', 'ibos.expense_report.transaction.update', 'ibos.expense_report.transaction.index', 'ibos.expense_report.balance_request.create', 'ifas.fund-request.show', 'ifas.expense-transaction.approval.show'];
            if (in_array($kind, ['spv', 'pm'])) {
                foreach (['index', 'create', 'approve', 'start', 'complete', 'cancel'] as $action) $permissions[] = 'ibos.technician_borrow.'.$action;
            }
            if ($kind === 'finance') $permissions[] = 'ifas.expense-report.lock';
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert(['id' => $kind.$permission, 'role_id' => $kind, 'permission_id' => $permission]);
            }
        }
        DB::table('projects')->insert(['id' => 'project', 'name' => 'Test project', 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        DB::table('regions')->insert(['id' => 'region', 'name' => 'Test region']);
        DB::table('project_regions')->insert(['id' => 'pr', 'project_id' => 'project', 'region_id' => 'region']);
        DB::table('expense_categories')->insert(['id' => 'travel', 'name' => 'Travel']);
        DB::table('expense_categories')->insert(['id' => 'fund', 'name' => 'Fund transfer', 'is_fund_transfer' => true]);
        DB::table('approval_flows')->insert(['id' => 'flow', 'name' => 'Test flow', 'version' => 1]);
        foreach (['APPROVAL_EXPENSE_ER', 'APPROVAL_FUND_REQUEST'] as $key) {
            DB::table('settings')->insert(['id' => $key, 'code' => $key, 'value' => 'flow']);
        }
        $this->actingAs($this->people['spv']);
        $this->report = ExpenseReport::create(['code' => 'IER-TEST', 'name' => 'Monthly field expenses', 'amount' => 1000000, 'assignment_to' => 'tech', 'created_by' => 'spv', 'status' => 'ONGOING']);
        $this->location = ExpenseReportLocation::create(['expense_report_id' => $this->report->id, 'project_id' => 'project', 'region_id' => 'region']);
    }

    protected function step(int $order, string $role, ?string $operator = null, ?int $threshold = null): ApprovalFlowDetail
    {
        return ApprovalFlowDetail::create(['approval_flow_id' => 'flow', 'name' => $role, 'role_id' => $role, 'order' => $order, 'status' => $role === 'finance' ? 'NO-ROLE-CONDITION' : 'SAME-PROJECT', 'condition_id' => $operator ? 'ER_EXPENSE_AMOUNT' : null, 'condition' => $operator, 'condition_value' => $threshold, 'allow_fast_return' => true, 'is_project_finance' => $role === 'finance']);
    }

    protected function entity(string $type, int $amount = 500000)
    {
        return $type === 'EXPENSE'
            ? ExpenseReportBalance::create(['expense_report_id' => $this->report->id, 'code' => 'TR-TEST', 'credit' => $amount, 'expense_category_id' => 'travel', 'location_type' => 'REGION', 'location_id' => $this->location->id, 'created_by' => 'tech', 'status' => 'WAITING CONFIRMATION', 'remark' => 'Initial receipt', 'transfer_image' => '/test-receipt.jpg'])
            : ExpenseReportRequest::create(['expense_report_id' => $this->report->id, 'code' => 'FR-TEST', 'amount' => $amount, 'project_id' => 'project', 'status' => 'WAITING CONFIRMATION', 'remark' => 'Field budget']);
    }

    protected function start($entity, string $type): void
    {
        $user = $this->people[$type === 'EXPENSE' ? 'tech' : 'spv'];
        $this->actingAs($user);
        $result = ApprovalService::initStep($entity->id, $type, ['user_id' => $user->id, 'role_id' => $user->role_id, 'note' => 'Submitted']);
        $this->assertTrue($result['success'], $result['message']);
    }

    protected function process($entity, string $type, string $status = 'APPROVED'): array
    {
        $current = ApprovalService::fetchCurrentActivity($entity->id, $type);
        $this->actingAs(User::findOrFail($current->user_id));
        return ApprovalService::processApproval($entity->id, $type, ['status' => $status, 'note' => 'Test '.$status]);
    }
}
