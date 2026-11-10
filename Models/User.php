<?php

namespace Ibinet\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Ramsey\Uuid\Uuid;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;
    public $incrementing = false;

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

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public $appends = [
        'project_ids',
    ];

    public function getProjectIdsAttribute()
    {
        return $this->project->pluck('id')->toArray();
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getSignatureAttribute($value)
    {
        return env('AWS_BASE_URL') . $value;
    }

    public function role()
    {
        return $this->belongsTo('Ibinet\Models\Role');
    }

    /**
     * Whether this user's role is of a kind, e.g. hasRoleKind('is_technician').
     * See Role::KINDS. Never compare role_id against a fixed id instead.
     */
    public function hasRoleKind(string $kind): bool
    {
        if (!array_key_exists($kind, Role::KINDS)) {
            throw new \InvalidArgumentException("Unknown role kind [{$kind}].");
        }

        return (bool) optional($this->role)->{$kind};
    }

    /**
     * Users whose role is of a kind, e.g. User::withRoleKind('is_technician').
     */
    public function scopeWithRoleKind($query, string $kind)
    {
        return $query->whereHas('role', fn ($role) => $role->ofKind($kind));
    }

    /**
     * Whether this user's role holds a permission. has() answers the same for
     * the signed-in user; this is for anyone else, e.g. a report's author.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->role_id) {
            return false;
        }

        return RolePermission::where('role_id', $this->role_id)
            ->where('permission_id', $permission)
            ->exists();
    }

    /**
     * Users whose role holds a permission, e.g. who signs as finance.
     */
    public function scopeWithPermission($query, string $permission)
    {
        return $query->whereHas('role.permissions', fn ($grant) => $grant->where('permission_id', $permission));
    }

    public function region()
    {
        return $this->belongsToMany('Ibinet\Models\Region', 'user_regions');
    }

    public function homebase()
    {
        return $this->belongsToMany('Ibinet\Models\HomeBase', 'user_homebases');
    }

    public function zone()
    {
        return $this->belongsToMany('Ibinet\Models\Zone', 'user_zones');
    }

    public function project()
    {
        return $this->belongsToMany('Ibinet\Models\Project', 'user_projects');
    }

    /**
     * Projects this user is attached to as finance.
     *
     * Narrow counterpart to project(), which stays unfiltered because
     * UserHelper::getUserProjectArray() and the project_ids accessor need
     * every assignment regardless of pivot type.
     */
    public function financeProject()
    {
        return $this->belongsToMany('Ibinet\Models\Project', 'user_projects')
            ->wherePivot('type', UserProject::TYPE_FINANCE);
    }

    /**
     * The single node of the org chart this user sits in -- normally a leaf.
     * Department, division and every other level are read from its ancestry
     * rather than stored, so choosing the unit already settles the department.
     */
    public function organization()
    {
        return $this->belongsTo('Ibinet\Models\Organization', 'organization_id');
    }

    /**
     * The department this user rolls up into, derived from the org tree.
     * Null when the user has no organization assigned yet.
     *
     * @return \Ibinet\Models\Organization|null
     */
    public function getDepartmentAttribute()
    {
        return $this->organization
            ? $this->organization->nearestOfType(Organization::TYPE_DEPARTMENT)
            : null;
    }

    public function userProject()
    {
        return $this->hasMany('Ibinet\Models\UserProject');
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
