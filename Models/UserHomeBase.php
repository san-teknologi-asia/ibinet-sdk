<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class UserHomeBase extends Model
{
    protected $table = 'user_homebases';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];
}
