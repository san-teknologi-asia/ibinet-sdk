<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class TechnicianBorrowContractChange extends Model
{
    protected $table = 'technician_borrow_contract_changes';

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
        'change_data' => 'array',
        'lender_approved_at' => 'datetime',
        'borrower_approved_at' => 'datetime',
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
     * Get the user who requested the change
     */
    public function requestedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'requested_by');
    }

    /**
     * Get the lender who approved
     */
    public function lenderApprovedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'lender_approved_by');
    }

    /**
     * Get the borrower who approved
     */
    public function borrowerApprovedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'borrower_approved_by');
    }

    /**
     * Get the approvals for this change
     */
    public function approvals()
    {
        return $this->hasMany('Ibinet\Models\TechnicianBorrowApproval', 'contract_change_id');
    }
}
