<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ProjectRequirementValue extends Model
{
    use LogsActivity;

    public $incrementing = false;

    public $table = 'project_requirement_values';

    protected static $logName = 'project_requirement_values';
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
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) use ($logModule) {
                $user = auth()->user();
                $userName = $user ? ($user->name ?? $user->email ?? 'Unknown') : 'System';
                
                return sprintf(
                    '[%s] %s %s (id:%s) by %s',
                    $logModule,
                    class_basename($this),
                    $eventName,
                    $this->getKey() ?? '-',
                    $userName,
                    $user ? $user->id : 'null'
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

    public function created_by()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }
}
