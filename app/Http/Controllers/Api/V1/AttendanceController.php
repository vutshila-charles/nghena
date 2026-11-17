<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\CorrectionRequestResource;
use App\Services\AttendanceService;
use App\Services\CorrectionService;
use App\Services\IdempotencyService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly IdempotencyService $idempotency,
        private readonly CorrectionService $corrections,
    ) {}

    public function status(Request $request)
    {
        $record = $this->attendance->current($request->user());

        return response()->json(['data' => ['attendance' => $record ? AttendanceResource::make($record->load(['workplace', 'breaks'])) : null]]);
    }

    public function history(Request $request)
    {
        $records = $request->user()->attendanceRecords()
            ->with(['workplace', 'breaks'])
            ->orderByDesc('work_date')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        return AttendanceResource::collection($records);
    }

    public function clockIn(Request $request)
    {
        $data = $this->actionData($request, true);

        return $this->perform($request, $data, function () use ($request, $data) {
            $this->attendance->verifyFace($request->user(), $data['face_image']);

            return $this->attendance->clockIn($request->user(), $data);
        }, 201);
    }

    public function clockOut(Request $request)
    {
        $data = $this->actionData($request);

        return $this->perform($request, $data, function () use ($request, $data) {
            $this->attendance->verifyFace($request->user(), $data['face_image']);

            return $this->attendance->clockOut($request->user(), $data);
        });
    }

    public function startBreak(Request $request)
    {
        $data = $this->actionData($request);

        return $this->perform($request, $data, function () use ($request, $data) {
            $this->attendance->verifyFace($request->user(), $data['face_image']);

            return $this->attendance->startBreak($request->user(), $data);
        });
    }

    public function endBreak(Request $request)
    {
        $data = $this->actionData($request);

        return $this->perform($request, $data, function () use ($request, $data) {
            $this->attendance->verifyFace($request->user(), $data['face_image']);

            return $this->attendance->endBreak($request->user(), $data);
        });
    }

    public function requestCorrection(Request $request)
    {
        $data = $request->validate([
            'attendance_record_id' => ['nullable', 'integer', 'exists:attendance_records,id'],
            'workplace_id' => ['nullable', 'integer', 'exists:workplaces,id', 'required_without:attendance_record_id'],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'clocked_in_at' => ['nullable', 'date'],
            'clocked_out_at' => ['nullable', 'date', 'after:clocked_in_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if (! empty($data['attendance_record_id'])) {
            $owned = $request->user()->attendanceRecords()->whereKey($data['attendance_record_id'])->exists();
            abort_unless($owned, 404, 'Attendance record not found.');
        }

        $correction = $this->corrections->submit($request->user(), $data);

        return response()->json(['data' => CorrectionRequestResource::make($correction)], 201);
    }

    public function corrections(Request $request)
    {
        return CorrectionRequestResource::collection(
            $request->user()->correctionRequests()->latest()->paginate(min((int) $request->query('per_page', 15), 100)),
        );
    }

    private function actionData(Request $request, bool $includeWorkplace = false): array
    {
        $rules = [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'face_image' => ['required', 'image', 'max:10240'],
        ];

        if ($includeWorkplace) {
            $rules['workplace_id'] = ['required', 'integer', 'exists:workplaces,id'];
        }

        $data = $request->validate($rules);
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 128) {
            throw new ApiException('idempotency_key_required', 'Provide an Idempotency-Key header between 8 and 128 characters.', 422);
        }

        $data['__idempotency_key'] = $key;

        return $data;
    }

    private function perform(Request $request, array $data, \Closure $operation, int $status = 200)
    {
        $key = $data['__idempotency_key'];
        unset($data['__idempotency_key']);
        $result = $this->idempotency->run($request->user(), $key, $data, function () use ($request, $operation, $status) {
            $record = $operation();
            $record->load(['workplace', 'breaks']);

            return [
                'status' => $status,
                'body' => ['data' => ['attendance' => AttendanceResource::make($record)->resolve($request)]],
            ];
        });

        return response()->json($result['body'], $result['status'])
            ->header('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
