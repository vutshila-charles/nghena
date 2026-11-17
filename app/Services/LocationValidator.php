<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workplace;

class LocationValidator
{
    public function assignedWorkplace(User $user, int $workplaceId): Workplace
    {
        $workplace = Workplace::query()
            ->whereKey($workplaceId)
            ->where('is_active', true)
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (! $workplace) {
            throw new ApiException('workplace_not_assigned', 'The selected workplace is not assigned to this employee.', 403);
        }

        return $workplace;
    }

    public function assertWithinRadius(Workplace $workplace, float $latitude, float $longitude): void
    {
        $earthRadiusMeters = 6371000;
        $latDelta = deg2rad($latitude - $workplace->latitude);
        $lonDelta = deg2rad($longitude - $workplace->longitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($workplace->latitude)) * cos(deg2rad($latitude)) * sin($lonDelta / 2) ** 2;
        $distance = 2 * $earthRadiusMeters * asin(min(1, sqrt($a)));

        if ($distance > $workplace->radius_meters) {
            throw new ApiException('outside_workplace_radius', 'You must be within the assigned workplace radius.', 422);
        }
    }
}
