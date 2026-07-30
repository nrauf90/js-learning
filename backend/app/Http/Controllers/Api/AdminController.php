<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    /**
     * Admin dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $totalUsers = User::count();
        $activeSubscriptions = Subscription::where('status', 'active')
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

        $perPage = $request->input('per_page', 20);
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

        $user->update($request->only(['name', 'email', 'is_admin']));

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

        $perPage = $request->input('per_page', 20);
        $subscriptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($subscriptions);
    }

    /**
     * Update subscription
     */
    public function subscriptionUpdate(Request $request, Subscription $subscription)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:active,cancelled,expired',
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

        $perPage = $request->input('per_page', 20);
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

        $perPage = $request->input('per_page', 20);
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
