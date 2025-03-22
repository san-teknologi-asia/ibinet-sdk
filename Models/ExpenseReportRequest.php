<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class ExpenseReportRequest extends Model
{
    protected $table = 'expense_report_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getTransferImageAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        return env('AWS_BASE_URL').$value;
    }

    public function expenseReport()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport', 'expense_report_id');
    }

    public function expenseReportProject()
    {
        return $this->belongsTo('Ibinet\Models\Project', 'project_id');
    }

    public function approveBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'approve_by')->withTrashed();
    }

    public function latestApprovalActivity()
    {
        return $this->hasOne(ApprovalActivity::class, 'ref_id', 'id')
            ->where('ref_type', 'FUND_REQUEST')
            ->orderBy('created_at', 'desc');
    }

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project');
    }

    /**
     *  Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }
}
