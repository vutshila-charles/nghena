<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkplaceResource;
use Illuminate\Http\Request;

class WorkplaceController extends Controller
{
    public function assigned(Request $request)
    {
        $assignments = $request->user()->workplaceAssignments()
            ->whereHas('workplace', fn ($query) => $query->where('is_active', true))
            ->with(['workplace', 'shift'])
            ->get()
            ->map(fn ($assignment) => [
                'workplace' => WorkplaceResource::make($assignment->workplace),
                'shift' => $assignment->shift ? [
                    'id' => $assignment->shift->id,
                    'name' => $assignment->shift->name,
                    'starts_at' => $assignment->shift->starts_at,
                    'ends_at' => $assignment->shift->ends_at,
                    'break_minutes' => $assignment->shift->break_minutes,
                ] : null,
            ]);

        return response()->json(['data' => $assignments]);
    }
}
