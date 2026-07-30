<?php

namespace App\Services\Billing;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use InvalidArgumentException;

class GatewayResolver
{
    public function resolve(string $provider): PaymentGateway
    {
        return match ($provider) {
            'jazzcash' => app(JazzCashGateway::class),
            'easypaisa' => app(EasyPaisaGateway::class),
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}
