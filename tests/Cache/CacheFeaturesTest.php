<?php

use Infocyph\InterMix\Cache\Cache;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/pest_cache_features_' . uniqid();
    $this->cache = Cache::file('features', $this->cacheDir);
});

afterEach(function () {
    if (!is_dir($this->cacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($this->cacheDir);
});

test('setTagged + invalidateTag removes all tagged keys', function () {
    $this->cache->setTagged('k1', 'A', ['grp']);
    $this->cache->setTagged('k2', 'B', ['grp']);
    $this->cache->set('k3', 'C');

    expect($this->cache->invalidateTag('grp'))->toBeTrue()
        ->and($this->cache->get('k1'))->toBeNull()
        ->and($this->cache->get('k2'))->toBeNull()
        ->and($this->cache->get('k3'))->toBe('C');
});

test('remember caches once and supports tag invalidation', function () {
    $count = 0;

    $v1 = $this->cache->remember(
        'hot',
        function ($item) use (&$count) {
            $count++;
            $item->expiresAfter(30);
            return 'payload';
        },
        null,
        ['hot-path'],
    );

    $v2 = $this->cache->remember(
        'hot',
        function () use (&$count) {
            $count++;
            return 'should-not-run';
        },
    );

    expect($v1)->toBe('payload')
        ->and($v2)->toBe('payload')
        ->and($count)->toBe(1)
        ->and($this->cache->invalidateTag('hot-path'))->toBeTrue()
        ->and($this->cache->get('hot'))->toBeNull();
});

test('get callable path still computes once on miss', function () {
    $count = 0;

    $a = $this->cache->get('compute', function ($item) use (&$count) {
        $count++;
        $item->expiresAfter(30);
        return 99;
    });
    $b = $this->cache->get('compute', function () use (&$count) {
        $count++;
        return 11;
    });

    expect($a)->toBe(99)
        ->and($b)->toBe(99)
        ->and($count)->toBe(1);
});
