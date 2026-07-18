<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

class PaypalGateway implements PaymentGateway
{
    public function pay(int $amount): string
    {
        return "paypal:$amount";
    }
}
