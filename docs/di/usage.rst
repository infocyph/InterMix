.. _di.usage:

=====
Usage
=====

Simply, initialize using either of these lines,

.. code-block:: php

    $container = AbmmHasan\InterMix\container();
    $container = AbmmHasan\InterMix\DI\Container::instance();
    $container = new AbmmHasan\InterMix\DI\Container();

By default,

* **Autowiring** is enabled
* **Attribute** resolution is disabled
* No default method set

.. tip::

    You can get multiple ``container()`` instance completely isolated from each other. Simply send in an alias/identifier
    in 2nd parameter. Identifier makes sure to keep it isolated from another identifier.


The container have following options to play with as you see fit,

get(string $id) & has(string $id)
---------------------------------

The container implements the
`PSR-11 <http://www.php-fig.org/psr/psr-11/>`__ standard. That means it
implements
`Psr\\Container\\ContainerInterface <https://github.com/php-fig/container/blob/master/src/ContainerInterface.php>`__:

.. code:: php

   namespace Psr\Container;

   interface ContainerInterface
   {
       public function get($id);
       public function has($id);
   }

bind(string $id, mixed $definition) & addDefinitions(array $definitions)
------------------------------------------------------------------------

You can set entries directly on the container using either ``bind()``:

.. code:: php

    $container->bind('foo', 'bar');
    $container->bind('MyInterface', container('MyClass'));
    $container->bind('myClosure', function() { /* ... */ });

or ``addDefinitions()``

.. code:: php

    $container->addDefinitions([
        'definition 1' => 'class reference / closure / any mixed value',
        'foo' => 'bar',
        'MyInterface' => 'MyClass',
        'myClosure' => function() { /* ... */ }
    ]);

For details, see the :doc:`di.definitions`.

getReturn(string $id)
---------------------

``get()`` & ``getReturn()`` does the same except, ``getReturn()`` prioritize method returns where ``get()`` prioritizes
class object.

call(string|Closure|callable $classOrClosure, string|bool $method = null)
-------------------------------------------------------------------------

``get()`` & ``getReturn()`` both internally calls ``call()``. Differences are, ``get()`` & ``getReturn()`` results are
cached. In case of ``call()`` returns from method/closure are never cached (but class instances is cached as usual).

make(string $class, string|bool $method = false)
------------------------------------------------

This library only builds single instance per class (Singleton). But sometime we may need class instance independently.
``container()`` have 2nd parameter for completely different container instance for that. But what if we need only one
new instance for a class but other dependencies from cached? well, that is what ``make()`` is for! Also, as the definition
it supports class only.

registerClass(string $class, array $parameters = [])
----------------------------------------------------

Normally, this method won't be needed unless you need to send in some extra parameter to the constructor.

You don't need ``registerClass()`` for this

.. code:: php

    class GithubProfile
    {
        public function __construct(ApiClient $client)
        ...
    }

but you will need here if the variable ``$user`` is not defined via set()/addDefinitions()

.. code:: php

    class GithubProfile
    {
        public function __construct(ApiClient $client, $user)
        ...
    }

    // define as below
    $container->registerClass('GithubProfile', [
        'user' => 'some value'
    ]);

registerClosure(string $closureAlias, callable|Closure $function, array $parameters = [])
-----------------------------------------------------------------------------------------

Same as ``registerClass()`` but for Closure.

registerProperty(string $class, array $property), registerMethod(string $class, string $method, array $parameters = [])
-----------------------------------------------------------------------------------------------------------------------

While resolving through classes, container will look for any property value registered of that class (if **attribute** &
**property** resolutions is enabled) & will resolve it. During this if any custom property value is defined with
``registerProperty()`` it will resolve it as well.

Register property by class,

.. code:: php

    $container->registerProperty('GithubProfile', [
        'someProperty' => 'some value'
    ]);

Container will look for any method registered with ``registerMethod()`` & will resolve it. Even if it is not registered,
container still may resolve some method, check the container lifecycle for details.

register parameter in a method (also is default method to resolve for that class)

.. code:: php

    $container->registerMethod('GithubProfile', 'aMethod', [
        'user' => 'some value'
    ]);

setOptions(bool $injection = true, bool $methodAttributes = false, bool $propertyAttributes = false, string $defaultMethod = null)
----------------------------------------------------------------------------------------------------------------------------------

Well, as you have seen above, the container provides lots of options. Obviously you can enable/disable them as your requirements.
Available options are,

* ``injection``: Enable/disable dependency injection (Enabled by default)
* ``methodAttributes``: Enable/disable attribute resolution on method
* ``propertyAttributes``: Enable/disable attribute resolution on property
* ``defaultMethod``: Set a default method to be called if method is not set already

.. attention::

    Defaults are; ``injection`` is enabled, rests are disabled. If ``injection`` is disabled rest of the options won't work.

split(string|array|Closure|callable $classAndMethod)
----------------------------------------------------

Breakdown any recognizable formation to a recognizable callable format ``['class', 'method']`` or ``['closure']``. Will
be called automatically if 1st parameter in ``container()`` function is passed.
Applicable formats are,

* ``class@method``
* ``class::method``
* ``closure()``
* ``['class', 'method']``
* ``['class']``

lock()
------

Once this method is called, you won't be able to modify the options or add anything to the class.

.. code:: php

    $container->lock();

unset()
-------

Once container is created it can be chained/piped through (to add/edit method/property/options) till the process die.
But once **unset()** is called, no more chaining. Calling back will just simply initiate new container instance.

.. code:: php

    $container->unset();