.. _di.attribute:

===================
Attribute Injection
===================

InterMix provides one canonical injection attribute: ``#[Inject]``. It supports
properties, parameters, and whole-method defaults without maintaining synonymous
public APIs.

Built-in Injection
==================

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\Inject;

   final class Service
   {
       #[Inject]
       private LoggerInterface $logger;

       #[Inject('cfg.debug')]
       private bool $debug;

       #[Inject(strtotime: '+1 day')]
       private int $expires;

       #[Inject(retries: 2)]
       public function run(
           int $retries,
           #[Inject('cfg.env')] string $environment,
       ): void {}
   }

``#[Inject]`` without arguments resolves by type. A positional string selects a
container definition, function, class, or interface. Named arguments provide
whole-method defaults or function arguments.

Enable only the resolution surfaces the application uses:

.. code-block:: php

   $container->options()->setOptions(
       injection: true,
       methodAttributes: true,
       propertyAttributes: true,
   );

Explicit values registered through ``registerClass()``, ``registerMethod()``, or
``registerProperty()`` take priority over attribute values.

Custom Attributes
=================

Custom resolvers implement ``AttributeResolverInterface`` and are registered at
bootstrap.

.. code-block:: php

   use Infocyph\InterMix\DI\Attribute\AttributeResolution;
   use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;

   #[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
   final readonly class UpperCase
   {
       public function __construct(public string $text) {}
   }

   final class UpperCaseResolver implements AttributeResolverInterface
   {
       public function resolve(
           object $attribute,
           Reflector $target,
           Container $container,
       ): mixed {
           return strtoupper($attribute->text);
       }
   }

   $container->attributeRegistry()->register(
       UpperCase::class,
       UpperCaseResolver::class,
   );

A resolver may inject any value, including ``null``. It returns
``AttributeResolution::Unresolved`` only when it declines to provide a value:

.. code-block:: php

   public function resolve(
       object $attribute,
       Reflector $target,
       Container $container,
   ): mixed {
       $container->get('logger')->notice($target->getName());

       return AttributeResolution::Unresolved;
   }

For multiple registered attributes on one target, the first value other than the
unresolved sentinel is injected. Registered resolvers may still perform explicit
logic before declining.

Resolution Priority
===================

1. Values registered for the class, method, or property
2. Arguments supplied to ``call()`` or ``make()``
3. Container definitions
4. ``#[Inject]``
5. Registered custom attribute resolvers

The lazy initializer used internally by the container is not an attribute or a
supported application API.

Next → :ref:`di.lifetimes`
