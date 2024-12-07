<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use IDC\Models\Remote;
use Uuid;

class ExpenseReportLocation extends Model
{
    protected $table = 'expense_report_locations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    protected $appends = ['info'];

    public function getInfoAttribute()
    {
        $data = Remote::find($this->remote_id);

        if($this->remote_id != null){
            $text = "<b>" . $data->name . "</b><br/>" .($data->remoteType->name ?? '-')."<br/>";
            $text .= "<b>IP LAN : </b>" . $data->ip_lan . "<br/>";
            $text .= "<b>IP P2P Modem : </b>" . $data->ip_p2p_modem . "<br/>";
            $text .= "<b>Site ID : </b>" . ($data->site_id ? $data->site_id : "-");
        } else {
            $text = $this->work_location;
        }

        return $text;
    }

    public function client()
    {
        return $this->belongsTo('Ibinet\Models\Client')->withTrashed();
    }

    public function district()
    {
        return $this->belongsTo('Ibinet\Models\District');
    }

    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote');
    }

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project');
    }

    public function ticket()
    {
        return $this->belongsTo('Ibinet\Models\Ticket');
    }

    public function expense_report()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport');
    }

    public function expenseReport()
    {
        return $this->belongsTo('Ibinet\Models\ExpenseReport');
    }

    public function workType()
    {
        return $this->belongsTo('Ibinet\Models\WorkType')->withTrashed();
    }

    public function remote_helpdesk()
    {
        return $this->hasOne('Ibinet\Models\RemoteHelpdesk', 'expense_report_id');
    }

    public function remoteHelpdesk()
    {
        return $this->hasOne('Ibinet\Models\RemoteHelpdesk', 'expense_report_id');
    }

    public function remoteFinance()
    {
        return $this->hasOne('Ibinet\Models\RemoteFinance', 'expense_report_location_id');
    }

    public function adminProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'admin_process_by');
    }

    public function helpdeskProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'helpdesk_process_by');
    }

    public function financeProcessBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'finance_process_by');
    }

    public function remoteType()
    {
        return $this->belongsTo('Ibinet\Models\RemoteType');
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
