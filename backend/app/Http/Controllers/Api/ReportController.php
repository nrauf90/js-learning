<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Services\ReportAggregator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportAggregator $aggregator) {}

    public function weekly(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashEntry::class);

        $validated = $request->validate([
            'start' => ['required', 'date'],
        ]);

        $start = Carbon::parse($validated['start'])->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        return $this->jsonReport($request, $start, $end);
    }

    public function monthly(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashEntry::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $start = Carbon::create($validated['year'], $validated['month'], 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->jsonReport($request, $start, $end);
    }

    public function yearly(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CashEntry::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $start = Carbon::create($validated['year'], 1, 1)->startOfYear();
        $end = $start->copy()->endOfYear();

        return $this->jsonReport($request, $start, $end);
    }

    private function jsonReport(Request $request, Carbon $start, Carbon $end): JsonResponse
    {
        $report = $this->aggregator->aggregate(
            $request->user()->id,
            $start->toDateString(),
            $end->toDateString()
        );

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            ...$report,
        ]);
    }
}
