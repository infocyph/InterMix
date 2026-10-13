.. _di.invocation:

================================================
Runtime invocation
================================================

Runtime invocation is explicit in InterMix 11.

make()
------

make() constructs a fresh class instance and accepts positional or named constructor arguments.

.. code-block:: php

   $job = $runtime->make(Job::class, ['name' => 'nightly']);

invoke()
--------

invoke() executes a real PHP callable and resolves missing typed parameters through the runtime.

.. code-block:: php

   $handler = $runtime->make(Handler::class);

   $result = $runtime->invoke(
       [$handler, 'handle'],
       ['payload' => $payload],
   );

Functions, closures, invokable objects, and valid callable arrays are supported. String descriptor parsing and implicit default methods are not part of the InterMix 11 runtime contract.

Supplied parameter values
-------------------------

Named arguments match the PHP parameter name directly, without a dollar sign:

.. code-block:: php

   $legend = $runtime->invoke(
       static fn (int $legend): int => $legend,
       ['legend' => 32],
   );
   // $legend === 32

Supplied arguments take precedence over automatic resolution. Key presence is
checked independently of the value, so zero, false and nullable null are supplied
values. Matching a name does not convert its value into an object; class conversion
requires the explicit auto-binding policy below. Without a supplied argument,
compatible registered values can also resolve by parameter name.

Automatic parameter binding
---------------------------

Enable conversion explicitly when a class parameter should receive an object derived
from a supplied value, such as a model identifier:

.. code-block:: php

   use Infocyph\InterMix\DI\ContainerBuilder;

   final readonly class Logger
   {
       public function __construct(public int $id) {}

       public static function fromBoundValue(mixed $value): static
       {
           if (!is_int($value)) {
               throw new InvalidArgumentException('An integer logger ID is required.');
           }

           return new self($value);
       }
   }

   $builder = new ContainerBuilder();
   $builder->enableAutoBind()->value('logger', 32);
   $runtime = $builder->build();
   $logger = $runtime->invoke(static fn(Logger $logger): Logger => $logger);
   // $logger->id === 32

The same conversion applies to named and positional ``make()`` and ``invoke()``
arguments, registered parameter values, and declared scope inputs. The method must
be public, static, accept exactly one argument by value, and return an instance of
the declared parameter class. Compatible objects and nullable ``null`` values pass
through unchanged. Classes without this method retain ordinary injection behavior.
Union, intersection and variadic parameters retain ordinary type resolution.

Conversion errors propagate to the caller. InterMix does not choose a database,
perform implicit lookups, or translate exceptions into HTTP responses; the class
owns conversion and the host application owns error handling. No conversion result
is cached separately from the receiving service's declared lifetime.

For execution-owned values, declare ``input('logger')`` and supply the identifier
through ``withinScope()`` seeds. Services depending on that input must be scoped or
transient. Native compilation preserves the conversion contract without requiring
a fallback graph solely for auto-binding. Recompile artifacts for ABI 3.

PSR-11
------

Use get() for configured/resolvable services and has() for the PSR-11 resolvability check.
