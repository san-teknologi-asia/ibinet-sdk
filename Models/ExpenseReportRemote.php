<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ExpenseReportRemote extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'expense_report_remotes';

    protected static $logName = 'expense_report_remotes';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    protected $keyType = 'string';

    public $incrementing = false;

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
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
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

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project', 'project_id');
    }

    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote', 'remote_id');
    }

    public function schedule()
    {
        return $this->belongsTo('Ibinet\Models\Schedule', 'schedule_id');
    }

    public function expenseReport()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport', 'expense_report_id');
    }

    public function workType()
    {
        return $this->belongsTo('Ibinet\Models\WorkType', 'work_type_id');
    }

    public function remoteFinance()
    {
        return $this->hasOne('Ibinet\Models\RemoteFinance', 'expense_report_remote_id');
    }

    public function remoteHelpdesk()
    {
        return $this->hasOne('Ibinet\Models\RemoteHelpdesk', 'expense_report_id');
    }
}
