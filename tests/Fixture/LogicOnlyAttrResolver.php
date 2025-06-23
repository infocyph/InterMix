<?php
namespace Infocyph\InterMix\Tests\Fixture;
use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Infocyph\InterMix\DI\Container;
use Reflector;

class LogicOnlyAttrResolver implements AttributeResolverInterface
{
    public function resolve(object $attr, Reflector $target, Container $c): mixed
    {
        $c->logger()?->log($attr->level, "[Attr] $target handled");
        return null;
    }
}
