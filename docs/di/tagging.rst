.. _di.tagging:

========
Tagging
========

“Tagging” lets you label multiple definitions with one or more **tags** so you
can later retrieve **all** of them in one call.  It is perfect for event
listeners, middleware stacks, console commands, or any *plugin*-style
collection.

-----------------
Add a Tag 🏷️
-----------------

Simply pass **``tags:[…]``** to
:php:meth:`Infocyph\\InterMix\\DI\\Managers\\DefinitionManager::bind`.

.. code-block:: php

   $c->definitions()
     ->bind('listener.email',  EmailListener::class,  tags: ['event'])
     ->bind('listener.logger', LoggerListener::class, tags: ['event'])
     ->bind('listener.sms',    SmsListener::class,    tags: ['event', 'sms']); // multi-tag

Tags are **free-form strings** – use any naming convention that makes sense for
your project.

--------------------------
Retrieve All by Tag 📬
--------------------------

.. code-block:: php

   foreach ($c->findByTag('event') as $id => $factory) {
       $factory()->handle($event);
   }

* :php:meth:`Infocyph\\InterMix\\DI\\Container::findByTag` returns an **array**:
  ``[id => callable|object, …]``
* Services are resolved **lazily** – if the definition was a class string the
  container still honours lazy loading & lifetimes.
* Tag lookup is **environment-aware**. If you override tags with
  ``setDefinitionMetaForEnv(...)``, ``findByTag()`` uses the active environment.

-----------------------
Multiple Tags per ID
-----------------------

A definition may belong to more than one group:

.. code-block:: php

   $def->bind(
       'task.cleanup',
       CleanupTask::class,
       tags: ['cron', 'maintenance']
   );

Retrieve by **any** tag:

.. code-block:: php

   $nightlyJobs = $c->findByTag('cron');

Example with environment-aware tags:

.. code-block:: php

   $c->definitions()->bind('mailer', Mailer::class, tags: ['core']);

   $c->options()
     ->setDefinitionMetaForEnv('test', 'mailer', tags: ['core', 'test-only'])
     ->setEnvironment('test');

   $testOnly = $c->findByTag('test-only'); // contains 'mailer' only in test env

------------------------
Tag Queries and Filters
------------------------

Need advanced filtering?

.. code-block:: php

   // all services that carry *both* 'event' AND 'sms' tags
   $smsEvents = array_filter(
       $c->findByTag('sms'),
       fn (string $id) => in_array(
           'event',
           $c->getRepository()->getDefinitionMeta($id)['tags'] ?? [],
           true
       ),
       ARRAY_FILTER_USE_KEY
   );

*(For advanced tag metadata queries, use the low-level repository API.)*

--------------------
Common Use-Cases 🎯
--------------------

* **Event bus** – gather all listeners tagged “event”.
* **Console commands** – auto-register everything tagged “cli”.
* **HTTP middleware** – build a pipeline from “middleware” tag collection.
* **Cron / queue workers** – pick jobs by “cron”, “queue:high”, etc.

------------------
Best Practices 💡
------------------

* Treat tags as **capsule names** – short, lower-case, dash-separated.
* Avoid coupling tag names to classes; think behaviour: “validator”, “policy”.
* Keep tag lists **small**; if you need complex querying consider a dedicated
  registry object.

Next up » :doc:`environment`
