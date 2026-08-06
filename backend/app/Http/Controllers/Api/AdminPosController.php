<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosProduct;
use App\Models\PosRefund;
use App\Models\PosSale;
use App\Services\Pos\PosSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminPosController extends Controller
{
    public function __construct(private readonly PosSyncService $sync) {}

    public function dashboard(): JsonResponse
    {
        $todaySales = PosSale::whereDate('sold_at', today())->sum('total');
        $todayRefunds = PosRefund::whereDate('refunded_at', today())->sum('total_refunded');
        $totalSales = PosSale::count();
        $activeProducts = PosProduct::where('is_active', true)->count();

        $recentSales = PosSale::with(['user:id,name,email', 'lines'])
            ->orderByDesc('sold_at')
            ->limit(10)
            ->get();

        return response()->json([
            'stats' => [
                'today_sales' => (float) $todaySales,
                'today_refunds' => (float) $todayRefunds,
                'today_net' => (float) $todaySales - (float) $todayRefunds,
                'total_sales' => $totalSales,
                'active_products' => $activeProducts,
            ],
            'recent_sales' => $recentSales->map(fn (PosSale $s) => $this->sync->salePayload($s, false)['sale']),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = PosProduct::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(['products' => $query->get()]);
    }

    public function productStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:pos_products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'gte:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product = PosProduct::create($validated);

        return response()->json(['product' => $product], 201);
    }

    public function productUpdate(Request $request, PosProduct $posProduct): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['sometimes', 'string', 'max:64', 'unique:pos_products,sku,'.$posProduct->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'gte:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $posProduct->update($validated);

        return response()->json(['product' => $posProduct->fresh()]);
    }

    public function productDestroy(PosProduct $posProduct): JsonResponse
    {
        $posProduct->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function sales(Request $request): JsonResponse
    {
        $query = PosSale::with(['user:id,name,email', 'lines'])
            ->orderByDesc('sold_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $sales = $query->paginate($request->input('per_page', 20));

        return response()->json($sales);
    }

    public function saleShow(PosSale $posSale): JsonResponse
    {
        $posSale->load(['user:id,name,email', 'lines', 'refunds.lines']);

        return response()->json($this->sync->salePayload($posSale, false));
    }

    public function refunds(Request $request): JsonResponse
    {
        $refunds = PosRefund::with(['user:id,name,email', 'sale', 'lines.saleLine'])
            ->orderByDesc('refunded_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($refunds);
    }
}
