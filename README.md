# AETHER

A PHP 8.3 framework that doesn't suck.

I got tired of every framework loading 50MB of bloated garbage just to print "hello world" and taking 50ms to boot up. So I built this. 
It runs in persistent memory. It uses fibers. It has exactly zero external dependencies. I didn't even use Composer because I don't need half the internet downloaded to my drive just to pad a string. 

It's fast. Core is under 180KB. If you want to build a monolith with 300 packages, go use Laravel. If you want raw speed and don't mind writing actual code, you're in the right place.

## Why

- **Boot time is ~0ms.** It boots once and stays in memory. 
- **No Regex.** Regex routing is slow and lazy. I wrote a proper Radix Tree. It matches in O(K) time. 
- **Fibers.** It does non-blocking I/O natively. No callback hell.
- **No Reflection at runtime.** Reflection is slow. We compile attributes down to static factories before running.
- **Workers.** Uses `pcntl_fork`. It just works.

## Installation

Just clone it. There's no package manager. 

```bash
git clone https://github.com/kisalnelaka/aether.git
cd aether
```

## How to use it

Look at the `example/` folder. It's not complicated. 

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/aether.php';

use Aether\Kernel\Application;

$app = Application::create(__DIR__);

// map your routes
$app->get('/', 'App\\Controllers\\HomeController@index', 'home');

$app->run();
```

Controllers look like this. I used attributes. 

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Aether\Attributes\Controller;
use Aether\Attributes\Get;
use Aether\Http\Request;
use Aether\Http\Response;

#[Controller(prefix: '/api')]
final class UserController
{
    #[Get(path: '/users/{id}', name: 'users.show')]
    public function show(Request $request): Response
    {
        return Response::json([
            'user' => ['id' => $request->getRouteParam('id')],
        ]);
    }
}
```

Run it:
```bash
php -S localhost:8080 example/app.php
```

Or run the actual worker manager if you want performance:
```bash
php bin/aether serve
```

## Docs

Read the docs in the `docs/` folder or on the [Github Pages](https://kisalnelaka.github.io/aether/). If you don't read them and open an issue asking how the DI container works, I'll close it.

- [Architecture](docs/ARCHITECTURE.md) - How it actually works
- [Routing](docs/ROUTING.md) - Stop using regex
- [Container](docs/CONTAINER.md) - DI that doesnt leak memory
- [Fibers](docs/FIBERS.md) - Async I/O
- [AOT](docs/AOT.md) - Compiling the attributes
- [CLI](docs/CLI.md) - The few commands it has

## Rules I followed

- `declare(strict_types=1)` everywhere. No exceptions.
- No `preg_match`. It's banned.
- No Composer.
- No circular dependencies. The container will throw an error and refuse to boot if you write spaghetti code. 

## License

MIT. Do whatever you want with it, just don't blame me if it breaks. 

- [kisalnelaka](https://github.com/kisalnelaka)
- [LinkedIn](https://linkedin.com/in/kisalnelaka)
