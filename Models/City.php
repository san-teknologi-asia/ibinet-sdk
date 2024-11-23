<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $table = 'indonesia_cities';

    protected $casts = [
        'meta' => 'array',
    ];

    public $fillable = ['name', 'code', 'province_code'];

    public $timestamps = false;

    public function province()
    {
        return $this->belongsTo('IDC\Models\Province', 'province_code', 'code');
    }

    public function districts()
    {
        return $this->hasMany('IDC\Models\District', 'city_code', 'code');
    }

    public function villages()
    {
        return $this->hasManyThrough(
            'IDC\Models\Village',
            'IDC\Models\District',
            'city_code',
            'district_code',
            'code',
            'code'
        );
    }

    public function getProvinceNameAttribute()
    {
        return $this->province->name;
    }
}
