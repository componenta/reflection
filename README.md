# Componenta Reflection

Reflection utilities for PHP 8.4+.

## Installation

```bash
composer require componenta/reflection:^2.0
```

## Reflection

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn (): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::class()` supports classes, interfaces, traits and enums and returns `null` when the symbol does not exist.

`Reflection::callable()` supports functions, closures, concrete methods and invokable objects. It rejects callables resolved only through `__call()` or `__callStatic()` when PHP exposes no concrete signature, including the internal closures returned by `Closure::fromCallable()` for such methods.

Qualified ancestor callables return a `ReflectionFunction` for the native capture, retaining PHP's selected declaration and called class.

`Reflection::reflect()` accepts mixed input and returns the corresponding native reflector or `null` when the value cannot be reflected reliably.

Both methods select the owner and method of a callable array before validation can autoload application code. An abstract static dispatch route has no concrete reflector, so `reflect()` returns `null`.

## Attributes

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$attributes = Reflection::getMetadata($class, PermissionPolicy::class);
$first = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$has = Reflection::hasMetadata($class, PermissionPolicy::class);
```

Attribute definitions are cached for reflectors supplied by this library. For a caller-supplied reflector, metadata follows its current declaration. Attribute instances are created fresh for each read. `hasMetadata()` does not instantiate attributes.

Supported reflectors include functions, methods, classes, parameters, properties, class constants and global constants. Global constant attributes are available on PHP 8.5+.

### Deep lookup

```php
$attributes = Reflection::getDeepMetadata($class, SomeAttribute::class);
```

Deep lookup includes the class, methods, properties, property hooks and class constants. Paths are unambiguous:

```text
App\Command\CreatePostCommand
App\Command\CreatePostCommand::handle()
App\Command\CreatePostCommand::$title
App\Command\CreatePostCommand::$title::get()
App\Command\CreatePostCommand::STATUS
```

Use `getFirstDeepMetadata()` for the first matching attribute and `hasDeepMetadata()` for an existence check without instantiating attributes.

`getDeepMetadata()` collects the matching declarations before running attribute constructors.

## Reflection types

```php
use Componenta\Reflection\ReflectionType;

$type = (new ReflectionParameter($callable, 'value'))->getType();

ReflectionType::match($type, $value);
ReflectionType::contains($type, SomeInterface::class);
ReflectionType::getTypeNames($type);
```

`ReflectionType` supports named, union, intersection and DNF types.

`getTypeNames(null)` returns `[]`. Nullable named types are normalized semantically, so `?int` produces `['int', 'null']`.

When native Reflection exposes `self`, `parent` or `static`, pass the declaring or effective class as `scope`:

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

For relative types declared in a trait, use the consuming class as the scope.

`ReflectionType::toString()` returns PHP's native string representation of the reflected type.

### Coercion

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

Coercion is limited to reflected PHP types. Union coercion succeeds only when the value already matches a branch or exactly one branch is coercible. Ambiguous conversions are rejected. Union checks accept a matching ordinary member before trying callable resolution.

When checking a callable array, type inspection selects its owner and method before resolving the callable. Coercion through the `callable` branch returns this validated pair.

Integer coercion truncates finite values toward zero after checking the platform integer range. Decimal and scientific strings are checked and converted without rounding through a float.

Arrays can be produced from arrays, iterables and `Componenta\Arrayable\Arrayable` values. Scalars are not wrapped into one-element arrays.
`canCoerce()` checks iterable eligibility before iteration begins. `coerce()` uses PHP's array key rules and reports invalid keys with `InvalidArgumentException`.

## Cache management

```php
Reflection::clearReflectors();
```

Class, function and method reflectors are cached strongly. Object and closure reflectors are cached weakly and reused while a caller holds the reflector. The cache itself adds no strong reference to the reflector or its subject.
Cached handles are subclasses of the native reflector types. Calling `__construct()` again on a cached handle throws `LogicException`.

## Development

```bash
composer check
```

Tests use Pest 4. Static analysis runs with PHPStan at maximum level. CI covers PHP 8.4 and PHP 8.5.
