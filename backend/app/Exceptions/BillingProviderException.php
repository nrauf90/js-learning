<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when the billing provider is unreachable, misconfigured, or rejects a
 * request. Renders as a clean 503 rather than leaking a stack trace or the
 * provider's raw error body — those can contain account identifiers.
 */
class BillingProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $publicMessage = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->publicMessage ?? 'Billing is temporarily unavailable. Please try again.',
            'code' => 'billing_provider_unavailable',
        ], 503);
    }
}
