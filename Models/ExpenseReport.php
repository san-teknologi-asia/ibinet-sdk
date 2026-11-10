<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Ibinet\Helpers\PeriodHelper;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ExpenseReport extends Model
{
    use LogsActivity;

    protected $table = 'expense_reports';

    protected static $logName = 'expense_reports';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    public $incrementing = false;

    protected $keyType = 'string';

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
        'updated_at'
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_verified_by_finance' => 'boolean',
        'locked_at' => 'datetime'
    ];

    public function assignmentTo()
    {
        return $this->belongsTo('Ibinet\Models\User', 'assignment_to')->withTrashed();
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function technicianBorrow()
    {
        return $this->belongsTo(TechnicianBorrow::class);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where(fn ($q) => $q->where('project_id', $projectId)
            ->orWhere(fn ($q) => $q->whereNull('project_id')->where(fn ($q) => $q
                ->whereHas('location', fn ($q) => $q->where('project_id', $projectId))
                ->orWhereHas('remote', fn ($q) => $q->where('project_id', $projectId))
                ->orWhereHas('balanceRequest', fn ($q) => $q->where('project_id', $projectId)))));
    }

    public function isClosedForEntry(): bool
    {
        if ($this->is_locked || $this->status !== 'ONGOING') return true;
        if (!$this->technician_borrow_id) return false;
        $borrow = $this->technicianBorrow;
        return !$borrow || !in_array($borrow->status, ['APPROVED', 'IN_PROGRESS'], true)
            || today()->lt($borrow->start_date) || today()->gt($borrow->end_date);
    }

    public function financeVerifiedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'finance_verified_by')->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by')->withTrashed();
    }

    public function location()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportLocation')->orderBy('created_at', 'desc');
    }

    public function balance()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportBalance', 'expense_report_id')->orderBy('created_at', 'desc');
    }

    public function balanceRequest()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportRequest', 'expense_report_id')->orderBy('created_at', 'desc');
    }

    public function remote()
    {
        return $this->hasMany('Ibinet\Models\ExpenseReportRemote', 'expense_report_id')->orderBy('created_at', 'desc');
    }

    public function lockedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'locked_by')->withTrashed();
    }

    /**
     *  Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();

            if (empty($model->period)) {
                // created_at is still null here unless it was set explicitly;
                // periodFor() falls back to now() in that case.
                $model->period = PeriodHelper::periodFor($model->created_at);
            }
        });
    }
}
