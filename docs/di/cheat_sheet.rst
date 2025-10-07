.. _di.cheat_sheet:

=============
Cheat‑Sheet
=============

A quick reference for the most common InterMix container actions:

+----------+-----------------------------------------------------+-----------------------------+
| Task     | Fluent Chain                                        | Stand‑Alone Call           |
+==========+=====================================================+=============================+
| Bind     | ``$c->definitions()->bind('k', 1)``          | –                           |
+----------+-----------------------------------------------------+-----------------------------+
| Register | ``$c->registration()->registerClass(Foo::class)``   | –                           |
+----------+-----------------------------------------------------+-----------------------------+
| Options  | ``$c->options()->setOptions(...)``           | –                           |
+----------+-----------------------------------------------------+-----------------------------+
| Call     | ``$c->invocation()->call(Foo::class)``              | ``$c->call(Foo::class)``    |
+----------+-----------------------------------------------------+-----------------------------+
| Make     | ``$c->invocation()->make(Foo::class)``              | ``$c->make(Foo::class)``    |
+----------+-----------------------------------------------------+-----------------------------+

.. note::

   Fluent chains allow batch configuration with optional chaining of multiple actions.
   Stand-alone calls are shortcut helpers available directly from the container.

See also: :ref:`di.quickstart`, :ref:`di.usage`, :ref:`di.invocation`
