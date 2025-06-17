<?php
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\PaymentGateway;
use Infocyph\InterMix\Tests\Fixture\StripeGateway;

it('switches concrete by environment', function () {
    $c = Container::instance('intermix')
        ->options()->setEnvironment('prod')
        ->registration()->end();

    $c->options()->bindInterfaceForEnv('prod', PaymentGateway::class, StripeGateway::class);
    $c->options()->bindInterfaceForEnv('test', PaymentGateway::class, PaypalGateway::class);

    $gw = $c->make(PaymentGateway::class);

    expect($gw)->toBeInstanceOf(StripeGateway::class)
        ->and($gw->pay(10))->toBe('stripe:10');
});
