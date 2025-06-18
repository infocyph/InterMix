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

Simply pass **`tags:[…]`** to :py:meth:`DefinitionManager.bind`.

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

* :php:meth:`Container.findByTag()` returns an **array**:
  ``[id => callable|object, …]``
* Services are resolved **lazily** – if the definition was a class string the
  container still honours lazy loading & lifetimes.

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

------------------------
Tag Queries and Filters
------------------------

Need advanced filtering?

.. code-block:: php

   // all services that carry *both* 'event' AND 'sms' tags
   $smsEvents = array_filter(
       $c->findByTag('sms'),
       fn ($id) => in_array('event', $c->getRepository()->getTagsFor($id), true)
   );

*(The low-level tag map is available via the repository API.)*

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
