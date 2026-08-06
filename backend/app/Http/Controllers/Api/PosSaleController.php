<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Services\Pos\PosSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSaleController extends Controller
{
    public function __construct(private readonly PosSyncService $sync) {}

    public function index(Request $request): JsonResponse
    {
        $sales = PosSale::query()
            ->where('user_id', $request->user()->id)
            ->with('lines')
            ->orderByDesc('sold_at')
            ->limit(50)
            ->get();

        return response()->json([
            'sales' => $sales->map(fn (PosSale $sale) => $this->sync->salePayload($sale, false)['sale']),
        ]);
    }

    public function show(Request $request, PosSale $posSale): JsonResponse
    {
        abort_unless($posSale->user_id === $request->user()->id, 403);
        $posSale->load('lines', 'refunds.lines');

        return response()->json($this->sync->salePayload($posSale, false));
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:50'],
            'sales.*.client_sale_id' => ['required', 'uuid'],
            'sales.*.idempotency_key' => ['required', 'string', 'max:64'],
            'sales.*.subtotal' => ['required', 'numeric', 'gte:0'],
            'sales.*.tax' => ['nullable', 'numeric', 'gte:0'],
            'sales.*.total' => ['required', 'numeric', 'gte:0'],
            'sales.*.payment_method' => ['nullable', 'string', 'max:32'],
            'sales.*.sold_at' => ['required', 'date'],
            'sales.*.sync_source' => ['nullable', 'in:online,offline'],
            'sales.*.lines' => ['required', 'array', 'min:1'],
            'sales.*.lines.*.client_line_id' => ['required', 'uuid'],
            'sales.*.lines.*.pos_product_id' => ['nullable', 'integer', 'exists:pos_products,id'],
            'sales.*.lines.*.product_name' => ['required', 'string', 'max:255'],
            'sales.*.lines.*.sku' => ['nullable', 'string', 'max:64'],
            'sales.*.lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'sales.*.lines.*.quantity' => ['required', 'integer', 'min:1'],
            'sales.*.lines.*.line_total' => ['required', 'numeric', 'gte:0'],
        ]);

        $results = [];
        foreach ($validated['sales'] as $salePayload) {
            $results[] = $this->sync->syncSale($request->user(), $salePayload);
        }

        return response()->json(['results' => $results]);
    }
}
