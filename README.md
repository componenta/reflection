# Componenta Reflection

Small reflection helpers for Componenta libraries and PHP 8.4+ applications.

## Installation

```bash
composer require componenta/reflection
```

## Requirements

- PHP 8.4+

## Reflecting values

`Reflection` caches class/function/method reflectors independently. Object and closure reflectors use `WeakMap`, so caching does not extend the lifetime of application objects in long-running workers.

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn (): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::reflect()` accepts mixed input and returns the corresponding native reflector when the value is supported, or `null` otherwise.

`Reflection::class()` returns `null` when the class cannot be reflected. Exceptions raised by an autoloader are not swallowed.

## Reading attributes

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` returns `list<object>|null`. Attribute definitions are cached, but attribute instances are created fresh for each read. `hasMetadata()` and `hasDeepMetadata()` only check definitions and do not execute attribute constructors.

Supported reflectors include functions/methods, classes, parameters, properties and `ReflectionClassConstant`.

## Deep attribute lookup

Deep lookup includes class attributes, methods, properties, PHP 8.4 property hooks and class constants.

```php
$attributes = Reflection::getDeepMetadata($class, SomeAttribute::class);

foreach ($attributes as $path => $items) {
    // App\Command\CreatePostCommand
    // App\Command\CreatePostCommand::handle()
    // App\Command\CreatePostCommand::$title
    // App\Command\CreatePostCommand::$title::get()
    // App\Command\CreatePostCommand::STATUS
}
```

Method paths include `()` so they cannot collide with a class constant of the same name.

## Reflection types

`ReflectionType` works with named, union, intersection and DNF types.

```php
use Componenta\Reflection\ReflectionType;

$type = (new ReflectionParameter($callable, 'value'))->getType();

ReflectionType::match($type, $value);
ReflectionType::contains($type, SomeInterface::class);
ReflectionType::getTypeNames($type);
ReflectionType::toString($type);
```

For `self`, `parent` or `static`, pass the declaring class as `scope`:

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

### Simple PHP-level coercion

`canCoerce()` and `coerce()` are intentionally kept in this package. They answer whether a value can be converted to the reflected **native PHP type** and perform that conversion. They are not a replacement for `componenta/caster`, which owns named/domain-specific conversion pipelines.

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

The contract is strict: when `canCoerce()` returns `false`, `coerce()` rejects the same conversion. Union coercion is performed only when the value already matches a branch or exactly one branch is coercible; ambiguous conversions are rejected.

Arrays are accepted from arrays, iterables and `Componenta\Arrayable\Arrayable`. Arbitrary scalars are not silently wrapped into one-element arrays.

## Cache management

```php
Reflection::clearReflectors();
```

This clears class/function/method caches and resets the weak object, closure and attribute-definition caches. It is primarily useful for test isolation.

## Development

```bash
composer check
```

The test suite uses Pest 4. Static analysis runs with PHPStan at maximum level. CI covers PHP 8.4 and 8.5.
