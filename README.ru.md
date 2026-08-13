# Componenta Reflection

Небольшой набор reflection-утилит для библиотек Componenta и приложений на PHP 8.4+.

## Установка

Этот README описывает **ещё не выпущенный API v2, который сейчас находится в `main`**. Последний опубликованный стабильный релиз пока относится к v1.x, поэтому для проверки API, описанного ниже, development-ветку нужно указать явно:

```bash
composer require componenta/reflection:dev-main
```

Для установки последнего опубликованного релиза v1 используйте:

```bash
composer require componenta/reflection:^1.0
```

API v1 отличается от API, описанного в README ветки `main`.

## Требования

- PHP 8.4+

## Reflection значений

`Reflection` раздельно кеширует reflectors классов, функций и методов. Reflectors объектов и closures хранятся в `WeakMap`, поэтому кеш не продлевает жизнь объектам приложения в долгоживущих workers.

```php
use Componenta\Reflection\Reflection;

$class = Reflection::class(App\Service\UserService::class);
$object = Reflection::object($service);
$closure = Reflection::callable(static fn (): string => 'ok');
$method = Reflection::callable([App\Service\UserService::class, 'handle']);
```

`Reflection::reflect()` принимает mixed input и возвращает соответствующий native reflector либо `null`, если значение нельзя корректно отразить.

`Reflection::callable()` отражает конкретные functions, methods и invokable objects. PHP-callable, существующий только за счёт `__call()` или `__callStatic()`, не имеет конкретного method declaration с достоверной сигнатурой; поэтому `Reflection::callable()` отклоняет такой случай через `InvalidArgumentException`, а универсальный `Reflection::reflect()` возвращает `null`.

`Reflection::class()` поддерживает classes, interfaces, traits и enums. Для отсутствующего class-like symbol возвращается `null`. При отрицательном lookup autoloader вызывается один раз; исключения из autoloader не подавляются.

## Чтение attributes

```php
$class = Reflection::class(App\Command\CreatePostCommand::class);

$policies = Reflection::getMetadata($class, PermissionPolicy::class);
$policy = Reflection::getFirstMetadata($class, PermissionPolicy::class);
$hasPolicy = Reflection::hasMetadata($class, PermissionPolicy::class);
```

`getMetadata()` возвращает `list<object>|null`. Кешируются definitions attributes, но их экземпляры создаются заново при каждом чтении. `hasMetadata()` и `hasDeepMetadata()` проверяют только definitions и не вызывают constructors attributes.

Поддерживаются functions/methods, classes, parameters, properties, `ReflectionClassConstant` и `ReflectionConstant`. Attributes глобальных constants и `ReflectionConstant::getAttributes()` доступны начиная с PHP 8.5; на PHP 8.4 `ReflectionConstant` безопасно ведёт себя как reflector без attributes.

## Deep attribute lookup

Deep lookup учитывает class attributes, methods, properties, PHP 8.4 property hooks и class constants.

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

У method path присутствуют `()`, поэтому method и class constant с одинаковым именем не могут перезаписать друг друга.

## Reflection типов

`ReflectionType` поддерживает named, union, intersection и DNF types.

```php
use Componenta\Reflection\ReflectionType;

$type = (new ReflectionParameter($callable, 'value'))->getType();

ReflectionType::match($type, $value);
ReflectionType::contains($type, SomeInterface::class);
ReflectionType::getTypeNames($type);
```

`ReflectionParameter::getType()` может вернуть `null`, поэтому `getTypeNames(null)` возвращает пустой список. Nullable named declarations нормализуются семантически: например, `?int` даёт `['int', 'null']`, поэтому `contains($type, 'null')` согласован с nullable unions. Для `mixed` результат остаётся `['mixed']`, поскольку `mixed` уже включает `null` по смыслу.

`toString()` требует настоящий `ReflectionType` и возвращает его native строковое представление PHP.

Native Reflection по-разному представляет некоторые relative type names в PHP 8.4 и 8.5. Если тип всё ещё возвращается как `self`, `parent` или `static`, передавайте явный `scope`. Для обычных class declarations `self` и `parent` обычно разрешаются относительно declaring class; для `static` передавайте фактический late-static class, когда это различие существенно.

```php
ReflectionType::match($type, $value, scope: $declaringClass);
ReflectionType::getTypeNames($type, scope: $declaringClass);
```

Сам trait не является корректным scope для relative type: `self`, `parent` и `static` внутри trait интерпретируются в consuming class. Отражайте метод через consuming class и передавайте этот class как `scope`; иначе helper завершится fail-fast вместо ошибочного разрешения relative type в имя trait.

Если native Reflection уже возвращает конкретное имя класса, `scope` для него не нужен.

### Простое PHP-level coercion

`canCoerce()` и `coerce()` намеренно остаются в пакете. Они выполняют консервативные явные преобразования к отражённым **native PHP types**. Это не замена `componenta/caster`: caster отвечает за именованные и доменно-специфичные pipelines преобразования.

```php
if (ReflectionType::canCoerce($type, $value)) {
    $value = ReflectionType::coerce($type, $value);
}
```

Контракт строгий: если `canCoerce()` вернул `false`, `coerce()` отклонит то же преобразование. Для union coercion сохраняется уже подходящий branch, а преобразование выполняется только если возможен ровно один target. Если подходят несколько targets, преобразование отклоняется вместо применения внутреннего порядка предпочтений weak scalar coercion PHP.

В array можно преобразовать array, iterable и `Componenta\Arrayable\Arrayable`. Произвольные scalars больше не оборачиваются молча в одноэлементный array.

## Управление кешем

```php
Reflection::clearReflectors();
```

Метод очищает кеши classes/functions/methods и сбрасывает weak caches объектов, closures и definitions attributes. Основной сценарий — изоляция тестов.

## Разработка

```bash
composer check
```

Тесты написаны на Pest 4. Статический анализ выполняется PHPStan на максимальном уровне. CI проверяет PHP 8.4 и 8.5, включая отдельный PHP 8.5-only smoke test для attributed global constants.
