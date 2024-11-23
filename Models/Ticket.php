<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use IDC\Traits\LogTrait;
use Uuid;

class Ticket extends Model
{
    use SoftDeletes, LogTrait;

    public $incrementing = false;
    protected static $logName = 'Ticket';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    public $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    public function remote()
    {
        return $this->hasOne('IDC\Models\TicketRemote');
    }

    public function user()
    {
        return $this->belongsTo('IDC\Models\User');
    }

    public function project()
    {
        return $this->belongsTo('IDC\Models\Project');
    }

    public function created_by()
    {
        return $this->belongsTo('IDC\Models\User', 'created_by');
    }
    
    public function createdBy()
    {
        return $this->belongsTo('IDC\Models\User', 'created_by');
    }

    public function expenseReportLocation()
    {
        return $this->hasOne('IDC\Models\ExpenseReportLocation', 'ticket_id');
    }

    public function timers()
    {
        return $this->hasMany('IDC\Models\TicketTimer', 'ticket_id');
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
