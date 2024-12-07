<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $table = 'indonesia_provinces';

    protected $casts = [
        'meta' => 'array',
    ];

    public $timestamps = false;

    public $fillable = ['name', 'code'];

    public function cities()
    {
        return $this->hasMany('Ibinet\Models\City', 'province_code', 'code');
    }

    public function districts()
    {
        return $this->hasManyThrough(
            'Ibinet\Models\District',
            'Ibinet\Models\City',
            'province_code',
            'city_code',
            'code',
            'code'
        );
    }
}
