<?php

namespace Ibinet\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Village extends Model
{
    protected $table = 'indonesia_villages';

    protected $casts = [
        'meta' => 'array',
    ];

    public $timestamps = false;

    public $fillable = ['name', 'code', 'district_code'];

    public function district()
    {
        return $this->belongsTo('IDC\Models\District', 'district_code', 'code');
    }

    public function getDistrictNameAttribute()
    {
        return $this->district->name;
    }

    public function getCityNameAttribute()
    {
        return $this->district->city->name;
    }

    public function getProvinceNameAttribute()
    {
        return $this->district->city->province->name;
    }
}
