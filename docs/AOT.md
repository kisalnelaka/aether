# AOT Compiler

## Overview

Reflection is slow. Scanning directories on every request is slow. Parsing docblocks is just stupid.

The AOT (Ahead-of-Time) compiler runs once during your build step. It scans everything, figures out the dependencies and routes, and dumps them into plain PHP arrays and closures. At runtime, we load the cached files. 

## What It Builds

- `compiled_routes.php`: The whole radix tree dumped as a massive array.
- `compiled_hydrators.php`: A bunch of static factory functions for the DI container so it never has to use Reflection.
- `compiled_classmap.php`: An optimized autoloader map so it doesn't even hit the disk to resolve classes. 

## Run It

```bash
php bin/aether aot:compile
```

## Config

Tell it where to look in `config/aether.php`:

```php
return [
    'controllers' => [
        'App\\Controllers' => __DIR__ . '/../app/Controllers',
    ],
    'services' => [
        'App\\Services' => __DIR__ . '/../app/Services',
    ],
];
```

## Compiled Output

It looks like this. Don't edit these files by hand. If you do, the next build will wipe your changes and you'll waste hours debugging. 

```php
$c->bind('App\\Services\\UserService',
    static fn(Container $c) => new \App\Services\UserService(
        $c->resolve('App\\Repository\\UserRepository')
    ),
    ServiceScope::Persistent
);
```

## CI/CD

Just run `php bin/aether aot:compile` in your build script before you deploy. It's not rocket science.
