<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectWorkType extends Model
{
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public function workType()
    {
        return $this->belongsTo('Ibinet\Models\WorkType');
    }
}
