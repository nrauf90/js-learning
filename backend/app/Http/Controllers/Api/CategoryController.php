<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'in:income,expense'],
        ]);

        // Selectable only: this endpoint feeds the manual entry picker, and the
        // day book's own categories must not be choosable by hand.
        $query = ExpenseCategory::query()->selectable()->orderBy('name');

        if (! empty($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        $categories = $query->get(['id', 'name', 'slug', 'kind', 'icon', 'is_system']);

        return response()->json(['categories' => $categories]);
    }
}
