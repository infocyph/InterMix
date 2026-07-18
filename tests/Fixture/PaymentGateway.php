<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

interface PaymentGateway
{
    public function pay(int $amount): string;
}
