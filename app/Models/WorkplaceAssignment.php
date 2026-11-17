<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkplaceAssignment extends Model
{
    protected $fillable = ['user_id', 'workplace_id', 'shift_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
