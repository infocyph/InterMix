<?php

use Infocyph\InterMix\Memoize\WeakCache;

beforeEach(function () {
    $this->weakCache = WeakCache::instance();
});

test('it tracks cache hits and misses', function () {
    $object = new stdClass;
    $this->weakCache->get($object, 'signatureX', fn () => 'computed value'); // Miss
    $this->weakCache->get($object, 'signatureX', fn () => 'new value'); // Hit
    $stats = $this->weakCache->getStatistics();
    expect($stats)->toMatchArray(['hits' => 1, 'misses' => 1, 'total' => 2]);
});

test('it can memoize a value', function () {
    $object = new stdClass;
    $result = $this->weakCache->get($object, 'signature', fn () => 'computed value');
    expect($result)->toBe('computed value');
});

test('it retrieves the same value for the same object and signature', function () {
    $object = new stdClass;
    $this->weakCache->get($object, 'signature', fn () => 'computed value');
    $result = $this->weakCache->get($object, 'signature', fn () => 'new value');
    expect($result)->toBe('computed value'); // Memoized value is returned
});

test('it supports clearing cache by object', function () {
    $object = new stdClass;
    $this->weakCache->get($object, 'signature', fn () => 'computed value');
    $this->weakCache->forget($object);
    $result = $this->weakCache->get($object, 'signature', fn () => 'new value');
    expect($result)->toBe('new value'); // Cache is cleared, so callable runs again
});

test('it can flush all cache', function () {
    $object1 = new stdClass;
    $object2 = new stdClass;
    $this->weakCache->get($object1, 'signature1', fn () => 'value1');
    $this->weakCache->get($object2, 'signature2', fn () => 'value2');
    $this->weakCache->flush();
    $result1 = $this->weakCache->get($object1, 'signature1', fn () => 'new value1');
    $result2 = $this->weakCache->get($object2, 'signature2', fn () => 'new value2');
    expect($result1)
        ->toBe('new value1')
        ->and($result2)->toBe('new value2');
});



