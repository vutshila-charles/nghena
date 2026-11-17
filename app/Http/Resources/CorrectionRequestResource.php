<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CorrectionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->requested_work_date?->toDateString(),
            'requested_clocked_in_at' => $this->requested_clocked_in_at?->toISOString(),
            'requested_clocked_out_at' => $this->requested_clocked_out_at?->toISOString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'employee' => UserResource::make($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
