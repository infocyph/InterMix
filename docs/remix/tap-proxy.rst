.. _remix.tap-proxy:

========================
Tap Proxy (`TapProxy`)
========================

`Infocyph\InterMix\Remix\TapProxy` is the “engine” behind the zero‐argument
`tap()` call.  Whenever you write:

.. code-block:: php

   $someObject->tap()->foo()->bar()->baz();

this actually creates a `TapProxy($someObject)`. Then each chained method
(`foo()`, `bar()`, `baz()`) is invoked on the real target, **but the proxy
always returns the original target** (not the proxy). That means your chain
never breaks, and you never have to assign the result manually.

**Class definition:**

```php
namespace Infocyph\InterMix\Remix;

class TapProxy
{
    public function __construct(public mixed $target) {}

    public function __call(string $method, array $parameters)
    {
        // Forward the method call to the real object...
        $this->target->{$method}(...$parameters);
        // …then return the original object for further chaining
        return $this->target;
    }
}
````

**Global helper function `tap()`:**

```php
if (!function_exists('tap')) {
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if (is_null($callback)) {
            // No callback? Return a TapProxy to let you chain methods.
            return new TapProxy($value);
        }
        // With callback: invoke it and then return the original value.
        $callback($value);
        return $value;
    }
}
```

**Usage examples:**

```php
// 1) With a callback: let me “observe” $user and still return $user.
$user = tap($user, fn($u) => logger()->info("User id={$u->id}"));

// 2) Proxy method chaining: call methods but keep $user at end.
tap($user)
    ->setName('Alice')
    ->activate()
    ->sendWelcomeEmail();
```
