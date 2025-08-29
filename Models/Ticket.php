<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Ticket extends Model
{
    use SoftDeletes, LogsActivity;

    public $incrementing = false;
    protected static $logName = 'Ticket';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    public $keyType = 'string';

    // log activity
    public function getActivitylogOptions(): LogOptions
    {
        $logModule = config('activitylog.default_log_name', self::$logName);
        
        return LogOptions::defaults()
            ->useLogName(self::$logName)
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(function (string $eventName) use ($logModule) {
                return sprintf(
                    '[%s] %s %s (id:%s)',
                    $logModule,
                    class_basename($this),
                    $eventName,
                    $this->getKey() ?? '-'
                );
            });
    }
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
        return $this->belongsTo('Ibinet\Models\Remote', 'remote_id');
    }

    public function user()
    {
        return $this->belongsTo('Ibinet\Models\User');
    }

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project');
    }

    public function created_by()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }
    
    public function createdBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }

    public function expenseReportRemote()
    {
        return $this->hasOne('Ibinet\Models\ExpenseReportRemote', 'ticket_id');
    }

    public function timers()
    {
        return $this->hasMany('Ibinet\Models\TicketTimer', 'ticket_id');
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
