# Componenta Reflection

Small reflection helper for Componenta internals.

## Installation

```bash
composer require componenta/reflection
```

## Requirements

- PHP 8.4+

## Related Packages

This package is standalone and is mostly an internal helper for Componenta libraries.

| Package | Why it uses reflection |
|---|---|
| `componenta/di` | Reads parameters, properties, and attributes during dependency resolution. |
| `componenta/interceptor` | Reads method attributes for attribute-based interceptors. |
| `componenta/class-finder` | Attribute and inheritance filters can inspect discovered classes. |
| `componenta/policy` | Attribute policies can be read through reflection providers or app compilation. |

## What It Provides

- Cached reflection for classes, objects, closures, functions, methods, and invokable objects.
- Attribute lookup helpers for a single reflector.
- Deep attribute lookup across a class, its methods, properties, and constants.
- `WeakMap`-backed metadata cache for attribute instances.

## Reflecting Values

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn(): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::reflect()` accepts mixed input and returns the matching PHP reflector or `null`.

```php
$reflector = Reflection::reflect($value);
```

## Reading Attributes

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` returns `array<object>|null`: `null` means no matching attributes.

## Deep Attribute Lookup

```php
$attributes = Reflection::getDeepMetadata($class, SomeAttribute::class);

foreach ($attributes as $path => $items) {
    // $path examples:
    // App\Command\CreatePostCommand
    // App\Command\CreatePostCommand::handle
    // App\Command\CreatePostCommand::$title
    // App\Command\CreatePostCommand::STATUS
}
```

## Cache Management

```php
Reflection::clearReflectors();
```

Use this in tests when a test mutates reflector state or needs isolation.
