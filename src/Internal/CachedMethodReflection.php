<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use InvalidArgumentException;
use ReflectionMethod;

/** @internal */
final class CachedMethodReflection extends ReflectionMethod
{
    use ReflectorInitialization;

    public function __construct(object|string $objectOrMethod, ?string $method = null)
    {
        $this->initializeOnce();
        if ($method === null) {
            throw new InvalidArgumentException('A cached reflector requires a method name.');
        }
        parent::__construct($objectOrMethod, $method);
    }
}
