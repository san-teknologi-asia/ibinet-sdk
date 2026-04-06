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

    /**
     * Get all descendant roles under this role using a bounded iterative traversal.
     * Does not rely on MySQL session variables or recursive CTE.
     *
     * @param int $maxDepth Maximum depth to traverse (default 50)
     * @return array<int, object> Array of stdClass objects with id, parent_id, name
     */
    public function childrenRoles(int $maxDepth = 50): array
    {
        $visited = [];
        $currentLevel = [$this->id];
        $result = [];

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            if (empty($currentLevel)) {
                break;
            }

            $nextLevel = [];

            $children = DB::select("
                SELECT id, parent_id, name
                FROM roles
                WHERE parent_id IN (?)
                AND id NOT IN (?)
            ", [implode(',', $currentLevel), implode(',', array_merge($visited, [$this->id]))]);

            foreach ($children as $child) {
                if (in_array($child->id, $visited, true)) {
                    continue;
                }
                $visited[] = $child->id;
                $result[] = $child;
                $nextLevel[] = $child->id;
            }

            $currentLevel = $nextLevel;
        }

        return $result;
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
