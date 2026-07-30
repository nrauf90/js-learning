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

        $query = ExpenseCategory::query()->orderBy('name');

        if (! empty($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        $categories = $query->get(['id', 'name', 'slug', 'kind', 'icon', 'is_system']);

        return response()->json(['categories' => $categories]);
    }
}
