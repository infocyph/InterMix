<?php

use Infocyph\InterMix\Memoize\Cache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

beforeEach(function () {
    $this->cache = Cache::instance();
});

test('it uses default temporary file cache', function () {
    $result = $this->cache->get('key', fn() => 'computed value');
    expect($result)->toBe('computed value');
});

test('it retrieves the same value for the same key', function () {
    $this->cache->get('key', fn() => 'computed value');
    $result = $this->cache->get('key', fn() => 'new value');
    expect($result)->toBe('computed value'); // Memoized value is returned
});

test('it supports setting a custom cache driver', function () {
    $this->cache->setCacheDriver(new ArrayAdapter());
    $result = $this->cache->get('key', fn() => 'computed value');
    expect($result)->toBe('computed value');
});

test('it supports forgetting a cache key', function () {
    $this->cache->get('key', fn() => 'computed value');
    $this->cache->forget('key');
    $result = $this->cache->get('key', fn() => 'new value');
    expect($result)->toBe('new value'); // Cache is cleared, so callable runs again
});

test('it supports flushing all cache', function () {
    $this->cache->get('key1', fn() => 'value1');
    $this->cache->get('key2', fn() => 'value2');
    $this->cache->flush();
    $result1 = $this->cache->get('key1', fn() => 'new value1');
    $result2 = $this->cache->get('key2', fn() => 'new value2');
    expect($result1)->toBe('new value1');
    expect($result2)->toBe('new value2');
});
