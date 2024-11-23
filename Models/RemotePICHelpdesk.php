<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RemotePICHelpdesk extends Model
{
    use HasFactory;

    public $incrementing = false;

    public $table = 'remote_pic_helpdesks';

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
        return $this->belongsTo('IDC\Models\Pic');
    }
}
