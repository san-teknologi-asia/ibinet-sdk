<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class ExpenseReportBalanceImage extends Model
{
    protected $table = 'expense_report_balance_images';

    public $incrementing = false;

    public $keyType = 'string';

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
    public function getPathAttribute($value)
    {
        return $value ? env('AWS_BASE_URL') . $value : null;
    }

    public function balance()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReportBalance', 'expense_report_balance_id');
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
