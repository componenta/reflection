<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use ReflectionObject;

/**
 * @template T of object
 * @extends ReflectionObject<T>
 * @internal
 */
final class CachedObjectReflection extends ReflectionObject
{
    use ReflectorInitialization;

    /** @param T $object */
    public function __construct(object $object)
    {
        $this->initializeOnce();
        parent::__construct($object);
    }
}
