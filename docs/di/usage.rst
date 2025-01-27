.. _di.usage:

=====
Usage
=====

Below is a guide on how to **use** InterMix. The container implements the
`PSR-11 <http://www.php-fig.org/psr/psr-11/>`__ standard.

-----------------
Initialization
-----------------

Use the short-hand:

.. code-block:: php

   use function Infocyph\InterMix\container;

   $container = container();  // default alias

or:

.. code-block:: php

   $container = Infocyph\InterMix\DI\Container::instance('myAlias');
   // each alias is an isolated container

**By default**:

- **injection** is enabled,
- **methodAttributes** = false,
- **propertyAttributes** = false,
- **lazyLoading** = true.

You can **lock** the container after configuration:

.. code-block:: php

   $container->lock(); // no more modifications

To free it from memory:

.. code-block:: php

   $container->unset(); // removes from static registry

----------------------
PSR-11 get($id), has($id)
----------------------

**`get($id)`** returns the resolved service.
**`has($id)`** checks if the container *knows* about $id (either from functionReference or already resolved).

.. code-block:: php

   if ($container->has('myService')) {
       $service = $container->get('myService');
   }

If injection is enabled, InterMix tries reflection if `'myService'` is a class. Otherwise,
it might throw if it can't find that definition or auto-resolve.

---------------------------------
Definition Binding & Registration
---------------------------------

You can define entries:

.. code-block:: php

   $container->definitions()
       ->bind('foo', 'bar')
       ->bind(MyInterface::class, MyConcrete::class)
       ->addDefinitions([
           'db.host' => '127.0.0.1',
           'db.port' => '5432'
       ]);

Or register classes:

.. code-block:: php

   $container->registration()
       ->registerClass(MyService::class, [
           'someParam' => 'value'
       ])
       ->registerMethod(MyService::class, 'init', [
           'initParam' => 'initValue'
       ])
       ->registerProperty(MyService::class, [
           'propertyName' => 'propertyValue'
       ]);

**Everything** is stored in the container’s internal repository.

---------------------------------------
getReturn($id), call($classOrClosure)
---------------------------------------

- **`getReturn($id)`**: Similar to `get($id)` but returns the *method* output if a method was invoked.
- **`call($classOrClosure, $method = null)`**: Instantiates the class or closure with parameter injection.
  - For closures or global functions, it resolves parameters and calls them.
  - For classes, it calls the specified method.
  - Not always cached (the function call result isn’t stored unless it’s a class instance).

-----------
make($class)
-----------

**By default** InterMix caches the first resolved instance of a class. If you want a fresh instance:

.. code-block:: php

   $obj = $container->make(SomeClass::class);

No caching. If you pass a method:

.. code-block:: php

   $res = $container->make(SomeClass::class, 'doSomething');

it returns the result of `doSomething` on that new instance.

------------------------
setOptions(...) & Others
------------------------

You can set toggles:

.. code-block:: php

   $container->options()
       ->setOptions(
           injection: true,
           methodAttributes: true,
           propertyAttributes: true,
           defaultMethod: 'handle'
       )
       ->enableLazyLoading(true)
       ->setEnvironment('production');

**`injection`**: Switch between :php:class:`InjectedCall` (reflection) and
:php:class:`GenericCall` (no reflection).
**`methodAttributes`, `propertyAttributes`**: If you want the container to parse
:php:class:`Infuse` attributes.
**`defaultMethod`**: If no other method is found, call this one.
**`enableLazyLoading(true)`**: definitions are stored as a lazy placeholder if not user closures.
**`setEnvironment('production')`**: environment-based override checks.

-----------------------------------
Environment-Based Overrides Example
-----------------------------------

If you want an interface to map to different classes in each environment:

.. code-block:: php

   $container->options()
       ->bindInterfaceForEnv('production', GeoService::class, GoogleMaps::class)
       ->bindInterfaceForEnv('local', GeoService::class, OpenStreetMap::class);

Then:

.. code-block:: php

   $container->setEnvironment('production');
   $service = $container->get(StoreService::class); // gets GoogleMaps internally

**No** code changes in `StoreService`, just an environment setting.

-------------------------
Chaining Example
-------------------------

You can chain managers:

.. code-block:: php

   $container
       ->definitions()
           ->bind('db.host', '127.0.0.1')
           ->bind(MyInterface::class, MyConcrete::class)
           ->end()
       ->registration()
           ->registerClass(MyService::class, ['username' => 'alice'])
           ->registerMethod(MyService::class, 'init')
           ->end()
       ->options()
           ->setOptions(true, true, true, 'process')
           ->enableLazyLoading(true)
           ->bindInterfaceForEnv('production', SomeInterface::class, ProdImpl::class)
           ->end()
       ->invocation()
           ->call(MyService::class, 'bootstrap')
           ->end()
       ->lock();

- `.definitions()` => :php:class:`DefinitionManager`
- `.registration()` => :php:class:`RegistrationManager`
- `.options()` => :php:class:`OptionsManager`
- `.invocation()` => :php:class:`InvocationManager`
- `.end()` => returns the main container instance

The chain can be done in any order. This is a **fluent** approach.

-----------------
Lock & Unset
-----------------

Finally:

.. code-block:: php

   $container->lock();
   // no modifications

If you want to destroy that container alias:

.. code-block:: php

   $container->unset();

You can create a new container with the same alias afterward
(``$container = container(null, 'someAlias')`` => fresh instance).
