<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class FaceController extends Controller
{
    public function enroll(Request $request, AttendanceService $attendance)
    {
        $data = $request->validate(['image' => ['required', 'image', 'max:10240']]);
        $attendance->enrollFace($request->user(), $data['image']);

        return response()->json(['data' => ['enrolled' => true]], 201);
    }

    public function verify(Request $request, AttendanceService $attendance)
    {
        $data = $request->validate(['image' => ['required', 'image', 'max:10240']]);
        $attendance->verifyFace($request->user(), $data['image']);

        return response()->json(['data' => ['verified' => true]]);
    }
}
