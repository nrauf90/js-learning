<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CashEntryController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\PaddleWebhookController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QaController;
use App\Http\Controllers\Api\SaleController;
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
    Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange']);
});

Route::middleware($authThrottle)->get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::get('/billing/plans', [BillingController::class, 'plans']);

// Paddle notifications. Unauthenticated by necessity — the Paddle-Signature
// HMAC over the raw body is the authentication. Throttled so a flood of
// unsigned junk cannot pin the app; Paddle's own delivery rate stays well under
// this and it retries anything it gets a 429 for.
Route::middleware('throttle:120,1')
    ->post('/billing/paddle/webhook', [PaddleWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () use ($authThrottle) {
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::middleware($authThrottle)->put('/user/password', [AuthController::class, 'updatePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/categories', [CategoryController::class, 'index']);

    Route::middleware('subscribed')->group(function () {
        Route::get('/cash-entries', [CashEntryController::class, 'index']);
        Route::post('/cash-entries', [CashEntryController::class, 'store']);
        Route::put('/cash-entries/{cashEntry}', [CashEntryController::class, 'update']);
        Route::delete('/cash-entries/{cashEntry}', [CashEntryController::class, 'destroy']);

        // Point of sale. Behind the same subscription gate as cash entries —
        // it writes into the same ledger.
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/lookup', [ProductController::class, 'lookup']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/stock', [ProductController::class, 'adjustStock']);

        Route::get('/product-categories', [ProductCategoryController::class, 'index']);
        Route::post('/product-categories', [ProductCategoryController::class, 'store']);
        Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
        Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);

        Route::get('/sales', [SaleController::class, 'index']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/today', [SaleController::class, 'today']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/refund', [SaleController::class, 'refund']);

        Route::get('/reports/weekly', [ReportController::class, 'weekly']);
        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/yearly', [ReportController::class, 'yearly']);

        Route::post('/receipts/upload', [ReceiptController::class, 'upload']);
    });

    Route::get('/billing/subscription', [BillingController::class, 'subscription']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/billing/portal', [BillingController::class, 'portal']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/sandbox/complete/{payment}', [BillingController::class, 'completeTestPayment']);

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{user}', [AdminController::class, 'userShow']);
        Route::put('/users/{user}', [AdminController::class, 'userUpdate']);
        Route::delete('/users/{user}', [AdminController::class, 'userDestroy']);

        Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
        Route::put('/subscriptions/{subscription}', [AdminController::class, 'subscriptionUpdate']);

        Route::get('/cash-entries', [AdminController::class, 'cashEntries']);
        Route::delete('/cash-entries/{cashEntry}', [AdminController::class, 'cashEntryDestroy']);

        Route::get('/payments', [AdminController::class, 'payments']);

        Route::get('/categories', [AdminController::class, 'categories']);
        Route::post('/categories', [AdminController::class, 'categoryStore']);
        Route::put('/categories/{category}', [AdminController::class, 'categoryUpdate']);
        Route::delete('/categories/{category}', [AdminController::class, 'categoryDestroy']);
    });
});

if (app()->environment('local', 'testing')) {
    Route::middleware('auth:sanctum')->post('/qa/expire-trial', [QaController::class, 'expireTrial']);
}
