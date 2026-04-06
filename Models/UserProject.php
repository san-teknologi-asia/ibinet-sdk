<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProject extends Model
{
    use HasFactory;

    public const TYPE_PROJECT_MANAGER = 'PROJECT_MANAGER';
    public const TYPE_HELPDESK = 'HELPDESK';
    public const TYPE_FINANCE = 'FINANCE';

    protected $table = 'user_projects';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'created_at', 'updated_at'
    ];
}
