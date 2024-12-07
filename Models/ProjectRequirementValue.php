<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

use Ramsey\Uuid\Uuid;

class ProjectRequirementValue extends Model
{
    public $incrementing = false;

    public $table = 'project_requirement_values';

    public $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public function created_by()
    {
        return $this->belongsTo('Ibinet\Models\User', 'created_by');
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }
}
