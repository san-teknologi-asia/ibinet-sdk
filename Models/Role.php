<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use DB;

class Role extends Model
{
    public $incrementing = false;

    public $table = 'roles';

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

    public function permissions()
    {
        return $this->hasMany('Ibinet\Models\RolePermission');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'parent_id');
    }

    public function childrenRoles()
    {
        $roles = DB::select("
            WITH RECURSIVE role_hierarchy AS (
                SELECT id, parent_id, name
                FROM roles
                WHERE id = ?

                UNION ALL

                SELECT r.id, r.parent_id, r.name
                FROM roles r
                INNER JOIN role_hierarchy rh ON r.parent_id = rh.id
            )
            SELECT * FROM role_hierarchy;
        ", [$this->id]);

        $roles = array_filter($roles, function($role) {
            return $role->id != $this->id;
        });
        return array_values($roles);
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
