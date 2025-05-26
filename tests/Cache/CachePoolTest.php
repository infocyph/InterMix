<?php

use Infocyph\InterMix\Cache\Adapter\FileCacheAdapter;
use Infocyph\InterMix\Cache\CachePool;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/pest_cache_' . uniqid();
    $this->cache = new CachePool('tests', $this->cacheDir);
});

afterEach(function () {
    if (!is_dir($this->cacheDir)) {
        return;
    }

    // Recursively delete everything under the base dir
    $it = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    // Finally remove the base directory
    rmdir($this->cacheDir);
});

test('convenience set() and get()', function () {
    expect($this->cache->get('nope'))
        ->toBeNull()
        ->and($this->cache->set('foo', 'bar', 60))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

test('PSR-6 getItem()/save()', function () {
    $item = $this->cache->getItem('psr');
    expect($item)
        ->toBeInstanceOf(FileCacheAdapter::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(123)->expiresAfter(null)->save();
    $fetched = $this->cache->getItem('psr');
    expect($fetched->isHit())
        ->toBeTrue()
        ->and($fetched->get())->toBe(123);
});

test('saveDeferred() and commit()', function () {
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->getItem('a')->set('A')->saveDeferred();
    $this->cache->getItem('b')->set('B')->saveDeferred();

    // not yet written
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();

    expect($this->cache->get('a'))
        ->toBe('A')
        ->and($this->cache->get('b'))->toBe('B');
});

test('ArrayAccess support', function () {
    $this->cache['x'] = 10;
    expect(isset($this->cache['x']))
        ->toBeTrue()
        ->and($this->cache['x'])->toBe(10);

    unset($this->cache['x']);
    expect(isset($this->cache['x']))->toBeFalse();
});

test('magic __get/__set/__isset/__unset', function () {
    $this->cache->alpha = 'beta';
    expect(isset($this->cache->alpha))
        ->toBeTrue()
        ->and($this->cache->alpha)->toBe('beta');

    unset($this->cache->alpha);
    expect(isset($this->cache->alpha))->toBeFalse();
});

test('Iterator and Countable', function () {
    $this->cache->clear();
    $this->cache->set('k1', 'v1');
    $this->cache->set('k2', 'v2');

    expect(count($this->cache))->toBe(2);

    $collected = [];
    foreach ($this->cache as $k => $v) {
        $collected[$k] = $v;
    }
    expect($collected)->toMatchArray([
        'k1' => 'v1',
        'k2' => 'v2',
    ]);
});

test('runtime re-namespace and directory swap', function () {
    // 1) pick a fresh base dir
    $newDir = sys_get_temp_dir() . '/pest_cache_new_' . uniqid();

    // 2) reconfigure the pool to use it
    $this->cache->setNamespaceAndDirectory('newns', $newDir);

    // 3) write & read a value
    expect($this->cache->set('foo', 'bar'))
        ->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');

    // 4) assert that a .cache file lives under the namespace folder
    $namespaceDir = $newDir . '/cache_newns';
    expect(is_dir($namespaceDir))->toBeTrue();

    $files = glob($namespaceDir . '/*.cache');
    expect($files)->not->toBeEmpty();

    // 5) cleanup this newDir (Pest’s afterEach only removes the original cacheDir)
    foreach (glob($namespaceDir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($namespaceDir);
    @rmdir($newDir);
});
