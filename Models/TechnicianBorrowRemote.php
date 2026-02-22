<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

class TechnicianBorrowRemote extends Model
{
    use SoftDeletes;

    protected $table = 'technician_borrow_remotes';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'estimated_duration' => 'integer',
        'actual_duration' => 'integer',
        'is_removed' => 'boolean',
    ];

    /**
     * Setup model event hooks
     */
    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->id = (string) Uuid::uuid4();
        });
    }

    /**
     * Get the parent borrow
     */
    public function technicianBorrow()
    {
        return $this->belongsTo('Ibinet\Models\TechnicianBorrow', 'technician_borrow_id');
    }

    /**
     * Get the remote
     */
    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote', 'remote_id');
    }

    /**
     * Get the work type
     */
    public function workType()
    {
        return $this->belongsTo('Ibinet\Models\WorkType', 'work_type_id');
    }
}
