.. _container:

=================
Macro Mix (Mixin)
=================

Have you ever wished that a PHP class had another method on it that you’d like to use? ``MacroMix`` makes this dream come true.
This trait allows you to call a static “macro” method at runtime to add a new method to the class by executing a closure.
Behind the scenes, it will use the magic ``__call()`` and ``__callStatic()`` methods PHP provides to make the method
work as if it were really on the class.

.. caution::

    It uses ``__call`` & ``__callStatic`` magic methods. Be careful if your
    class already using them. It will end up in conflict.

macro()
=======

.. code:: php

   class House {
    use MacroMix;
    protected $color = 'gold';
   }

Now lets mix,

.. code:: php

    House::macro('fill', function ($value) {
        return $this->map(fn () => $value);
    });

    $house = new House();
    $house->fill('x')->all(); // ['x', 'x', 'x']

mix()
=======

Instead of closure lets push a whole class (which is full of methods)

.. code:: php

    $lemonade = new class() {
       public function lemon()
       {
          return function() {
             return 'Squeeze Lemon';
          };
       }

       public function water()
       {
          return function() {
             return 'Add Water';
          };
       }
    }

    House::mix($lemonade);

    $house = new House();
    $houseClass->lemon(); // Squeeze Lemon

.. tip::

    Whenever you are using some identifier like ``$this`` or some parameter variable it might happen that it won't backtrace
    as editor won't know it out function/method. In this case, use ``/** @var NameSpace\Class $this */`` to give it the identity.