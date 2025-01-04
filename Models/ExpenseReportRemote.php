<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class ExpenseReportRemote extends Model
{
    use HasFactory;
    
    protected $table = 'expense_report_remotes';

    protected $keyType = 'string';

    public $incrementing = false;

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
}
