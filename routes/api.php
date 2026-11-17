<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FaceController;
use App\Http\Controllers\Api\V1\WorkplaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->middleware('throttle:auth');
    Route::post('/tokens', 'issueToken')->middleware('throttle:auth');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
        Route::get('/tokens', 'tokens');
        Route::delete('/tokens/{tokenId}', 'revokeToken')->whereNumber('tokenId');
    });

    Route::post('/face/enrollment', [FaceController::class, 'enroll'])->middleware('throttle:attendance');
    Route::post('/face/verification', [FaceController::class, 'verify'])->middleware('throttle:attendance');

    Route::get('/workplaces/assigned', [WorkplaceController::class, 'assigned']);

    Route::prefix('attendance')->controller(AttendanceController::class)->group(function () {
        Route::get('/status', 'status');
        Route::get('/history', 'history');
        Route::post('/clock-in', 'clockIn')->middleware('throttle:attendance');
        Route::post('/clock-out', 'clockOut')->middleware('throttle:attendance');
        Route::post('/break/start', 'startBreak')->middleware('throttle:attendance');
        Route::post('/break/end', 'endBreak')->middleware('throttle:attendance');
        Route::get('/corrections', 'corrections');
        Route::post('/corrections', 'requestCorrection');
    });

    Route::prefix('admin')->middleware('can:administer')->controller(AdminController::class)->group(function () {
        Route::get('/dashboard', 'dashboard');
        Route::get('/employees', 'employees');
        Route::post('/employees', 'createEmployee');
        Route::patch('/employees/{user}', 'updateEmployee')->whereNumber('user');
        Route::post('/employees/{user}/workplaces', 'assignWorkplace')->whereNumber('user');
        Route::get('/workplaces', 'workplaces');
        Route::post('/workplaces', 'createWorkplace');
        Route::patch('/workplaces/{workplace}', 'updateWorkplace')->whereNumber('workplace');
        Route::get('/shifts', 'shifts');
        Route::post('/shifts', 'createShift');
        Route::patch('/shifts/{shift}', 'updateShift')->whereNumber('shift');
        Route::get('/corrections', 'corrections');
        Route::patch('/corrections/{correction}', 'reviewCorrection')->whereNumber('correction');
        Route::get('/reports/attendance', 'report');
    });
});
