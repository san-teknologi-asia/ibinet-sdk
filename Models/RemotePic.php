<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class RemotePic extends Model
{
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public function pic()
    {
        return $this->belongsTo('Ibinet\Models\Pic');
    }
}
