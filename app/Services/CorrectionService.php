<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AttendanceRecord;
use App\Models\CorrectionRequest;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Support\Facades\DB;

class CorrectionService
{
    public function __construct(private readonly LocationValidator $locations) {}

    public function submit(User $user, array $data): CorrectionRequest
    {
        if (empty($data['attendance_record_id']) && ! empty($data['workplace_id'])) {
            $this->locations->assignedWorkplace($user, (int) $data['workplace_id']);
        }

        return $user->correctionRequests()->create([
            'attendance_record_id' => $data['attendance_record_id'] ?? null,
            'workplace_id' => $data['workplace_id'] ?? null,
            'requested_work_date' => $data['work_date'],
            'requested_clocked_in_at' => $data['clocked_in_at'] ?? null,
            'requested_clocked_out_at' => $data['clocked_out_at'] ?? null,
            'reason' => $data['reason'],
        ]);
    }

    public function review(User $reviewer, CorrectionRequest $correction, array $data): CorrectionRequest
    {
        return DB::transaction(function () use ($reviewer, $correction, $data) {
            $correction = CorrectionRequest::query()->lockForUpdate()->findOrFail($correction->id);

            if ($correction->status !== 'pending') {
                throw new ApiException('correction_already_reviewed', 'This correction request has already been reviewed.', 409);
            }

            if ($data['decision'] === 'approved') {
                $this->applyCorrection($correction);
            }

            $correction->update([
                'status' => $data['decision'],
                'reviewed_by' => $reviewer->id,
                'review_note' => $data['review_note'] ?? null,
                'reviewed_at' => now(),
            ]);

            return $correction->refresh();
        });
    }

    private function applyCorrection(CorrectionRequest $correction): void
    {
        $existingForDate = AttendanceRecord::query()
            ->where('user_id', $correction->user_id)
            ->whereDate('work_date', $correction->requested_work_date)
            ->when($correction->attendance_record_id, fn ($query) => $query->where('id', '!=', $correction->attendance_record_id))
            ->exists();

        if ($existingForDate) {
            throw new ApiException('attendance_already_recorded', 'An attendance record already exists for the requested work date.', 409);
        }

        if ($correction->attendance_record_id) {
            $record = AttendanceRecord::query()->whereKey($correction->attendance_record_id)->lockForUpdate()->firstOrFail();
            $record->update([
                'work_date' => $correction->requested_work_date,
                'clocked_in_at' => $correction->requested_clocked_in_at ?? $record->clocked_in_at,
                'clocked_out_at' => $correction->requested_clocked_out_at ?? $record->clocked_out_at,
                'status' => $correction->requested_clocked_out_at ? 'completed' : $record->status,
            ]);

            return;
        }

        if (! $correction->workplace_id || ! $correction->requested_clocked_in_at) {
            throw new ApiException('correction_data_incomplete', 'An approved new attendance record requires a workplace and clock-in time.', 422);
        }

        $workplace = Workplace::query()->findOrFail($correction->workplace_id);
        $clockedOutAt = $correction->requested_clocked_out_at;

        $record = AttendanceRecord::query()->create([
            'user_id' => $correction->user_id,
            'workplace_id' => $workplace->id,
            'work_date' => $correction->requested_work_date,
            'status' => $clockedOutAt ? 'completed' : 'clocked_in',
            'clocked_in_at' => $correction->requested_clocked_in_at,
            'clocked_out_at' => $clockedOutAt,
            'clock_in_latitude' => $workplace->latitude,
            'clock_in_longitude' => $workplace->longitude,
            'clock_out_latitude' => $clockedOutAt ? $workplace->latitude : null,
            'clock_out_longitude' => $clockedOutAt ? $workplace->longitude : null,
        ]);

        $correction->update(['attendance_record_id' => $record->id]);
    }
}
