<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Client extends Authenticatable
{
    use SoftDeletes, LogsActivity;

    protected $table = 'clients';

    public $incrementing = false;
    protected static $logName = 'Client';
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

     /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getLogoAttribute($value)
    {
        return env('AWS_BASE_URL').$value;
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
