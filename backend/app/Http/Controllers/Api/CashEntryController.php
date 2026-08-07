<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashEntryController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashEntry::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = CashEntry::query()->where('user_id', $request->user()->id);

        if (! empty($validated['date'])) {
            $base->whereDate('entry_date', $validated['date']);
        }

        $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        $paginator = (clone $base)
            ->with('category:id,name,slug')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'entries' => collect($paginator->items())->map(fn (CashEntry $e) => $this->payload($e)),
            // Summed over every matching row, not over the page: the day's
            // income, expense and net are the figures the screen is opened for,
            // and adding up only what fits on one page would quietly understate
            // a busy day.
            'totals' => $this->totals(clone $base),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CashEntry::class);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
            'entry_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertCategoryMatchesType($validated['category_id'], $validated['type']);

        $entry = CashEntry::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        $entry->load('category:id,name,slug');

        return response()->json(['entry' => $this->payload($entry)], 201);
    }

    public function update(Request $request, CashEntry $cashEntry): JsonResponse
    {
        $this->authorize('update', $cashEntry);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:expense_categories,id'],
            'type' => ['sometimes', 'in:income,expense'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999999.99'],
            'entry_date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $type = $validated['type'] ?? $cashEntry->type;
        $categoryId = $validated['category_id'] ?? $cashEntry->category_id;
        $this->assertCategoryMatchesType($categoryId, $type);

        $cashEntry->update($validated);
        $cashEntry->load('category:id,name,slug');

        return response()->json(['entry' => $this->payload($cashEntry)]);
    }

    public function destroy(CashEntry $cashEntry): JsonResponse
    {
        $this->authorize('delete', $cashEntry);
        $cashEntry->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * @param  Builder<CashEntry>  $query
     * @return array{income: float, expense: float, net: float}
     */
    private function totals(Builder $query): array
    {
        $sums = $query->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = round((float) ($sums['income'] ?? 0), 2);
        $expense = round((float) ($sums['expense'] ?? 0), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => round($income - $expense, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CashEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'category_id' => $entry->category_id,
            'category' => $entry->category
                ? [
                    'id' => $entry->category->id,
                    'name' => $entry->category->name,
                    'slug' => $entry->category->slug,
                ]
                : null,
            'type' => $entry->type,
            'amount' => (float) $entry->amount,
            'entry_date' => $entry->entry_date?->format('Y-m-d'),
            'note' => $entry->note,
            'receipt_path' => $entry->receipt_path,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    private function assertCategoryMatchesType(int $categoryId, string $type): void
    {
        $category = ExpenseCategory::query()->findOrFail($categoryId);

        if ($category->kind !== $type) {
            throw ValidationException::withMessages([
                'category_id' => ['Category does not match entry type (income vs expense).'],
            ]);
        }
    }
}
