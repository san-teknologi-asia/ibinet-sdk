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

    protected $casts = [
        'is_project_manager' => 'boolean',
        'is_technician' => 'boolean',
    ];

    /**
     * Roles directly under this one.
     */
    public function children()
    {
        return $this->hasMany(Role::class, 'parent_id');
    }

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

            // One placeholder per id: a comma-joined string bound to a single
            // placeholder is compared as one literal value and matches nothing.
            $exclude = array_values(array_unique(array_merge($visited, [$this->id])));
            $parentPlaceholders = implode(',', array_fill(0, count($currentLevel), '?'));
            $excludePlaceholders = implode(',', array_fill(0, count($exclude), '?'));

            $children = DB::select("
                SELECT id, parent_id, name
                FROM roles
                WHERE parent_id IN ({$parentPlaceholders})
                AND id NOT IN ({$excludePlaceholders})
            ", array_merge($currentLevel, $exclude));

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
