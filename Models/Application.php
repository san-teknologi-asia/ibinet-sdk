<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class Application extends Model
{
    use HasFactory;
    protected $table = 'applications';

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

    /**
     * Get custom attribute with url
     *
     * @param self $value
     */
    public function getLogoAttribute($value)
    {
        return env('AWS_BASE_URL').$value;
    }

    public function applicationModules()
    {
        return $this->hasMany(ApplicationModule::class, 'application_id', 'id');
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
