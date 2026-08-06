<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosRefundController extends Controller
{
    public function __construct(private readonly PosSyncService $sync) {}

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refunds' => ['required', 'array', 'min:1', 'max:50'],
            'refunds.*.client_refund_id' => ['required', 'uuid'],
            'refunds.*.idempotency_key' => ['required', 'string', 'max:64'],
            'refunds.*.client_sale_id' => ['required_without:refunds.*.pos_sale_id', 'uuid'],
            'refunds.*.pos_sale_id' => ['nullable', 'integer'],
            'refunds.*.reason' => ['nullable', 'string', 'max:500'],
            'refunds.*.refunded_at' => ['required', 'date'],
            'refunds.*.lines' => ['required', 'array', 'min:1'],
            'refunds.*.lines.*.client_line_id' => ['required_without:refunds.*.lines.*.pos_sale_line_id', 'uuid'],
            'refunds.*.lines.*.pos_sale_line_id' => ['nullable', 'integer'],
            'refunds.*.lines.*.quantity_refunded' => ['required', 'integer', 'min:1'],
            'refunds.*.lines.*.amount_refunded' => ['required', 'numeric', 'gte:0'],
        ]);

        $results = [];
        foreach ($validated['refunds'] as $refundPayload) {
            $results[] = $this->sync->syncRefund($request->user(), $refundPayload);
        }

        return response()->json(['results' => $results]);
    }
}
