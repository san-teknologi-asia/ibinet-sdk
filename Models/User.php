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
