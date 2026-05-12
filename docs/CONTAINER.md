# Dependency Injection Container

## Overview

The standard PHP DI container model is broken for persistent apps. If you just wire things up without thinking about scopes, your request state leaks into the next request and you expose User A's data to User B. 

This container forces you to be explicit about state. 

## Scopes

Learn these. If you get them wrong, your app will break.

| Scope | Lifetime | Reset? | Use it for |
|---|---|---|---|
| `Persistent` | The whole process | No | Database pools, config |
| `Ephemeral` | One request | Yes | Current user, request data |
| `Transient` | One call | N/A | DTOs, factories |

## Binding

### Factories
```php
$container->persistent(DatabasePool::class, function (Container $c) {
    return new DatabasePool($c->resolve(Config::class));
});

$container->ephemeral(AuthContext::class, function (Container $c) {
    return new AuthContext($c->resolve(Request::class));
});
```

### Aliases
```php
$container->alias(LoggerInterface::class, FileLogger::class);
```

## Attribute Binding

Use attributes so the AOT compiler can generate the factories for you. Stop writing boilerplate.

```php
#[Persistent]
class DatabasePool
{
    public function __construct(
        #[Inject] private Config $config,
    ) {}
}
```

## Auto-Wiring

If you ask for a class that isn't bound, it tries to build it as Transient. Don't rely on this for complex stuff.

## Circular Deps

If A needs B and B needs A, the container throws an exception and halts. I didn't write a proxy resolver because circular dependencies mean your architecture is bad. Fix your architecture instead of expecting the container to magically resolve loops.

## Cleanup

The Kernel resets ephemeral instances automatically:
```php
$container->resetEphemerals();
```
Don't call this yourself. The framework does it.

## AOT Generation

When you compile (`php bin/aether aot:compile`), it spits out code like this:

```php
$c->bind('App\\Services\\UserService',
    static fn(Container $c) => new \App\Services\UserService(
        $c->resolve('App\\Repository\\UserRepository')
    ),
    ServiceScope::Persistent
);
```

No reflection at runtime. It's just a bunch of closures. It's fast. 

## Memory Leaks

This container prevents the usual memory leaks by:
1. Banning circular references.
2. Trashing ephemerals after every cycle.
3. Providing a leak checker so you can see if you orphaned something. 

If your memory still bloats, you did something stupid in your own code.
