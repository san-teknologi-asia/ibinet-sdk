<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use IDC\Traits\LogTrait;
use Uuid;

class RemoteHelpdesk extends Model
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

    public function expense_report_location()
    {
        return $this->belongsTo('IDC\Models\ExpenseReportLocation', 'expense_report_id');
    }

    public function expenseReportLocation()
    {
        return $this->belongsTo('IDC\Models\ExpenseReportLocation', 'expense_report_id');
    }

    public function workUnit()
    {
        return $this->belongsTo('IDC\Models\WorkUnit');
    }

    public function remoteType()
    {
        return $this->belongsTo('IDC\Models\RemoteType');
    }

    public function territory()
    {
        return $this->belongsTo('IDC\Models\RemoteTerritory', 'remote_territory_id');
    }

    public function supervision()
    {
        return $this->belongsTo('IDC\Models\Supervision');
    }

    public function homeBase()
    {
        return $this->belongsTo('IDC\Models\HomeBase', 'home_base_id');
    }

    public function pic()
    {
        return $this->hasMany('IDC\Models\RemotePICHelpdesk');
    }

    public function link()
    {
        return $this->belongsTo('IDC\Models\Link');
    }

    public function province()
    {
        return $this->belongsTo('IDC\Models\Province', 'province_code');
    }

    public function city()
    {
        return $this->belongsTo('IDC\Models\City', 'city_code');
    }

    public function district()
    {
        return $this->belongsTo('IDC\Models\District', 'distric_code');
    }

    public function village()
    {
        return $this->belongsTo('IDC\Models\Village', 'village_code');
    }

    public function serials()
    {
        return $this->hasMany('IDC\Models\RemoteSerialHelpdesk', 'remote_helpdesk_id');
    }

    public function modem_type()
    {
        return $this->belongsTo('IDC\Models\HardwareVariety', 'modem_type_id');
    }

    public function mounting()
    {
        return $this->belongsTo('IDC\Models\Mounting', 'mounting');
    }

    public function satelite()
    {
        return $this->belongsTo('IDC\Models\Satelite', 'satelite_id');
    }

    public function created_by()
    {
        return $this->belongsTo('IDC\Models\User', 'created_by');
    }

    public function zone()
    {
        return $this->belongsTo('IDC\Models\Zone', 'zone_id');
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
