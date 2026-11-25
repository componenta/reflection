<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use ReflectionClass;

/**
 * @template T of object
 * @extends ReflectionClass<T>
 * @internal
 */
final class CachedClassReflection extends ReflectionClass
{
    use ReflectorInitialization;

    /** @param T|class-string<T> $objectOrClass */
    public function __construct(object|string $objectOrClass)
    {
        $this->initializeOnce();
        parent::__construct($objectOrClass);
    }
}
