<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Ramsey\Uuid\Uuid;

class Project extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    public $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at',
        'updated_at'
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

    public function client()
    {
        return $this->belongsTo('Ibinet\Models\Client');
    }

    public function workType()
    {
        return $this->hasMany('Ibinet\Models\ProjectWorkType');
    }

    public function remote()
    {
        return $this->hasMany('Ibinet\Models\ProjectRemote');
    }

    /**
     * The remotes that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function remotes(): BelongsToMany
    {
        return $this->belongsToMany(Remote::class, 'project_remotes', 'project_id', 'remote_id');
    }

    /**
     * The regions that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'project_regions', 'project_id', 'region_id');
    }

    /**
     * The user that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany('Ibinet\Models\User', 'user_projects', 'project_id', 'user_id');
    }

    /**
     * The requirements that belong to the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function requirements()
    {
        return $this->belongsToMany('Ibinet\Models\Requirement', 'project_requirements');
    }
}
