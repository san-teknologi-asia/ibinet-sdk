<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectRequirement extends Model
{
    public $incrementing = false;

    public $table = 'project_requirements';

    public $keyType = 'string';

    public function requirements()
    {
        return $this->hasOne('IDC\Models\Requirement', 'id', 'requirement_id');
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
