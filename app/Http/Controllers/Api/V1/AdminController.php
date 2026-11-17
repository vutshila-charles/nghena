<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\CorrectionRequestResource;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkplaceResource;
use App\Models\AttendanceRecord;
use App\Models\CorrectionRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Services\CorrectionService;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard()
    {
        $today = now()->toDateString();

        return response()->json(['data' => [
            'employee_count' => User::query()->where('role', 'employee')->where('is_active', true)->count(),
            'clocked_in_count' => AttendanceRecord::query()->whereDate('work_date', $today)->whereIn('status', ['clocked_in', 'on_break'])->count(),
            'pending_corrections' => CorrectionRequest::query()->where('status', 'pending')->count(),
            'today' => AttendanceResource::collection(AttendanceRecord::query()->whereDate('work_date', $today)->with(['user', 'workplace', 'breaks'])->latest('clocked_in_at')->limit(10)->get()),
        ]]);
    }

    public function employees(Request $request)
    {
        $employees = User::query()->where('role', 'employee')->with('faceEnrollment')->orderBy('name')->paginate(min((int) $request->query('per_page', 20), 100));

        return UserResource::collection($employees);
    }

    public function createEmployee(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $employee = User::query()->create([...$data, 'role' => 'employee']);

        return response()->json(['data' => UserResource::make($employee)], 201);
    }

    public function updateEmployee(Request $request, User $user)
    {
        abort_unless($user->role === 'employee', 404, 'Employee not found.');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:12'],
        ]);
        $user->update($data);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json(['data' => UserResource::make($user->refresh())]);
    }

    public function assignWorkplace(Request $request, User $user)
    {
        abort_unless($user->role === 'employee', 404, 'Employee not found.');
        $data = $request->validate([
            'workplace_id' => ['required', 'integer', 'exists:workplaces,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);
        $user->workplaceAssignments()->updateOrCreate(
            ['workplace_id' => $data['workplace_id']],
            ['shift_id' => $data['shift_id'] ?? null],
        );

        return response()->json(['data' => ['assigned' => true]], 201);
    }

    public function workplaces(Request $request)
    {
        return WorkplaceResource::collection(Workplace::query()->orderBy('name')->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function createWorkplace(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:25,5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $workplace = Workplace::query()->create($data);

        return response()->json(['data' => WorkplaceResource::make($workplace)], 201);
    }

    public function updateWorkplace(Request $request, Workplace $workplace)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => ['sometimes', 'integer', 'between:25,5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $workplace->update($data);

        return response()->json(['data' => WorkplaceResource::make($workplace->refresh())]);
    }

    public function shifts(Request $request)
    {
        return ShiftResource::collection(Shift::query()->orderBy('name')->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function createShift(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'break_minutes' => ['required', 'integer', 'between:0,480'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $shift = Shift::query()->create($data);

        return response()->json(['data' => ShiftResource::make($shift)], 201);
    }

    public function updateShift(Request $request, Shift $shift)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'between:0,480'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $shift->update($data);

        return response()->json(['data' => ShiftResource::make($shift->refresh())]);
    }

    public function corrections(Request $request)
    {
        $corrections = CorrectionRequest::query()->with(['user', 'attendanceRecord.workplace'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return CorrectionRequestResource::collection($corrections);
    }

    public function reviewCorrection(Request $request, CorrectionRequest $correction, CorrectionService $corrections)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $correction = $corrections->review($request->user(), $correction, $data);

        return response()->json(['data' => CorrectionRequestResource::make($correction->load('user'))]);
    }

    public function report(Request $request)
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $query = AttendanceRecord::query()->with(['user', 'workplace', 'breaks'])
            ->when($range['from'] ?? null, fn ($query, $from) => $query->whereDate('work_date', '>=', $from))
            ->when($range['to'] ?? null, fn ($query, $to) => $query->whereDate('work_date', '<=', $to))
            ->when($range['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderByDesc('work_date');
        $records = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => [
                'record_count' => $records->total(),
                'records' => AttendanceResource::collection($records),
            ],
        ]);
    }
}
