<?php

namespace Infocyph\InterMix\Tests\Fixture;

class StripeGateway implements PaymentGateway
{
    public function pay(int $amount): string
    {
        return "stripe:$amount";
    }
}
