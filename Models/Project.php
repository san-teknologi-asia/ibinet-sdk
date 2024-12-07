<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Contracts\Activity;
use IDC\Traits\LogTrait;
use Ramsey\Uuid\Uuid;

class Project extends Model
{
    use SoftDeletes, LogTrait;

    public $incrementing = false;
    protected static $logName = 'Project';
    protected static $logAttributes = ['*'];
    protected static $logOnlyDirty = true;

    public $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];

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

    public function client()
    {
        return $this->belongsTo('Ibinet\Models\Client');
    }

    public function workType()
    {
        return $this->hasMany('Ibinet\Models\ProjectWorkType');
    }

    public function remote()
    {
        return $this->hasMany('Ibinet\Models\ProjectRemote');
    }
}
