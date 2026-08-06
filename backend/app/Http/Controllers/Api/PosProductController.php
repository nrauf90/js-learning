<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosProduct;
use App\Models\PosSale;
use App\Services\Pos\PosSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = PosProduct::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'price']);

        return response()->json([
            'products' => $products->map(fn (PosProduct $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'price' => (float) $p->price,
            ]),
        ]);
    }
}
