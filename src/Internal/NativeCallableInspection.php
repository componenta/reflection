<?php

declare(strict_types=1);

namespace Componenta\Reflection\Internal;

use Error;

/** @internal */
trait NativeCallableInspection
{
    /** @phpstan-assert-if-true callable $value */
    private static function isNativeCallable(mixed $value): bool
    {
        try {
            return is_callable($value);
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

            return false;
        }
    }
}
