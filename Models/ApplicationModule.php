<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationModule extends Model
{
    use HasFactory;

    protected $table = 'application_modules';

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

    public function permissions()
    {
        return $this->hasMany(Permission::class, 'application_module_id', 'id');   
    }
}
