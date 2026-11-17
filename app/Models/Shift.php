<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $attributes = [
        'break_minutes' => 0,
        'is_active' => true,
    ];

    protected $fillable = ['name', 'starts_at', 'ends_at', 'break_minutes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
