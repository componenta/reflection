<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use Error;

/** @internal */
trait NativeCallableInspection
{
    /** @return callable|null */
    private static function inspectCallable(mixed $value, bool $preserveOrder = true): mixed
    {
        if (is_array($value)) {
            $value = self::snapshotCallableArray($value, $preserveOrder);
        }

        try {
            return is_callable($value) ? $value : null;
        } catch (Error $error) {
            // The native probe can throw for abstract static methods. Preserve
            // failures raised by autoloaders, even with the same diagnostic.
            if ($error::class !== Error::class
                || $error->getFile() !== __FILE__
                || ($error->getTrace()[0]['function'] ?? null) !== 'is_callable'
                || !str_starts_with($error->getMessage(), 'Cannot call abstract method ')
            ) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $callable
     * @return array<array-key, mixed>
     */
    private static function snapshotCallableArray(array $callable, bool $preserveOrder = false): array
    {
        if (count($callable) !== 2 || !isset($callable[0], $callable[1])) {
            return $callable;
        }

        // Own the routing slots before callable validation can load a class.
        return $preserveOrder && array_key_first($callable) === 1
            ? [1 => $callable[1], 0 => $callable[0]]
            : [$callable[0], $callable[1]];
    }
}
