<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingCallbackController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CashEntryController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\QaController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
    ]);
});

$authThrottle = app()->environment('local', 'testing') ? 'throttle:120,1' : 'throttle:10,1';

Route::middleware($authThrottle)->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::get('/billing/plans', [BillingController::class, 'plans']);

Route::post('/billing/jazzcash/ipn', [BillingCallbackController::class, 'jazzCashIpn']);
Route::match(['get', 'post'], '/billing/jazzcash/return', [BillingCallbackController::class, 'jazzCashReturn']);
Route::post('/billing/easypaisa/ipn', [BillingCallbackController::class, 'easyPaisaIpn']);
Route::match(['get', 'post'], '/billing/easypaisa/return', [BillingCallbackController::class, 'easyPaisaReturn']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/categories', [CategoryController::class, 'index']);

    Route::middleware('subscribed')->group(function () {
        Route::get('/cash-entries', [CashEntryController::class, 'index']);
        Route::post('/cash-entries', [CashEntryController::class, 'store']);
        Route::put('/cash-entries/{cashEntry}', [CashEntryController::class, 'update']);
        Route::delete('/cash-entries/{cashEntry}', [CashEntryController::class, 'destroy']);

        Route::get('/reports/weekly', [ReportController::class, 'weekly']);
        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/yearly', [ReportController::class, 'yearly']);

        Route::post('/receipts/upload', [ReceiptController::class, 'upload']);
    });

    Route::get('/billing/subscription', [BillingController::class, 'subscription']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/billing/sandbox/complete/{payment}', [BillingController::class, 'sandboxComplete']);
});

if (app()->environment('local', 'testing')) {
    Route::middleware('auth:sanctum')->post('/qa/expire-trial', [QaController::class, 'expireTrial']);
}
