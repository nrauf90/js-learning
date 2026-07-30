<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashEntry::class);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $query = CashEntry::query()
            ->with('category:id,name,slug')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if (! empty($validated['date'])) {
            $query->whereDate('entry_date', $validated['date']);
        }

        $entries = $query->get();

        return response()->json(['entries' => $entries->map(fn (CashEntry $e) => $this->payload($e))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CashEntry::class);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'gt:0'],
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
            'amount' => ['sometimes', 'numeric', 'gt:0'],
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
