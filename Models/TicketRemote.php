<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class TicketRemote extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public function remote()
    {
        return $this->belongsTo('Ibinet\Models\Remote');
    }

    public function ticket()
    {
        return $this->belongsTo('Ibinet\Models\Ticket');
    }
}
