<?php

namespace Infocyph\InterMix\Tests\Fixture;

interface PaymentGateway
{
    public function pay(int $amount): string;
}
