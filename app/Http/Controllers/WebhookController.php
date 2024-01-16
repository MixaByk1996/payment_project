<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\User;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function webhook(): array
    {
        $endpoint_sercet = env('STRIPE_WEBHOOK_SECRET');
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        return [
            'payload' => [$payload],
            'header' => [$_SERVER],
            'stripe-signature' => [$sig_header],
        ];
    }
}
