<?php

namespace Ibinet\Services;

use Ibinet\Models\ApprovalFlow;
use Ibinet\Models\ApprovalFlowDetail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FundRequestFlowSetupService
{
    /** Separate the legacy shared setting; leave explicitly configured fund flows alone. */
    public static function separateSharedFlow(): ?string
    {
        $flowId = DB::transaction(function () {
            $settings = DB::table('settings')->whereIn('code', ['APPROVAL_FUND_REQUEST', 'APPROVAL_EXPENSE_ER'])
                ->lockForUpdate()->pluck('value', 'code');
            $sharedId = $settings['APPROVAL_FUND_REQUEST'] ?? null;
            if (!$sharedId || $sharedId !== ($settings['APPROVAL_EXPENSE_ER'] ?? null)) {
                return null;
            }

            // Reuse the finance role already configured on the expense flow,
            // identified by its transfer-confirmation permission, never its name.
            $transferSteps = ApprovalFlowDetail::where('approval_flow_id', $sharedId)
                ->whereHas('role.permissions', fn ($q) => $q->where('permission_id', 'ifas.fund-request.show'))
                ->orderByDesc('order')->get();
            // Project managers may also hold the confirmation permission. The
            // existing final confirmation stage identifies the payout role.
            $roleIds = $transferSteps->where('order', $transferSteps->first()?->order)
                ->pluck('role_id')->unique()->values();
            if ($roleIds->count() !== 1) {
                throw new RuntimeException('Cannot identify one finance role in the shared approval flow. Configure a separate Fund Request approval flow in IDC before retrying.');
            }

            $flow = ApprovalFlow::create(['name' => 'Fund Request to Finance', 'version' => 1, 'is_latest' => true]);
            $stepData = [
                'approval_flow_id' => $flow->id, 'name' => 'Finance Transfer Confirmation',
                'role_id' => $roleIds->first(), 'status' => 'NO-ROLE-CONDITION', 'order' => 1,
                'condition_id' => 'ER_AMOUNT_FUND_REQUEST', 'condition' => '>=', 'condition_value' => '0',
                'allow_fast_return' => true,
            ];
            if (Schema::hasColumn('approval_flow_details', 'is_project_finance')) {
                $stepData['is_project_finance'] = true;
            }
            ApprovalFlowDetail::create($stepData);
            DB::table('settings')->where('code', 'APPROVAL_FUND_REQUEST')->update(['value' => $flow->id]);
            return $flow->id;
        });
        if ($flowId) {
            Cache::forget('setting');
        }
        return $flowId;
    }
}
