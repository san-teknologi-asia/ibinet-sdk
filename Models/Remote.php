<?php

namespace Ibinet\Models;

use Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use IDC\Models\ExpenseReportLocation;
use IDC\Models\RemoteActiveHistory;
use IDC\Traits\LogTrait;

class Remote extends Model
{
    use SoftDeletes, LogTrait;

    public $incrementing = false;

    protected static $logName = 'Remote';
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

    protected $appends = [
        'is_have_helpdesk',
        'is_have_admin'
    ];

    public function getIsHaveHelpdeskAttribute()
    {
        $er = ExpenseReportLocation::with(['remote'])
                ->whereNotNull(['phase', 'project_id'])
                ->orderBy('created_at')
                ->where('remote_id', $this->id)
                ->first();

        if($er){
            return true;
        } else{
            return false;
        }
    }

    public function getIsHaveAdminAttribute()
    {
        $er = ExpenseReportLocation::with(['remote'])
                ->whereNotNull(['phase', 'project_id'])
                ->orderBy('created_at')
                ->where('remote_id', $this->id)
                // ->where('is_process_helpdesk', true)
                ->first();

        if($er){
            return true;
        } else{
            return false;
        }
    }

    public function workUnit()
    {
        return $this->belongsTo('Ibinet\Models\WorkUnit');
    }

    public function remoteType()
    {
        return $this->belongsTo('Ibinet\Models\RemoteType');
    }

    public function territory()
    {
        return $this->belongsTo('Ibinet\Models\RemoteTerritory', 'remote_territory_id');
    }

    public function supervision()
    {
        return $this->belongsTo('Ibinet\Models\Supervision');
    }

    public function homeBase()
    {
        return $this->belongsTo('Ibinet\Models\HomeBase');
    }

    public function pic()
    {
        return $this->hasMany('Ibinet\Models\RemotePic');
    }

    public function link()
    {
        return $this->belongsTo('Ibinet\Models\Link');
    }

    public function province()
    {
        return $this->belongsTo('Ibinet\Models\Province', 'province_code');
    }

    public function city()
    {
        return $this->belongsTo('Ibinet\Models\City', 'city_code');
    }

    public function district()
    {
        return $this->belongsTo('Ibinet\Models\District', 'distric_code');
    }

    public function village()
    {
        return $this->belongsTo('Ibinet\Models\Village', 'village_code');
    }

    public function serials()
    {
        return $this->hasMany('Ibinet\Models\RemoteSerial');
    }

    public function modem_type()
    {
        return $this->belongsTo('Ibinet\Models\HardwareVariety', 'modem_type_id');
    }

    public function modemType()
    {
        return $this->belongsTo('Ibinet\Models\HardwareVariety', 'modem_type_id');
    }

    public function mounting()
    {
        return $this->belongsTo('Ibinet\Models\Mounting', 'mounting');
    }

    public function satelite()
    {
        return $this->belongsTo('Ibinet\Models\Satelite', 'satelite_id');
    }

    public function project()
    {
        return $this->belongsToMany('Ibinet\Models\Project', 'project_remotes');
    }

    public function tickets()
    {
        return $this->belongsToMany('Ibinet\Models\Ticket', 'ticket_remotes');
    }

    public function zone()
    {
        return $this->belongsTo('Ibinet\Models\Zone', 'zone_id');
    }

    public function latesTicket()
    {
        return $this->belongsToMany('Ibinet\Models\Ticket', 'ticket_remotes')
            ->orderBy('created_at', 'desc')
            ->limit(1);
    }

    public function latestExpenseReportLocation()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReportLocation', 'id', 'remote_id')
            ->orderBy('created_at', 'desc');
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

        self::created(function ($model) {
            RemoteActiveHistory::create([
                'remote_id' => $model->id,
                'date' => now(),
                'is_active' => $model->is_active,
            ]);
        });

        self::updated(function ($model) {
            RemoteActiveHistory::create([
                'remote_id' => $model->id,
                'date' => now(),
                'is_active' => $model->is_active,
            ]);
        });
    }
}
