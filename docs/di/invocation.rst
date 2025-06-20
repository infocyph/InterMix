.. _di.invocation:

========================
Invocation & Shortcuts
========================

InterMix ships with a **mini-helper API** that goes beyond PSR-11
``get()`` / ``has()``.
These helpers *call* things immediately so you can treat functions,
closures or whole classes as **actions**.

-------------------------------------------------------
1 · call( callable|string $target , ?string $method )
-------------------------------------------------------

.. code-block:: php

   // (a) global / namespaced function -----------------
   $len = $c->call('strlen', ['InterMix']);            // → 7

   // (b) invokable object or closure ------------------
   $iso = $c->call(fn (DateTimeImmutable $now) => $now->format('c'));

   // (c) class & method (autowired) -------------------
   $c->call(JobProcessor::class, 'handle');            // ctor + handle()

**Rules**

* All parameters (including constructor params) are **auto-resolved** unless
  you pass them explicitly in the arg array.
* `$method` is optional; omit it for classes with an ``__invoke`` or the
  *defaultMethod* you set in :ref:`di.options`.

-------------------------------------------------------
2 · getReturn( string $id )
-------------------------------------------------------

Sometimes you register a *method* rather than rely on ``__invoke`` or a
default.
``getReturn()`` hides the instance and returns **only the method’s output**:

.. code-block:: php

   $c->registration()
       ->registerClass(Mailer::class)
       ->registerMethod(Mailer::class, 'healthCheck');

   $ok = $c->getReturn(Mailer::class);   // ← returns bool, not Mailer

Internally the container:

1. Builds (or fetches) the **Mailer** singleton.
2. Executes **healthCheck()** with injected parameters.
3. Returns the result to you.

-------------------------------------------------------
3 · make( string $class , ?string $method [, array $args] )
-------------------------------------------------------

*Always* returns a **brand-new** instance (or the method’s output) and thus
**skips** the singleton cache used by ``get()``. Perfect for factories or
stateless workers.

.. code-block:: php

   // just the object
   $pdf = $c->make(PdfBuilder::class);

   // build → call render() → pass explicit args
   $html = $c->make(
       PdfBuilder::class,
       'render',
       [$template, $payload]        // ← overrides auto-wiring
   );

-------------------------------------------------------
4 · When to use what?
-------------------------------------------------------

+ **get()** – retrieve a *singleton* service (most common).
+ **call()** – execute any callable with DI-resolved args.
+ **getReturn()** – “fire & forget” a registered *method*.
+ **make()** – obtain a *fresh* instance each time.

Tip: These helpers respect the **same options** (autowiring, attributes,
environment overrides) you configured on the container—so your wiring rules
apply everywhere.

-------------------------------------------------------
Reference Table
-------------------------------------------------------

=================  ==============  =========  =====================================================
Helper             Caches object?  Returns    Typical use-case
=================  ==============  =========  =====================================================
``get($id)``       **Yes**         object     Core PSR-11 retrieval.
``call()``         n/a             mixed      Invoke a callable with DI.
``getReturn()``    **Yes**         mixed      Skip instance, grab method result.
``make()``         **No**          object\|mixed  Factory pattern, transient workflows.
=================  ==============  =========  =====================================================

See also: :ref:`di.options` (``defaultMethod``), :ref:`di.registration`
(for pre-registered class metadata).
