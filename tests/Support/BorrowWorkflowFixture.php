<?php

namespace Ibinet\Tests\Support;

use Carbon\Carbon;
use Ibinet\Models\User;
use Ibinet\Services\TechnicianBorrowService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ExpenseWorkflowFixture.php';

trait BorrowWorkflowFixture
{
    use ExpenseWorkflowFixture { setUp as protected setUpExpenseFixture; }

    protected function setUp(): void
    {
        $this->setUpExpenseFixture();
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        DB::table('projects')->insert(['id' => 'project-b', 'name' => 'Original Project B', 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        DB::table('projects')->where('id', 'project')->update(['name' => 'Borrowing Project A']);
        DB::table('project_regions')->insert(['id' => 'pb-region', 'project_id' => 'project-b', 'region_id' => 'region']);
        DB::table('user_projects')->whereIn('user_id', ['pm', 'tech'])->update(['project_id' => 'project-b']);
        $this->people['finance-b'] = User::create(['name' => 'Project B Finance', 'role_id' => 'finance', 'is_active' => true]);
        DB::table('user_projects')->insert(['user_id' => $this->people['finance-b']->id, 'project_id' => 'project-b', 'type' => 'FINANCE']);
        $this->report->update(['project_id' => 'project-b', 'created_by' => 'pm', 'period' => '202611']);
        $this->location->update(['project_id' => 'project-b']);
    }

    protected function loanData(): array
    {
        return ['borrower_pm_id' => 'spv', 'lender_pm_id' => 'pm', 'technician_id' => 'tech', 'borrower_project_id' => 'project', 'lender_project_id' => 'project-b', 'start_date' => '2026-11-20', 'end_date' => '2026-12-29', 'created_by' => 'spv'];
    }

    protected function approvedLoan()
    {
        $this->actingAs($this->people['spv']);
        $result = TechnicianBorrowService::createBorrowRequest($this->loanData());
        $this->assertTrue($result['success'], $result['message']);
        $loan = $result['data'];
        $this->actingAs($this->people['pm']);
        $result = TechnicianBorrowService::processApproval($loan->id, 'pm', 'APPROVED');
        $this->assertTrue($result['success'], $result['message']);
        return $loan->fresh();
    }
}
