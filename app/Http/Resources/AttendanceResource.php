<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'employee' => UserResource::make($this->whenLoaded('user')),
            'clocked_in_at' => $this->clocked_in_at?->toISOString(),
            'clocked_out_at' => $this->clocked_out_at?->toISOString(),
            'workplace' => WorkplaceResource::make($this->whenLoaded('workplace')),
            'breaks' => $this->whenLoaded('breaks', fn () => $this->breaks->map(fn ($break) => [
                'started_at' => $break->started_at?->toISOString(),
                'ended_at' => $break->ended_at?->toISOString(),
            ])),
        ];
    }
}
