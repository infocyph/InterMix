<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PreloadGenerator;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Serializer\ResourceHandlers;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class RegressionResourceHandlers extends ResourceHandlers
{
    public static function registerStream(): void
    {
        ValueSerializer::registerResourceHandler(
            'stream',
            function ($res): array {
                rewind($res);
                return ['content' => stream_get_contents($res)];
            },
            function (array $data) {
                $s = fopen('php://memory', 'r+');
                fwrite($s, $data['content']);
                rewind($s);
                return $s;
            }
        );
    }
}

interface RegressionTokenSource
{
    public function token(): string;
}

class RegressionTransientTokenSource implements RegressionTokenSource
{
    private readonly string $value;

    public function __construct(?string $value = null)
    {
        $this->value = $value ?? bin2hex(random_bytes(8));
    }

    public function token(): string
    {
        return $this->value;
    }
}

class RegressionMethodConsumer
{
    public function current(RegressionTokenSource $tokenSource): string
    {
        return $tokenSource->token();
    }
}

it('disables debug tracing without throwing', function () {
    $c = Container::instance(uniqid('trace_'));

    $out = $c->options()->enableDebugTracing(false)->end();

    expect($out)->toBe($c)
        ->and($c->tracer()->level())->toBe(TraceLevelEnum::Off);
});

it('generates a syntactically valid preload file', function () {
    $c = Container::instance(uniqid('preload_'));
    $file = sys_get_temp_dir() . '/intermix_preload_' . uniqid() . '.php';

    (new PreloadGenerator())->generate($c, $file);

    $cmd = '"' . PHP_BINARY . '" -l "' . $file . '"';
    exec($cmd, $output, $code);

    expect($code)->toBe(0);
});

it('supports pure PSR-6 pools when resolving definitions', function () {
    $pool = new class () implements CacheItemPoolInterface {
        private array $deferred = [];
        private array $store = [];

        public function getItem(string $key): CacheItemInterface
        {
            $hit = array_key_exists($key, $this->store);
            $value = $hit ? $this->store[$key] : null;

            return new class ($key, $value, $hit) implements CacheItemInterface {
                public function __construct(
                    private string $key,
                    private mixed $value,
                    private bool $hit,
                ) {
                }

                public function getKey(): string
                {
                    return $this->key;
                }

                public function get(): mixed
                {
                    return $this->value;
                }

                public function isHit(): bool
                {
                    return $this->hit;
                }

                public function set(mixed $value): static
                {
                    $this->value = $value;
                    $this->hit = true;
                    return $this;
                }

                public function expiresAt(?DateTimeInterface $expiration): static
                {
                    return $this;
                }

                public function expiresAfter(int|\DateInterval|null $time): static
                {
                    return $this;
                }
            };
        }

        public function getItems(array $keys = []): iterable
        {
            foreach ($keys as $key) {
                yield $key => $this->getItem($key);
            }
        }

        public function hasItem(string $key): bool
        {
            return array_key_exists($key, $this->store);
        }

        public function clear(): bool
        {
            $this->store = [];
            $this->deferred = [];
            return true;
        }

        public function deleteItem(string $key): bool
        {
            unset($this->store[$key], $this->deferred[$key]);
            return true;
        }

        public function deleteItems(array $keys): bool
        {
            foreach ($keys as $key) {
                $this->deleteItem($key);
            }
            return true;
        }

        public function save(CacheItemInterface $item): bool
        {
            $this->store[$item->getKey()] = $item->get();
            return true;
        }

        public function saveDeferred(CacheItemInterface $item): bool
        {
            $this->deferred[$item->getKey()] = $item;
            return true;
        }

        public function commit(): bool
        {
            foreach ($this->deferred as $key => $item) {
                $this->save($item);
                unset($this->deferred[$key]);
            }
            return true;
        }
    };

    $c = Container::instance(uniqid('psr6_'));
    $c->getRepository()->setCacheAdapter($pool);
    $c->definitions()->bind('answer', fn () => 42);

    expect($c->get('answer'))->toBe(42)
        ->and($c->get('answer'))->toBe(42);
});

it('registerDefaults discovers subclass register methods', function () {
    ValueSerializer::clearResourceHandlers();
    RegressionResourceHandlers::registerDefaults();

    $s = fopen('php://memory', 'r+');
    fwrite($s, 'ok');
    rewind($s);

    $blob = ValueSerializer::serialize($s);
    $rest = ValueSerializer::unserialize($blob);

    expect(is_resource($rest))->toBeTrue()
        ->and(get_resource_type($rest))->toBe('stream')
        ->and(stream_get_contents($rest))->toBe('ok');
});

it('does not reuse non-constructor method parameter resolution values across calls', function () {
    $c = Container::instance(uniqid('method_args_'));
    $c->definitions()->bind(
        RegressionTokenSource::class,
        fn () => new RegressionTransientTokenSource(),
        LifetimeEnum::Transient,
    );

    $first = $c->call(RegressionMethodConsumer::class, 'current');
    $second = $c->call(RegressionMethodConsumer::class, 'current');

    expect($first)->not->toBe($second);
});
