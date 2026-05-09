<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use stdClass;

class IMStdClass extends stdClass {}

class_alias(IMStdClass::class, 'IMStdClass');
