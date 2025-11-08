<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

class TechnicianBorrow extends Model
{
    use SoftDeletes;

    protected $table = 'technician_borrows';

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
        'start_date' => 'date',
        'end_date' => 'date',
        'actual_end_date' => 'date',
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
     * Get the borrower project manager
     */
    public function borrowerPM()
    {
        return $this->belongsTo('Ibinet\Models\User', 'borrower_pm_id');
    }

    /**
     * Get the lender project manager
     */
    public function lenderPM()
    {
        return $this->belongsTo('Ibinet\Models\User', 'lender_pm_id');
    }

    /**
     * Get the borrowed technician
     */
    public function technician()
    {
        return $this->belongsTo('Ibinet\Models\User', 'technician_id');
    }

    /**
     * Get the borrower project
     */
    public function borrowerProject()
    {
        return $this->belongsTo('Ibinet\Models\Project', 'borrower_project_id');
    }

    /**
     * Get the lender project
     */
    public function lenderProject()
    {
        return $this->belongsTo('Ibinet\Models\Project', 'lender_project_id');
    }

    /**
     * Get the expense report
     */
    public function expenseReport()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport', 'expense_report_id');
    }

    /**
     * Get the creator
     */
    public function createdBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }

    /**
     * Get the remotes for this borrow
     */
    public function remotes()
    {
        return $this->hasMany('Ibinet\Models\TechnicianBorrowRemote', 'technician_borrow_id');
    }

    /**
     * Get the approvals for this borrow
     */
    public function approvals()
    {
        return $this->hasMany('Ibinet\Models\TechnicianBorrowApproval', 'technician_borrow_id');
    }

    /**
     * Get the contract changes for this borrow
     */
    public function contractChanges()
    {
        return $this->hasMany('Ibinet\Models\TechnicianBorrowContractChange', 'technician_borrow_id');
    }

    /**
     * Get pending approvals
     */
    public function pendingApprovals()
    {
        return $this->hasMany('Ibinet\Models\TechnicianBorrowApproval', 'technician_borrow_id')
            ->where('status', 'PENDING');
    }

    /**
     * Get lender approval
     */
    public function lenderApproval()
    {
        return $this->hasOne('Ibinet\Models\TechnicianBorrowApproval', 'technician_borrow_id')
            ->where('approver_role', 'LENDER')
            ->where('approval_type', 'INITIAL');
    }

    /**
     * Get borrower approval
     */
    public function borrowerApproval()
    {
        return $this->hasOne('Ibinet\Models\TechnicianBorrowApproval', 'technician_borrow_id')
            ->where('approver_role', 'BORROWER')
            ->where('approval_type', 'INITIAL');
    }
}
