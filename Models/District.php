<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $table = 'indonesia_districts';

    protected $casts = [
        'meta' => 'array',
    ];

    public $timestamps = false;

    public $fillable = ['name', 'code', 'city_code'];

    public function city()
    {
        return $this->belongsTo('IDC\Models\City', 'city_code', 'code');
    }

    public function villages()
    {
        return $this->hasMany('IDC\Models\Village', 'district_code', 'code');
    }

    public function getCityNameAttribute()
    {
        return $this->city->name;
    }

    public function getProvinceNameAttribute()
    {
        return $this->city->province->name;
    }
}
