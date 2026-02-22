<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class TechnicianBorrowApproval extends Model
{
    protected $table = 'technician_borrow_approvals';

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
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /**
     * Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }

    /**
     * Get the parent borrow
     */
    public function technicianBorrow()
    {
        return $this->belongsTo('Ibinet\Models\TechnicianBorrow', 'technician_borrow_id');
    }

    /**
     * Get the contract change (if applicable)
     */
    public function contractChange()
    {
        return $this->belongsTo('Ibinet\Models\TechnicianBorrowContractChange', 'contract_change_id');
    }

    /**
     * Get the approver
     */
    public function approver()
    {
        return $this->belongsTo('Ibinet\Models\User', 'approver_id');
    }
}
