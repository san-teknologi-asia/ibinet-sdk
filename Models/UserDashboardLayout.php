<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * A single user's dashboard in a single app.
 *
 * Keyed by (user_id, application), so a layout is never shared: what one user
 * does to their dashboard is invisible to everyone else.
 */
class UserDashboardLayout extends Model
{
    protected $table = 'user_dashboard_layouts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [
        'created_at', 'updated_at'
    ];

    protected $casts = [
        'layout' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }
}
