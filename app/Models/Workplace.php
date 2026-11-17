<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workplace extends Model
{
    protected $attributes = [
        'radius_meters' => 150,
        'is_active' => true,
    ];

    protected $fillable = ['name', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkplaceAssignment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
