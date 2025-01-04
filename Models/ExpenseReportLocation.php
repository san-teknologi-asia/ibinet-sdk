<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ibinet\Models\Remote;
use Ramsey\Uuid\Uuid;

class ExpenseReportLocation extends Model
{
    protected $table = 'expense_report_locations';

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

    protected $appends = ['info'];

    public function getInfoAttribute()
    {
        $text .= "<b>Region : </b>" . $this->region->name ?? "-" . "<br/>";
        $text .= "<b>Project : </b>" . $this->project->name ?? "-";

        return $text;
    }


    public function region()
    {
        return $this->belongsTo('Ibinet\Models\Region');
    }

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project');
    }

    public function expenseReport()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport');
    }

    public function adminProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'admin_process_by');
    }

    public function helpdeskProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'helpdesk_process_by');
    }

    public function financeProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'finance_process_by');
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
