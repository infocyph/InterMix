<?php

declare(strict_types=1);
namespace Infocyph\InterMix\Tests\Fixture;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Reflector;

class LogicOnlyAttrResolver implements AttributeResolverInterface
{
    public function resolve(object $attr, Reflector $target, RuntimeContainerInterface $c): mixed
    {
        $c->logger()?->log($attr->level, "[Attr] $target handled");
        return AttributeResolution::Unresolved;
    }
}
