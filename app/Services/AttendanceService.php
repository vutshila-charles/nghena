<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\Face\FaceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly LocationValidator $locations,
        private readonly FaceProvider $faceProvider,
    ) {}

    public function enrollFace(User $user, UploadedFile $image): void
    {
        $reference = $this->faceProvider->enroll($user, $image);

        $user->faceEnrollment()->updateOrCreate([], [
            'provider' => $this->faceProvider->name(),
            'provider_reference' => $reference,
            'enrolled_at' => now(),
        ]);
    }

    public function verifyFace(User $user, UploadedFile $image): void
    {
        if (! $user->faceEnrollment()->exists()) {
            throw new ApiException('face_enrollment_required', 'Enroll face verification before recording attendance.', 422);
        }

        if (! $this->faceProvider->verify($user, $image)) {
            throw new ApiException('face_verification_failed', 'Face verification could not confirm your identity.', 422);
        }
    }

    public function clockIn(User $user, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($user, $data) {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $workDate = now()->toDateString();

            if (AttendanceRecord::query()->where('user_id', $user->id)->whereIn('status', ['clocked_in', 'on_break'])->exists()) {
                throw new ApiException('attendance_already_active', 'Finish the active attendance record before clocking in again.', 409);
            }

            if (AttendanceRecord::query()->where('user_id', $user->id)->whereDate('work_date', $workDate)->exists()) {
                throw new ApiException('attendance_already_recorded', 'An attendance record already exists for today.', 409);
            }

            $workplace = $this->locations->assignedWorkplace($user, (int) $data['workplace_id']);
            $this->locations->assertWithinRadius($workplace, (float) $data['latitude'], (float) $data['longitude']);

            return AttendanceRecord::query()->create([
                'user_id' => $user->id,
                'workplace_id' => $workplace->id,
                'work_date' => $workDate,
                'status' => 'clocked_in',
                'clocked_in_at' => now(),
                'clock_in_latitude' => $data['latitude'],
                'clock_in_longitude' => $data['longitude'],
            ]);
        });
    }

    public function startBreak(User $user, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($user, $data) {
            $attendance = $this->activeAttendance($user);
            $this->validateLocation($attendance, $data);

            if ($attendance->status !== 'clocked_in') {
                throw new ApiException('invalid_attendance_state', 'A break can only start while clocked in.', 409);
            }

            $attendance->breaks()->create(['started_at' => now()]);
            $attendance->update(['status' => 'on_break']);

            return $attendance->refresh();
        });
    }

    public function endBreak(User $user, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($user, $data) {
            $attendance = $this->activeAttendance($user);
            $this->validateLocation($attendance, $data);

            if ($attendance->status !== 'on_break') {
                throw new ApiException('invalid_attendance_state', 'There is no active break to end.', 409);
            }

            $break = $attendance->breaks()->whereNull('ended_at')->latest('started_at')->firstOrFail();
            $break->update(['ended_at' => now()]);
            $attendance->update(['status' => 'clocked_in']);

            return $attendance->refresh();
        });
    }

    public function clockOut(User $user, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($user, $data) {
            $attendance = $this->activeAttendance($user);
            $this->validateLocation($attendance, $data);

            if ($attendance->status === 'on_break') {
                throw new ApiException('break_active', 'End the active break before clocking out.', 409);
            }

            $attendance->update([
                'status' => 'completed',
                'clocked_out_at' => now(),
                'clock_out_latitude' => $data['latitude'],
                'clock_out_longitude' => $data['longitude'],
            ]);

            return $attendance->refresh();
        });
    }

    public function current(User $user): ?AttendanceRecord
    {
        return $user->attendanceRecords()->with(['workplace', 'breaks'])
            ->whereIn('status', ['clocked_in', 'on_break'])
            ->latest('clocked_in_at')
            ->first()
            ?? $user->attendanceRecords()
                ->whereDate('work_date', now()->toDateString())
                ->with(['workplace', 'breaks'])
                ->first();
    }

    private function activeAttendance(User $user): AttendanceRecord
    {
        $attendance = $user->attendanceRecords()
            ->whereIn('status', ['clocked_in', 'on_break'])
            ->with('workplace')
            ->lockForUpdate()
            ->first();

        if (! $attendance) {
            throw new ApiException('no_active_attendance', 'There is no active attendance record for today.', 409);
        }

        return $attendance;
    }

    private function validateLocation(AttendanceRecord $attendance, array $data): void
    {
        $this->locations->assertWithinRadius(
            $attendance->workplace,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );
    }
}
