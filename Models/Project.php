<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Project extends Model
{
    use SoftDeletes, LogsActivity;

    public $incrementing = false;

    protected static $logName = 'Project';
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
        'created_at',
        'updated_at'
    ];

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

    public function client()
    {
        return $this->belongsTo('Ibinet\Models\Client');
    }

    public function workType()
    {
        return $this->hasMany('Ibinet\Models\ProjectWorkType');
    }

    public function remote()
    {
        return $this->hasMany('Ibinet\Models\ProjectRemote');
    }

    /**
     * The remotes that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function remotes(): BelongsToMany
    {
        return $this->belongsToMany(Remote::class, 'project_remotes', 'project_id', 'remote_id');
    }

    /**
     * The regions that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'project_regions', 'project_id', 'region_id');
    }

    /**
     * The user that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany('Ibinet\Models\User', 'user_projects', 'project_id', 'user_id');
    }

    /**
     * The requirements that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function requirements()
    {
        return $this->belongsToMany('Ibinet\Models\Requirement', 'project_requirements');
    }

    /**
     * Get expense report remotes for this project
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function expenseReportRemotes()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportRemote', 'project_id');
    }

    /**
     * Get remote finances through expense report remotes
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function remoteFinances()
    {
        return $this->hasManyThrough(
            'Ibinet\Models\RemoteFinance',
            'Ibinet\Models\ExpenseReportRemote',
            'project_id', // Foreign key on expense_report_remotes table
            'expense_report_remote_id', // Foreign key on remote_finances table
            'id', // Local key on projects table
            'id' // Local key on expense_report_remotes table
        );
    }
}
