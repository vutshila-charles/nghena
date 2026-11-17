<?php

namespace App\Services\Face;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface FaceProvider
{
    public function name(): string;

    public function enroll(User $user, UploadedFile $image): string;

    public function verify(User $user, UploadedFile $image): bool;
}
