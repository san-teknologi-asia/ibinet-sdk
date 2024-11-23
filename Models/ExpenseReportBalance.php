<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Uuid;

class ExpenseReportBalance extends Model
{
    protected $table = 'expense_report_balances';

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

    protected $casts = [
        'is_verified_by_supervisor' => 'boolean',
    ];

    /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getTransferImageAttribute($value)
    {
        return env('AWS_BASE_URL').$value;
    }

    /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getTransferImageOtherAttribute($value)
    {
        if ($value) {
            return env('AWS_BASE_URL') . $value;
        }

        return null;
    }

    public function expenseReport()
    {
        return $this->belongsTo('IDC\Models\ExpenseReport', 'expense_report_id');
    }

    public function expenseCategory()
    {
        return $this->belongsTo('IDC\Models\ExpenseCategory', 'expense_category_id')->withTrashed();
    }

    /**
     *  Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();

            if($model->credit > 0){
                $model->is_verified_by_supervisor = false;
            }
        });
    }
}
