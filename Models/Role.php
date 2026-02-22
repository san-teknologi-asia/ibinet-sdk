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
        try {
            // Set a reasonable recursion depth limit
            DB::statement('SET SESSION cte_max_recursion_depth = 100');
            
            $roles = DB::select("
                WITH RECURSIVE role_hierarchy AS (
                    SELECT id, parent_id, name, 0 as depth
                    FROM roles
                    WHERE id = ?

                    UNION ALL

                    SELECT r.id, r.parent_id, r.name, rh.depth + 1
                    FROM roles r
                    INNER JOIN role_hierarchy rh ON r.parent_id = rh.id
                    WHERE rh.depth < 50
                )
                SELECT id, parent_id, name FROM role_hierarchy
                WHERE id != ?;
            ", [$this->id, $this->id]);

            return array_values($roles);
        } catch (\Exception $e) {
            // Log the error and return empty array to prevent application crash
            \Log::error('Role hierarchy query failed: ' . $e->getMessage(), [
                'role_id' => $this->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback: return direct children only
            return DB::select("
                SELECT id, parent_id, name
                FROM roles
                WHERE parent_id = ? AND id != ?
            ", [$this->id, $this->id]);
        }
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
