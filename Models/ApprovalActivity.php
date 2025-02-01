<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class ApprovalActivity extends Model
{
    use HasFactory;
    protected $table = 'approval_activities';

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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function expenseReportRequest()
    {
        return $this->belongsTo(ExpenseReportRequest::class, 'ref_id', 'id');
    }

    public function expenseReportBalance()
    {
        return $this->belongsTo(ExpenseReportBalance::class, 'ref_id', 'id');
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
