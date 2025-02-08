<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectRequirementRemote extends Model
{
    public $incrementing = false;

    public $table = 'project_requirement_remote';

    public $keyType = 'string';

    public function requirements()
    {
        return $this->hasOne('Ibinet\Models\RequirementRemote', 'id', 'requirement_remote_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];
}
