<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class ApprovalRevisionHistory extends Model
{
    use HasFactory;
    
    protected $table = 'approval_revision_histories';

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
        'data' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * Still awaiting the submitter's correction. Resubmission reads this to
     * decide between replaying the flow and returning to whoever asked.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function requestedBy()
    {
        return $this->belongsTo('Ibinet\Models\User', 'requested_user_id');
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
