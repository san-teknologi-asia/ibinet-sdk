<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class RemoteFinance extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'remote_finances';

    protected static $logName = 'Remote Finances';
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
            ->setDescriptionForEvent(function (string $eventName) use ($logModule) {
                $taskLabel    = $this->task_status_label ?? ($this->status_task ?? '-');
                $invoiceLabel = $this->invoice_status_label ?? ($this->status_invoice ?? '-');
                $dateLabel    = $this->invoice_date_formatted ?? ($this->invoice_date ? (string) $this->invoice_date : '-');

                if ($eventName === 'updated') {
                    return sprintf(
                        '[%s] %s %s (id:%s) | Task Status: %s | Invoice Status: %s | Invoice Date: %s',
                        $logModule,
                        class_basename($this),
                        $eventName,
                        $this->getKey() ?? '-',
                        $taskLabel,
                        $invoiceLabel,
                        $dateLabel
                    );
                }

                return sprintf(
                    '[%s] %s %s (id:%s)',
                    $logModule,
                    class_basename($this),
                    $eventName,
                    $this->getKey() ?? '-'
                );
            });
    }

    public function tapActivity(Activity $activity, string $eventName)
    {
        // Set the subject to the related Ticket instead of TicketTimer
        $activity->subject_id = $this->expense_report_remote_id;
    }
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    public function requirements()
    {
        return $this->hasMany('Ibinet\Models\RemoteFinanceRequirement', 'remote_finance_id');
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
