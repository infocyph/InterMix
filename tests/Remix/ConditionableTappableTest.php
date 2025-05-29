<?php

use Infocyph\InterMix\Remix\ConditionableTappable;

/* Dummy model ---------------------------------------------------- */

class UserStub
{
    use ConditionableTappable;

    public bool $active = false;
    public string $status = '';

    public function activate(): static
    {
        $this->active = true;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}

/* direct when() / unless() -------------------------------------- */
it('when() executes only on truthy', function () {
    $u = new UserStub();
    $u->when(true, fn ($m) => $m->activate());
    expect($u->active)->toBeTrue();
});

it('unless() executes only on falsy', function () {
    $u = new UserStub();
    $u->unless(false, fn ($m) => $m->activate());
    expect($u->active)->toBeTrue();
});

/* zero-argument proxy capture ------------------------------------ */
it('proxy captures property for condition', function () {
    $u = new UserStub();
    $u->active = true;
    $u->when()->active->activate();     // executes, property == truthy
    expect($u->active)->toBeTrue();
});

it('proxy captures method for condition', function () {
    $u = new UserStub();
    $u->when()->isActive()->status = 'ok';
    expect($u->status)->toBe('');       // isActive()==false so branch skipped
});
