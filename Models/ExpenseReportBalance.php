<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Ibinet\Models\ExpenseReportLocation;
use Ibinet\Models\ExpenseReportRemote;

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
        return $this->belongsTo('Ibinet\Models\ExpenseReport', 'expense_report_id');
    }

    public function expenseCategory()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseCategory', 'expense_category_id')->withTrashed();
    }

    public function createdBy() 
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }

    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote', 'location_id', 'id');
    }

    public function region()
    {
        return $this->belongsTo('Ibinet\Models\Region', 'location_id', 'id');
    }

    public function getLocationAttribute()
    {
        if ($this->location_type === 'REGION') {
            $location = ExpenseReportLocation::where('id', $this->location_id)->first();
            return $location ? "({$location->region->name}) {$location->project->name}" : null;
        }
    
        if ($this->location_type === 'REMOTE') {
            $location = ExpenseReportRemote::where('id', $this->location_id)->first();
            return $location ? "({$location->remote->name}) {$location->project->name}" : null;
        }
    
        return null;
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
