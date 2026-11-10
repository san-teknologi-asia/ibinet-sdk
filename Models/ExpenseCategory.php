<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $table = 'expense_categories';

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

    protected $casts = [
        'is_fund_transfer' => 'boolean',
    ];

    /**
     * Categories a technician can book an expense against: everything except
     * the fund-transfer category, which only IFAS uses when it pays out a
     * fund request.
     */
    public function scopeSpendable($query)
    {
        return $query->where('is_fund_transfer', false);
    }

    public function scopeFundTransfer($query)
    {
        return $query->where('is_fund_transfer', true);
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
