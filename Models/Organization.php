<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * A node of the company org chart.
 *
 * One self-referencing tree covers every level -- division, department, unit --
 * so "unit" is simply a deeper node, not a different entity. Users point at the
 * single node they sit in and everything above it is derived from parent_id.
 */
class Organization extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Conventional labels for the `type` column. Nothing enforces these; they
     * only drive how a level is described in the UI.
     */
    public const TYPE_DIVISION = 'DIVISION';
    public const TYPE_DEPARTMENT = 'DEPARTMENT';
    public const TYPE_UNIT = 'UNIT';

    protected $table = 'organizations';

    public $incrementing = false;

    public $keyType = 'string';

    protected $guarded = [
        'created_at', 'updated_at'
    ];

    public function parent()
    {
        return $this->belongsTo('Ibinet\Models\Organization', 'parent_id');
    }

    public function children()
    {
        return $this->hasMany('Ibinet\Models\Organization', 'parent_id');
    }

    public function users()
    {
        return $this->hasMany('Ibinet\Models\User', 'organization_id');
    }

    /**
     * Get all descendant organizations under this node using a bounded
     * iterative traversal. Does not rely on MySQL session variables or
     * recursive CTE. Mirrors Role::childrenRoles().
     *
     * @param int $maxDepth Maximum depth to traverse (default 50)
     * @return array<int, object> Array of stdClass objects with id, parent_id, name
     */
    public function childrenOrganizations(int $maxDepth = 50): array
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

            // Raw select bypasses the SoftDeletes global scope, so trashed nodes
            // have to be excluded by hand or they come back as live descendants.
            $children = DB::select("
                SELECT id, parent_id, name, type
                FROM organizations
                WHERE parent_id IN ({$parentPlaceholders})
                AND id NOT IN ({$excludePlaceholders})
                AND deleted_at IS NULL
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
     * This node plus every descendant, as ids. The shape headcount and
     * "everyone under X" queries actually want.
     *
     * @param int $maxDepth
     * @return array<int, string>
     */
    public function selfAndDescendantIds(int $maxDepth = 50): array
    {
        $ids = [$this->id];

        foreach ($this->childrenOrganizations($maxDepth) as $child) {
            $ids[] = $child->id;
        }

        return $ids;
    }

    /**
     * The chain from the root down to this node, inclusive.
     *
     * Walking upwards is what makes a single organization_id on the user enough:
     * choose the unit and the department is already known.
     *
     * @param int $maxDepth Guards against a parent_id cycle in bad data
     * @return array<int, \Ibinet\Models\Organization>
     */
    public function ancestry(int $maxDepth = 50): array
    {
        $chain = [$this];
        $seen = [$this->id];
        $node = $this;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            if (!$node->parent_id || in_array($node->parent_id, $seen, true)) {
                break;
            }

            $parent = static::find($node->parent_id);

            if (!$parent) {
                break;
            }

            array_unshift($chain, $parent);
            $seen[] = $parent->id;
            $node = $parent;
        }

        return $chain;
    }

    /**
     * Nearest ancestor (or self) carrying the given type, e.g. the DEPARTMENT
     * a UNIT belongs to. Falls back to the immediate parent when the tree has
     * not been labelled, so this still answers sensibly on unlabelled data.
     *
     * @param string $type
     * @return \Ibinet\Models\Organization|null
     */
    public function nearestOfType($type = self::TYPE_DEPARTMENT)
    {
        $chain = array_reverse($this->ancestry());

        foreach ($chain as $node) {
            if ($node->type === $type) {
                return $node;
            }
        }

        return $this->parent;
    }

    /**
     * Readable path, e.g. "Operations / Operation And Maintenance / OM Implementation".
     *
     * @return string
     */
    public function getPathAttribute()
    {
        return implode(' / ', array_map(function ($node) {
            return $node->name;
        }, $this->ancestry()));
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
