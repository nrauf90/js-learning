<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Coming soon'], 501);
    }
}
