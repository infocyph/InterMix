<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Revs(500)]
#[Iterations(5)]
#[Warmup(1)]
final class FenceBench
{
    private int $newKeyCounter = 0;

    private int $requiredNewKeyCounter = 0;

    #[BeforeMethods('setUpLimitExceededPath')]
    public function benchLimitExceededPath(): void
    {
        try {
            FenceBenchLimit::instance('over-limit');
        } catch (\Throwable) {
            // noop: this benchmark measures the capped creation path.
        }
    }

    #[BeforeMethods('setUpMultiExistingKeyHotPath')]
    public function benchMultiExistingKeyHotPath(): void
    {
        FenceBenchMulti::instance('existing');
    }

    #[BeforeMethods('setUpMultiNewKeyPath')]
    public function benchMultiNewKeyCreationPath(): void
    {
        FenceBenchMulti::instance('new-' . (++$this->newKeyCounter));
    }

    #[BeforeMethods('setUpRequirementCreationPath')]
    public function benchRequirementCreationPath(): void
    {
        FenceBenchMulti::instance(
            'required-' . (++$this->requiredNewKeyCounter),
            ['extensions' => ['Core'], 'classes' => [\stdClass::class]],
        );
    }

    #[BeforeMethods('setUpRequirementHitPath')]
    public function benchRequirementHitPath(): void
    {
        FenceBenchMulti::instance(
            'required-hit',
            ['extensions' => ['nonexistent_ext'], 'classes' => ['Missing\\NotFound']],
        );
    }

    #[BeforeMethods('setUpSingleHotPath')]
    public function benchSingleInstanceHotPath(): void
    {
        FenceBenchSingle::instance();
    }

    public function setUpLimitExceededPath(): void
    {
        FenceBenchLimit::clearInstances();
        FenceBenchLimit::setLimit(1);
        FenceBenchLimit::instance('seed');
    }

    public function setUpMultiExistingKeyHotPath(): void
    {
        FenceBenchMulti::clearInstances();
        FenceBenchMulti::instance('existing');
    }

    public function setUpMultiNewKeyPath(): void
    {
        $this->newKeyCounter = 0;
        FenceBenchMulti::clearInstances();
    }

    public function setUpRequirementCreationPath(): void
    {
        $this->requiredNewKeyCounter = 0;
        FenceBenchMulti::clearInstances();
    }

    public function setUpRequirementHitPath(): void
    {
        FenceBenchMulti::clearInstances();
        FenceBenchMulti::instance('required-hit');
    }

    public function setUpSingleHotPath(): void
    {
        FenceBenchSingle::clearInstances();
        FenceBenchSingle::instance();
    }
}

final class FenceBenchSingle
{
    use Single;
}

final class FenceBenchMulti
{
    use Multi;
}

final class FenceBenchLimit
{
    use Limit;
}
