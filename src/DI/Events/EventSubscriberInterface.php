<?php

namespace Infocyph\InterMix\DI\Events;

/**
 * Subscribers define a static method listing their subscribed events
 * plus the corresponding listener methods.
 */
interface EventSubscriberInterface
{
    /**
     * Return an array like:
     *   [
     *     'user.created' => 'onUserCreated',
     *     'user.deleted' => 'onUserDeleted',
     *   ]
     * Then the dispatcher can auto-wire these.
     */
    public static function getSubscribedEvents(): array;
}
