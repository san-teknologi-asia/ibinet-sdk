<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class FinanceDistribution extends Model
{
    public const METHOD_ROTATE = 'ROTATE';
    public const METHOD_RANDOM = 'RANDOM';
    public const METHOD_MANUAL = 'MANUAL';

    protected $table = 'finance_distributions';

    public $incrementing = false;

    public $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    public function project()
    {
        return $this->belongsTo('Ibinet\Models\Project', 'project_id');
    }

    public function technician()
    {
        return $this->belongsTo('Ibinet\Models\User', 'technician_user_id');
    }

    public function finance()
    {
        return $this->belongsTo('Ibinet\Models\User', 'finance_user_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'generated_by');
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
