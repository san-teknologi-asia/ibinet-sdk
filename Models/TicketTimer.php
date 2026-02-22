<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

class TicketTimer extends Model
{
    use HasFactory, LogsActivity;

    public $incrementing = false;

    protected static $logName = 'TicketTimer';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::$logName)
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(function(string $eventName) {
                switch ($eventName) {
                    case 'created':
                        return "Timer started at " . ($this->start_time ? $this->start_time->format('Y-m-d H:i:s') : 'now');
                    case 'updated':
                        if ($this->end_time && $this->isDirty('end_time')) {
                            return "Timer stopped at " . $this->end_time->format('Y-m-d H:i:s');
                        }
                        if ($this->start_time && $this->isDirty('start_time')) {
                            return "Timer started again at " . $this->start_time->format('Y-m-d H:i:s');
                        }
                        return "Timer has been updated";
                    default:
                        return "This model has been {$eventName}";
                }
            });
    }

    public function tapActivity(Activity $activity, string $eventName)
    {
        // Set the subject to the related Ticket instead of TicketTimer
        $activity->subject_id = $this->ticket_id;
        $activity->causer_id = auth()->user()->id;
    }
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo('Ibinet\Models\Ticket');
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
