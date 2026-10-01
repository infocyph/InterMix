.. _di.tagging:

================
Tags and pipelines
================

Tags are assigned while configuring autowired or factory definitions.

.. code-block:: php

   $builder
       ->autowire(EmailListener::class, EmailListener::class, tags: ['event'])
       ->autowire(AuditListener::class, AuditListener::class, tags: ['event']);

Iterate tagged services lazily from the active runtime:

.. code-block:: php

   foreach ($runtime->tagged('event') as $id => $listener) {
       $listener->handle($event);
   }

The iterator captures active scope identity and rejects use after that scope is no longer current.

For pipeline-style processing, Container also exposes pipeline(tag).
