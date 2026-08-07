<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashEntry;
use App\Models\Sale;
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

    /** Sales, cost of goods sold, expenses and what is left. */
    public function profit(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        [$start, $end] = $this->resolvePeriod($request);

        return response()->json([
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            // Staff see their shop owner's takings, not their own empty ledger.
            ...$this->aggregator->profit($request->user()->dataOwnerId(), $start->toDateString(), $end->toDateString()),
        ]);
    }

    /** Drawer, udhaar and stock — the three places the money can be standing. */
    public function cashPosition(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        [$start, $end] = $this->resolvePeriod($request);

        return response()->json([
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            ...$this->aggregator->cashPosition($request->user(), $start->toDateString(), $end->toDateString()),
        ]);
    }

    /**
     * The period, in whichever shape the caller happened to ask in.
     *
     * The new screens are driven by a plain from/to picker, but the same
     * endpoints have to answer the weekly/monthly/yearly buttons the reports
     * page has always had — so all four shapes are accepted rather than forcing
     * the front end to convert a month into two dates and get the leap years
     * wrong. Nothing at all means this month, which is what an owner opening
     * the screen cold is asking about.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_with:from'],
            'start' => ['nullable', 'date'],
            // A month with no year is unanswerable; a year on its own is the
            // yearly report.
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100', 'required_with:month'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        if (! empty($validated['from'])) {
            return [
                Carbon::parse($validated['from'])->startOfDay(),
                Carbon::parse($validated['to'])->endOfDay(),
            ];
        }

        if (! empty($validated['start'])) {
            $week = Carbon::parse($validated['start'])->startOfWeek(Carbon::MONDAY);

            return [$week, $week->copy()->endOfWeek(Carbon::SUNDAY)];
        }

        if (! empty($validated['year'])) {
            $year = Carbon::create($validated['year'], $validated['month'] ?? 1, 1);

            return empty($validated['month'])
                ? [$year->copy()->startOfYear(), $year->copy()->endOfYear()]
                : [$year->copy()->startOfMonth(), $year->copy()->endOfMonth()];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
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
