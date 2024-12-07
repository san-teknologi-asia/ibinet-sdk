<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Uuid;

class ExpenseReport extends Model
{
    protected $table = 'expense_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'updated_at'
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_verified_by_finance' => 'boolean'
    ];

    protected $appends = [
        'current'
    ];

    public function assignmentTo()
    {
        return $this->belongsTo('Ibinet\Models\User', 'assignment_to')->withTrashed();
    }

    public function financeVerifiedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'finance_verified_by')->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by')->withTrashed();
    }

    public function location()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportLocation')->orderBy('created_at', 'desc');
    }

    public function balance()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportBalance', 'expense_report_id')->orderBy('created_at', 'desc');
    }

    public function balanceRequest()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportRequest', 'expense_report_id')->orderBy('created_at', 'desc');
    }

    public function getCurrentAttribute()
    {
        return [
            'total_amount' => $this->getTotalAmountAttribute(),
            'usage_amount' => $this->getUsageAmountAttribute(),
            'remaining_amount' => $this->getRemainingAmountAttribute()
        ];
    }

    private function getTotalAmountAttribute()
    {
        return $this->balance->where('status', 'APPROVED')->sum('debit');
    }

    private function getUsageAmountAttribute()
    {
        return $this->balance->where('status', 'APPROVED')->sum('credit');
    }

    private function getRemainingAmountAttribute()
    {
        return $this->balance->where('status', 'APPROVED')->sum('debit') - $this->balance->where('status', 'APPROVED')->sum('credit');
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
