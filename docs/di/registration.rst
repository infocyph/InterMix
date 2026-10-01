.. _di.registration:

========================
Wiring and providers
========================

Constructor and property wiring is declared directly on autowire().

.. code-block:: php

   $builder->autowire(
       Mailer::class,
       Mailer::class,
       arguments: [
           'dsn' => 'smtp://localhost',
       ],
       properties: [
           'enabled' => true,
       ],
   );

Explicit arguments take precedence over automatic resolution. Positional and named constructor arguments must not be mixed in one definition.

Service providers
-----------------

ServiceProviderInterface::register() receives ContainerBuilder.

.. code-block:: php

   final class MailProvider implements ServiceProviderInterface
   {
       public function register(ContainerBuilder $builder): void
       {
           $builder
               ->value('mail.channel', 'transactional')
               ->autowire(Mailer::class, Mailer::class);
       }
   }

   $builder->import(new MailProvider());

The application/bootstrap layer constructs provider instances and therefore owns provider constructor dependencies. InterMix does not instantiate provider classes implicitly.
