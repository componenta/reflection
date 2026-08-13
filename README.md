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

`Reflection::callable()` reflects concrete functions, methods and invokable objects. A PHP callable that exists only through `__call()` or `__callStatic()` has no concrete method declaration whose signature can be reflected reliably; `Reflection::callable()` rejects that case with `InvalidArgumentException`, while the generic `Reflection::reflect()` returns `null`.

`Reflection::class()` supports classes, interfaces, traits and enums. It returns `null` when the class-like symbol cannot be reflected. A negative lookup invokes the autoloader once; exceptions raised by that autoloader are not swallowed.

## Reading attributes

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` returns `list<object>|null`. Attribute definitions are cached, but attribute instances are created fresh for each read. `hasMetadata()` and `hasDeepMetadata()` only check definitions and do not execute attribute constructors.

Supported reflectors include functions/methods, classes, parameters, properties, `ReflectionClassConstant`, and `ReflectionConstant`. Global-constant attributes and `ReflectionConstant::getAttributes()` are available starting with PHP 8.5; on PHP 8.4 a `ReflectionConstant` safely behaves as having no attributes.

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

Native Reflection changed how some relative type names are exposed between PHP 8.4 and 8.5. When a reflected type is still reported as `self`, `parent` or `static`, pass an explicit resolution `scope`. For `self` and `parent` this is normally the declaring class. For `static`, pass the effective late-static class when that distinction matters.

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

If native Reflection already reports a concrete class name, `scope` is not needed for that name.

### Simple PHP-level coercion

`canCoerce()` and `coerce()` are intentionally kept in this package. They provide conservative, explicit conversions to reflected **native PHP types**. They are not a replacement for `componenta/caster`, which owns named/domain-specific conversion pipelines.

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

The contract is strict: when `canCoerce()` returns `false`, the same conversion is rejected by `coerce()`. Union coercion is intentionally more conservative than PHP's weak parameter coercion: an already matching branch is preserved, and otherwise conversion is performed only when exactly one branch is coercible. Multiple possible conversion targets are rejected instead of applying PHP's scalar-union preference order.

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

The test suite uses Pest 4. Static analysis runs with PHPStan at maximum level. CI covers PHP 8.4 and 8.5, including a PHP 8.5-only smoke test for attributed global constants.
