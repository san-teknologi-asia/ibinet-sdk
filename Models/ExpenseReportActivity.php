<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Uuid;

class ExpenseReportActivity extends Model
{
    protected $table = 'expense_report_activities';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    /**
     * Expense Report Activity belongs to Expense Report
     */
    public function expenseReport()
    {
        return $this->belongsTo(ExpenseReport::class);
    }

    /**
     * Expense Report Activity belongs to Expense Report Location
     */
    public function expenseReportLocation()
    {
        return $this->belongsTo(ExpenseReportLocation::class);
    }

    /**
     * User relation data
     */
    public function user()
    {
        return $this->belongsTo(User::class);    
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
