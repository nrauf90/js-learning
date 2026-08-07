<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CashEntryController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DayBalanceController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\PaddleWebhookController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\QaController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Middleware\ShopAdminOnly;
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

        // ── sales ───────────────────────────────────────────────────────────
        Route::get('/sales', [SaleController::class, 'index']);
        Route::post('/sales', [SaleController::class, 'store']);
        // Both static paths stay above /sales/{sale} or they would be swallowed
        // by the model binding.
        Route::get('/sales/today', [SaleController::class, 'today']);
        Route::get('/sales/stats', [SaleController::class, 'stats']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);
        Route::post('/sales/{sale}/refund', [SaleController::class, 'refund']);
        Route::post('/sales/{sale}/payments', [SaleController::class, 'storePayment']);
        // ── end sales ───────────────────────────────────────────────────────

        // ── purchases (stock in) ────────────────────────────────────────────
        // What the shop paid for its stock. Without this the catalogue's `cost`
        // is whatever someone last typed by hand, and every profit figure that
        // depends on it is a guess.
        Route::get('/suppliers', [PurchaseController::class, 'suppliers']);
        Route::post('/suppliers', [PurchaseController::class, 'storeSupplier']);
        Route::put('/suppliers/{supplier}', [PurchaseController::class, 'updateSupplier']);
        Route::get('/purchases', [PurchaseController::class, 'index']);
        // Above the model binding, or "outstanding" is read as a purchase id.
        Route::get('/purchases/outstanding', [PurchaseController::class, 'outstanding']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::post('/purchases/{purchase}/payments', [PurchaseController::class, 'storePayment']);
        // ── end purchases ───────────────────────────────────────────────────

        // ── customers (udhaar khata) ────────────────────────────────────────
        // The notebook by the till. Static paths stay above the model binding.
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/outstanding', [CustomerController::class, 'outstanding']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger']);
        Route::post('/customers/{customer}/payments', [CustomerController::class, 'recordPayment']);
        // ── end customers ───────────────────────────────────────────────────

        // ── day book (opening / closing balance) ────────────────────────────
        Route::get('/day-book', [DayBalanceController::class, 'index']);
        Route::get('/day-book/current', [DayBalanceController::class, 'current']);
        Route::post('/day-book/open', [DayBalanceController::class, 'open']);
        Route::post('/day-book/close', [DayBalanceController::class, 'close']);
        // ── end day book ────────────────────────────────────────────────────

        // ── shop, staff and roles ───────────────────────────────────────────
        // Shop admins run these from inside the app, so they sit behind the
        // same subscription gate as everything else in this group. Staff may
        // read the shop (the receipt header comes from it) but nothing more.
        Route::get('/shop', [ShopController::class, 'show']);

        Route::middleware(ShopAdminOnly::class)->group(function () {
            Route::put('/shop', [ShopController::class, 'update']);

            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::put('/staff/{user}', [StaffController::class, 'update']);
            Route::delete('/staff/{user}', [StaffController::class, 'destroy']);
        });

        // Onboarding a new shop is platform-operator work, so it opts out of
        // the subscription gate above — whether the operator's own trial is
        // still running has nothing to do with signing up a customer.
        Route::middleware('admin')
            ->prefix('admin')
            ->withoutMiddleware('subscribed')
            ->group(function () {
                Route::get('/shop-admins', [AdminController::class, 'shopAdmins']);
                Route::post('/shop-admins', [AdminController::class, 'shopAdminStore']);
            });
        // ── end shop, staff and roles ───────────────────────────────────────

        // ── media upload and activity log ───────────────────────────────────
        // Multipart, so they sit outside the JSON product/category routes above
        // rather than overloading PUT with two request shapes.
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::delete('/products/{product}/image', [ProductController::class, 'destroyImage']);

        Route::post('/product-categories/{productCategory}/image', [ProductCategoryController::class, 'uploadImage']);
        Route::delete('/product-categories/{productCategory}/image', [ProductCategoryController::class, 'destroyImage']);

        Route::get('/activity', [ActivityLogController::class, 'index']);
        // ── end media upload and activity log ───────────────────────────────

        Route::get('/reports/weekly', [ReportController::class, 'weekly']);
        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/yearly', [ReportController::class, 'yearly']);
        // "Aaj kitna kamaya?" and "paisa kahan hai?" — sales, COGS and margin
        // for the first, drawer vs udhaar vs stock-on-shelf for the second.
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/cash-position', [ReportController::class, 'cashPosition']);

        Route::post('/receipts/upload', [ReceiptController::class, 'upload']);
    });

    // Reading subscription state stays with the shop: it is how the app knows
    // whether it has access, how much trial is left, and which account details
    // to quote when asking the operator to renew. Nothing here can be bought
    // or cancelled, so there is no purchase surface to protect.
    Route::get('/billing/subscription', [BillingController::class, 'subscription']);

    // Buying, the hosted portal and cancellation are platform-operator work
    // now — shops are onboarded and renewed by hand, and a shop that reached
    // checkout could pay for a subscription nobody asked it to buy. Paddle
    // webhooks stay public and remain the only thing that moves provider state.
    Route::middleware('admin')->group(function () {
        Route::post('/billing/checkout', [BillingController::class, 'checkout']);
        Route::post('/billing/portal', [BillingController::class, 'portal']);
        Route::post('/billing/cancel', [BillingController::class, 'cancel']);
        Route::post('/billing/sandbox/complete/{payment}', [BillingController::class, 'completeTestPayment']);
    });

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{user}', [AdminController::class, 'userShow']);
        Route::put('/users/{user}', [AdminController::class, 'userUpdate']);
        Route::delete('/users/{user}', [AdminController::class, 'userDestroy']);

        Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
        // With self-serve checkout closed this is the only way a shop gets
        // access: subscriptionUpdate() can only edit a row that already exists.
        Route::post('/subscriptions', [AdminController::class, 'subscriptionStore']);
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
