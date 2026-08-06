<?php

namespace App\Services\Pos;

use App\Models\PosRefund;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSyncService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function syncSale(User $user, array $payload): array
    {
        $existing = PosSale::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->with('lines')
            ->first();

        if ($existing) {
            return $this->salePayload($existing, true);
        }

        $duplicateClient = PosSale::query()
            ->where('user_id', $user->id)
            ->where('client_sale_id', $payload['client_sale_id'])
            ->first();

        if ($duplicateClient) {
            return $this->salePayload($duplicateClient, true);
        }

        return DB::transaction(function () use ($user, $payload) {
            $sale = PosSale::create([
                'user_id' => $user->id,
                'client_sale_id' => $payload['client_sale_id'],
                'idempotency_key' => $payload['idempotency_key'],
                'subtotal' => $payload['subtotal'],
                'tax' => $payload['tax'] ?? 0,
                'total' => $payload['total'],
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'status' => 'completed',
                'sold_at' => $payload['sold_at'],
                'sync_source' => $payload['sync_source'] ?? 'offline',
            ]);

            foreach ($payload['lines'] as $line) {
                PosSaleLine::create([
                    'pos_sale_id' => $sale->id,
                    'pos_product_id' => $line['pos_product_id'] ?? null,
                    'client_line_id' => $line['client_line_id'],
                    'product_name' => $line['product_name'],
                    'sku' => $line['sku'] ?? null,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $sale->load('lines');

            return $this->salePayload($sale, false);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function syncRefund(User $user, array $payload): array
    {
        $existing = PosRefund::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->with(['lines.saleLine', 'sale'])
            ->first();

        if ($existing) {
            return $this->refundPayload($existing, true);
        }

        $duplicateClient = PosRefund::query()
            ->where('user_id', $user->id)
            ->where('client_refund_id', $payload['client_refund_id'])
            ->first();

        if ($duplicateClient) {
            return $this->refundPayload($duplicateClient, true);
        }

        return DB::transaction(function () use ($user, $payload) {
            $sale = PosSale::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($payload) {
                    $q->where('id', $payload['pos_sale_id'] ?? 0)
                        ->orWhere('client_sale_id', $payload['client_sale_id'] ?? '');
                })
                ->with('lines')
                ->firstOrFail();

            $totalRefunded = 0;
            $refundLinesData = [];

            foreach ($payload['lines'] as $linePayload) {
                $saleLine = $sale->lines->first(function (PosSaleLine $line) use ($linePayload) {
                    return $line->client_line_id === $linePayload['client_line_id']
                        || $line->id === ($linePayload['pos_sale_line_id'] ?? null);
                });

                if (! $saleLine) {
                    throw ValidationException::withMessages([
                        'lines' => ['Sale line not found for refund.'],
                    ]);
                }

                $qty = (int) $linePayload['quantity_refunded'];
                if ($qty < 1 || $qty > $saleLine->refundableQuantity()) {
                    throw ValidationException::withMessages([
                        'lines' => ["Cannot refund {$qty} units of {$saleLine->product_name}."],
                    ]);
                }

                $amount = round((float) $linePayload['amount_refunded'], 2);
                $maxAmount = round($saleLine->unit_price * $qty, 2);
                if ($amount > $maxAmount + 0.01) {
                    throw ValidationException::withMessages([
                        'lines' => ["Refund amount exceeds line total for {$saleLine->product_name}."],
                    ]);
                }

                $totalRefunded += $amount;
                $refundLinesData[] = [
                    'sale_line' => $saleLine,
                    'quantity_refunded' => $qty,
                    'amount_refunded' => $amount,
                ];
            }

            $refund = PosRefund::create([
                'pos_sale_id' => $sale->id,
                'user_id' => $user->id,
                'client_refund_id' => $payload['client_refund_id'],
                'idempotency_key' => $payload['idempotency_key'],
                'total_refunded' => $totalRefunded,
                'reason' => $payload['reason'] ?? null,
                'refunded_at' => $payload['refunded_at'],
            ]);

            foreach ($refundLinesData as $lineData) {
                $refund->lines()->create([
                    'pos_sale_line_id' => $lineData['sale_line']->id,
                    'quantity_refunded' => $lineData['quantity_refunded'],
                    'amount_refunded' => $lineData['amount_refunded'],
                ]);

                $lineData['sale_line']->increment('quantity_refunded', $lineData['quantity_refunded']);
            }

            $sale->refresh()->load('lines');
            $fullyRefunded = $sale->lines->every(fn (PosSaleLine $l) => $l->quantity_refunded >= $l->quantity);
            $anyRefunded = $sale->lines->some(fn (PosSaleLine $l) => $l->quantity_refunded > 0);

            $sale->update([
                'status' => $fullyRefunded ? 'refunded' : ($anyRefunded ? 'partially_refunded' : 'completed'),
            ]);

            $refund->load(['lines.saleLine', 'sale']);

            return $this->refundPayload($refund, false);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function salePayload(PosSale $sale, bool $idempotentReplay): array
    {
        return [
            'idempotent_replay' => $idempotentReplay,
            'sale' => [
                'id' => $sale->id,
                'client_sale_id' => $sale->client_sale_id,
                'idempotency_key' => $sale->idempotency_key,
                'subtotal' => (float) $sale->subtotal,
                'tax' => (float) $sale->tax,
                'total' => (float) $sale->total,
                'payment_method' => $sale->payment_method,
                'status' => $sale->status,
                'sold_at' => $sale->sold_at?->toIso8601String(),
                'sync_source' => $sale->sync_source,
                'lines' => $sale->lines->map(fn (PosSaleLine $line) => [
                    'id' => $line->id,
                    'client_line_id' => $line->client_line_id,
                    'pos_product_id' => $line->pos_product_id,
                    'product_name' => $line->product_name,
                    'sku' => $line->sku,
                    'unit_price' => (float) $line->unit_price,
                    'quantity' => $line->quantity,
                    'quantity_refunded' => $line->quantity_refunded,
                    'line_total' => (float) $line->line_total,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refundPayload(PosRefund $refund, bool $idempotentReplay): array
    {
        return [
            'idempotent_replay' => $idempotentReplay,
            'refund' => [
                'id' => $refund->id,
                'client_refund_id' => $refund->client_refund_id,
                'idempotency_key' => $refund->idempotency_key,
                'pos_sale_id' => $refund->pos_sale_id,
                'client_sale_id' => $refund->sale?->client_sale_id,
                'total_refunded' => (float) $refund->total_refunded,
                'reason' => $refund->reason,
                'refunded_at' => $refund->refunded_at?->toIso8601String(),
                'lines' => $refund->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'pos_sale_line_id' => $line->pos_sale_line_id,
                    'client_line_id' => $line->saleLine?->client_line_id,
                    'quantity_refunded' => $line->quantity_refunded,
                    'amount_refunded' => (float) $line->amount_refunded,
                ])->values()->all(),
            ],
        ];
    }
}
