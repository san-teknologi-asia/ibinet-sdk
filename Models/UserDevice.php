<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Uuid;

class UserDevice extends Model
{
    protected $table = 'user_devices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];
}
