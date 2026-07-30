<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QaController extends Controller
{
    /** Local/testing only — backdate account so trial is expired (E2E). */
    public function expireTrial(Request $request): JsonResponse
    {
        $days = max(1, (int) config('billing.trial_days', 7));

        $request->user()->forceFill([
            'created_at' => now()->subDays($days + 1),
        ])->save();

        return response()->json(['ok' => true]);
    }
}
