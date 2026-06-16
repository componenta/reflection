# Componenta Reflection

Небольшой вспомогательный пакет рефлексии для внутренних пакетов Componenta.

## Установка

```bash
composer require componenta/reflection
```

## Требования

- PHP 8.4+

## Связанные пакеты

Пакет самодостаточный и является внутренней утилитой для других библиотек Componenta.

| Пакет | Зачем использует рефлексию |
|---|---|
| `componenta/di` | Читает параметры, свойства и атрибуты при разрешении зависимостей. |
| `componenta/interceptor` | Читает атрибуты методов для атрибутных перехватчиков. |
| `componenta/class-finder` | Фильтры атрибутов и наследования могут использовать рефлексию найденных классов. |
| `componenta/policy` | Атрибутные политики читаются через reflection provider или app-компиляцию. |

## Что предоставляет пакет

- Кешированную reflection-обёртку для классов, объектов, closures, functions, methods и invokable objects.
- Helpers для поиска attributes на одном reflector.
- Deep attribute lookup по классу, его методам, свойствам и константам.
- кеш метаданных на `WeakMap` для экземпляров атрибутов.

## Reflection значений

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn(): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::reflect()` принимает mixed input и возвращает подходящий PHP reflector или `null`.

```php
$reflector = Reflection::reflect($value);
```

## Чтение attributes

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` возвращает `array<object>|null`: `null` означает, что matching attributes нет.

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

## Управление кешем

```php
Reflection::clearReflectors();
```

Используйте это в тестах, когда тест меняет состояние reflector или требует изоляции.
