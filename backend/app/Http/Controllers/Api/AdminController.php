<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    /** Hard cap on `per_page` so a huge value can't be used for a DoS-y page load. */
    private const MAX_PER_PAGE = 100;

    private function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->input('per_page', $default)));
    }

    /**
     * Admin dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $totalUsers = User::count();
        $activeSubscriptions = Subscription::whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>', now())
            ->count();

        $monthlyRevenue = Payment::where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $totalEntries = CashEntry::count();

        $recentUsers = User::orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'email', 'created_at', 'is_admin']);

        $recentPayments = Payment::with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'active_subscriptions' => $activeSubscriptions,
                'monthly_revenue' => $monthlyRevenue,
                'currency' => config('billing.currency', 'USD'),
                'total_entries' => $totalEntries,
            ],
            'recent_users' => $recentUsers,
            'recent_payments' => $recentPayments,
        ]);
    }

    /**
     * List all users with search and filters
     */
    public function users(Request $request)
    {
        $query = User::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('admin_only') && $request->input('admin_only') === 'true') {
            $query->where('is_admin', true);
        }

        $perPage = $this->perPage($request);
        $users = $query->withCount(['cashEntries', 'subscriptions', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Get single user details with related data
     */
    public function userShow(User $user)
    {
        $user->load([
            'subscriptions' => fn($q) => $q->orderBy('created_at', 'desc')->limit(5),
            'payments' => fn($q) => $q->orderBy('created_at', 'desc')->limit(10),
        ]);

        $user->loadCount(['cashEntries', 'subscriptions', 'payments']);

        $entriesStats = CashEntry::where('user_id', $user->id)
            ->selectRaw('type, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return response()->json([
            'user' => $user,
            'entries_stats' => $entriesStats,
        ]);
    }

    /**
     * Update user details
     */
    public function userUpdate(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'is_admin' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Prevent user from demoting themselves
        if ($request->has('is_admin') && $user->id === $request->user()->id && !$request->input('is_admin')) {
            return response()->json(['error' => 'Cannot remove admin privileges from yourself'], 422);
        }

        $user->fill($request->only(['name', 'email']));

        // is_admin is intentionally not mass-assignable (see User::$fillable);
        // set it explicitly here, the one place it's allowed to change.
        if ($request->has('is_admin')) {
            $user->is_admin = $request->boolean('is_admin');
        }

        $user->save();

        return response()->json(['user' => $user]);
    }

    /**
     * Delete user
     */
    public function userDestroy(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'Cannot delete yourself'], 422);
        }

        DB::transaction(function () use ($user) {
            $user->cashEntries()->delete();
            $user->subscriptionAddons()->delete();
            $user->subscriptions()->delete();
            $user->payments()->delete();
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'User deleted successfully']);
    }

    /**
     * List shop admins with the shop each one runs.
     *
     * Paginated in the same shape as users() so the admin UI can reuse its
     * pagination renderer.
     */
    public function shopAdmins(Request $request)
    {
        // is_admin is what makes an account a platform operator; `role` on
        // those rows is just the column default, so they are excluded here
        // rather than showing up as customers of the platform.
        $query = User::query()
            ->where('role', User::ROLE_SHOP_ADMIN)
            ->where('is_admin', false)
            ->with('ownedShop');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('ownedShop', fn ($shop) => $shop->where('name', 'like', "%{$search}%"));
            });
        }

        $shopAdmins = $query->orderBy('created_at', 'desc')->paginate($this->perPage($request));

        // One grouped count for the whole page rather than a count query per
        // row — this list is the platform's customer list and only grows.
        $staffCounts = User::query()
            ->whereIn('shop_id', $shopAdmins->pluck('ownedShop.id')->filter()->all())
            ->where('role', User::ROLE_STAFF)
            ->selectRaw('shop_id, COUNT(*) as staff_total')
            ->groupBy('shop_id')
            ->pluck('staff_total', 'shop_id');

        return response()->json($shopAdmins->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at,
            'shop' => $user->ownedShop ? [
                'id' => $user->ownedShop->id,
                'name' => $user->ownedShop->name,
                'phone' => $user->ownedShop->phone,
                'address' => $user->ownedShop->address,
            ] : null,
            'staff_count' => (int) ($staffCounts[$user->ownedShop?->id] ?? 0),
        ]));
    }

    /**
     * Onboard a shop: the owner's login and the shop it runs, in one call.
     *
     * The two halves are useless apart — an owner with no shop cannot add
     * staff, a shop with no owner has nobody to run it — so they share a
     * transaction.
     */
    public function shopAdminStore(Request $request)
    {
        // Password rules come from AuthController::register via
        // Password::defaults(); an account created here logs in through the
        // same endpoint as one that signed itself up.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'shop_name' => ['required', 'string', 'max:160'],
            'shop_phone' => ['nullable', 'string', 'max:32'],
            'shop_address' => ['nullable', 'string', 'max:255'],
        ]);

        [$owner, $shop] = DB::transaction(function () use ($validated) {
            // Only name/email/password are mass-assigned. is_admin stays out of
            // reach entirely — this endpoint creates shop owners, never another
            // platform operator, however the request is shaped.
            $owner = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $shop = Shop::create([
                'owner_id' => $owner->id,
                'name' => $validated['shop_name'],
                'phone' => $validated['shop_phone'] ?? null,
                'address' => $validated['shop_address'] ?? null,
            ]);

            $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

            return [$owner->fresh(), $shop];
        });

        return response()->json([
            'user' => $owner->toAuthArray(),
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'phone' => $shop->phone,
                'address' => $shop->address,
            ],
        ], 201);
    }

    /**
     * List all subscriptions
     */
    public function subscriptions(Request $request)
    {
        $query = Subscription::with('user:id,name,email');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('plan')) {
            $query->where('plan', $request->input('plan'));
        }

        $perPage = $this->perPage($request);
        $subscriptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($subscriptions);
    }

    /**
     * Grant a shop its subscription, or extend the one it has.
     *
     * Shops no longer buy for themselves, and subscriptionUpdate() can only
     * edit a row that already exists — a shop onboarded through
     * shopAdminStore() has none, which left every new shop stuck on the trial
     * with no way off it.
     */
    public function subscriptionStore(Request $request, SubscriptionService $subscriptions)
    {
        $validated = $request->validate([
            'user_id' => ['required_without:email', 'integer', 'exists:users,id'],
            'email' => ['required_without:user_id', 'email', 'exists:users,email'],
            'plan' => ['required', 'in:monthly,yearly'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $user = isset($validated['user_id'])
            ? User::findOrFail($validated['user_id'])
            : User::where('email', $validated['email'])->firstOrFail();

        $existing = $subscriptions->currentSubscription($user);

        // Extend from whatever the shop still has left rather than from today,
        // so renewing a few days early never throws away days already granted.
        $base = $existing && $existing->ends_at?->isFuture() ? $existing->ends_at->copy() : now();
        // An explicit date means "through the end of that day". Taking it bare
        // would cut the shop off at midnight — a day earlier than the operator
        // picked, and the sort of thing nobody notices until the till locks.
        $endsAt = isset($validated['ends_at'])
            ? Carbon::parse($validated['ends_at'])->endOfDay()
            : $this->planEndsAt($base, $validated['plan']);

        if ($existing) {
            $existing->update([
                'plan' => $validated['plan'],
                'status' => 'active',
                'ends_at' => $endsAt,
                'renews_at' => $endsAt,
            ]);

            return response()->json(['subscription' => $existing->fresh()], 201);
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            // No external_id: nothing at Paddle backs a hand-granted
            // subscription, and that absence is what keeps the portal and
            // cancel controls off it.
            'provider' => 'manual',
            'plan' => $validated['plan'],
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'renews_at' => $endsAt,
        ]);

        return response()->json(['subscription' => $subscription], 201);
    }

    private function planEndsAt(Carbon $from, string $plan): Carbon
    {
        $config = config("billing.plans.{$plan}");
        $count = max(1, (int) ($config['interval_count'] ?? 1));

        return ($config['interval'] ?? 'month') === 'year'
            ? $from->copy()->addYears($count)
            : $from->copy()->addMonths($count);
    }

    /**
     * Update subscription
     */
    public function subscriptionUpdate(Request $request, Subscription $subscription)
    {
        // Matches the statuses Paddle actually reports (note the single-l
        // "canceled" — the old value here was "cancelled", which no webhook
        // ever writes, so an admin edit could not round-trip a real status).
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:active,trialing,past_due,paused,canceled,expired',
            'ends_at' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subscription->update($request->only(['status', 'ends_at']));

        return response()->json(['subscription' => $subscription]);
    }

    /**
     * List all cash entries
     */
    public function cashEntries(Request $request)
    {
        $query = CashEntry::with('user:id,name,email', 'category:id,name');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('date_from')) {
            $query->where('entry_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('entry_date', '<=', $request->input('date_to'));
        }

        $perPage = $this->perPage($request);
        $entries = $query->orderBy('entry_date', 'desc')->paginate($perPage);

        return response()->json($entries);
    }

    /**
     * Delete cash entry
     */
    public function cashEntryDestroy(CashEntry $cashEntry)
    {
        $cashEntry->delete();

        return response()->json(['message' => 'Cash entry deleted successfully']);
    }

    /**
     * List all payments
     */
    public function payments(Request $request)
    {
        $query = Payment::with('user:id,name,email', 'subscription:id,plan');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('gateway')) {
            $query->where('provider', $request->input('gateway'));
        }

        $perPage = $this->perPage($request);
        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($payments);
    }

    /**
     * List all categories
     */
    public function categories()
    {
        $categories = ExpenseCategory::withCount('cashEntries')
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    /**
     * Create category
     */
    public function categoryStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:expense_categories,name',
            'kind' => 'required|in:income,expense',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = ExpenseCategory::create([
            'name' => $request->name,
            'kind' => $request->kind,
            'slug' => \Illuminate\Support\Str::slug($request->name),
        ]);

        return response()->json(['category' => $category], 201);
    }

    /**
     * Update category
     */
    public function categoryUpdate(Request $request, ExpenseCategory $category)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:expense_categories,name,' . $category->id,
            'kind' => 'sometimes|in:income,expense',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category->update($request->only(['name', 'kind']));

        if ($request->has('name')) {
            $category->slug = \Illuminate\Support\Str::slug($request->name);
            $category->save();
        }

        return response()->json(['category' => $category]);
    }

    /**
     * Delete category
     */
    public function categoryDestroy(ExpenseCategory $category)
    {
        // Check if category is in use
        $entriesCount = $category->cashEntries()->count();

        if ($entriesCount > 0) {
            return response()->json([
                'error' => "Cannot delete category. It is used by {$entriesCount} cash entries."
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }
}
