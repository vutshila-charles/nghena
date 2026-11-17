<?php

namespace App\Services\Face;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UnavailableFaceProvider implements FaceProvider
{
    public function name(): string
    {
        return 'unconfigured';
    }

    public function enroll(User $user, UploadedFile $image): string
    {
        throw new ApiException('face_provider_unavailable', 'Face verification is not configured.', 503);
    }

    public function verify(User $user, UploadedFile $image): bool
    {
        throw new ApiException('face_provider_unavailable', 'Face verification is not configured.', 503);
    }
}
