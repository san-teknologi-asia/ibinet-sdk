<?php

namespace Ibinet\Helpers;

use Ibinet\Models\ExpenseReportActivity;

class ActivityHelper
{

    /**
     * Create activity
     * 
     * @param array $data
     * @return boolean
     */
    public static function createActivity($formData)
    {
        ExpenseReportActivity::create([
            'expense_report_id'          => $formData['expense_report_id'],
            'expense_report_location_id' => $formData['expense_report_location_id'] ?? null,
            'expense_report_remote_id'   => $formData['expense_report_remote_id'] ?? null,
            'user_id'                    => $formData['user_id'],
            'content'                    => $formData['content'],
            'type'                       => $formData['type'],
        ]);

        return true;
    }
}
