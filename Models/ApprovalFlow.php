<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

/**
 * An approval flow is versioned and immutable once it has been used.
 *
 * Editing a flow that already carries approval activity creates a new version
 * in the same family rather than rewriting the current one, so requests that
 * are mid-flight keep resolving against the definition they started on.
 */
class ApprovalFlow extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'approval_flows';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    protected $casts = [
        'version' => 'integer',
        'is_latest' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany('Ibinet\Models\ApprovalFlowDetail')->orderBy('order');
    }

    /**
     * Every version of this flow, newest first.
     */
    public function versions()
    {
        return $this->hasMany(self::class, 'family_id', 'family_id')
            ->orderByDesc('version');
    }

    /**
     * Only the version an operator edits and wires into settings. Superseded
     * versions stay in the table so running approvals can still resolve them.
     *
     * Named `latestVersion` rather than `latest` so it does not shadow
     * Eloquent's own `latest()` ordering helper.
     */
    public function scopeLatestVersion($query)
    {
        return $query->where('is_latest', true);
    }

    /**
     * The family this row belongs to. Rows created before versioning existed
     * are backfilled to their own id, but fall back defensively.
     */
    public function getFamilyKeyAttribute()
    {
        return $this->family_id ?: $this->id;
    }

    /**
     *  Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();

            // A flow with no explicit family starts a family of its own.
            if (empty($model->family_id)) {
                $model->family_id = $model->id;
            }
        });
    }
}
