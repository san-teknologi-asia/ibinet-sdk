<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ProjectRemote extends Model
{
    use LogsActivity;

    public $incrementing = false;

    protected static $logName = 'ProjectRemote';
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
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote');
    }

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project');
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
