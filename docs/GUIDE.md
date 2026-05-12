# Guide: Build an API

Look, I'm not going to hold your hand through this. We're building a simple CRUD API. I assume you know PHP. 

## 1. Setup

Clone the repo. We aren't using Composer. 

```bash
git clone https://github.com/kisalnelaka/aether.git myapi
cd myapi
mkdir -p app/Controllers app/Middleware app/Services
```

## 2. Config

Edit `config/aether.php`. Tell it where your files are.

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

## 3. Data Service

We're putting it in memory for this example. Normally you'd hit a DB, but I don't want to explain how to install Postgres to you. 

Create `app/Services/TaskService.php`. Mark it `#[Persistent]` so it survives the request cycle.

```php
<?php
declare(strict_types=1);

namespace App\Services;
use Aether\Attributes\Persistent;

#[Persistent]
final class TaskService
{
    private array $tasks = [];
    private int $nextId = 1;

    public function create(string $title): array
    {
        $task = ['id' => $this->nextId++, 'title' => $title];
        $this->tasks[$task['id']] = $task;
        return $task;
    }

    public function all(): array { return array_values($this->tasks); }
    public function delete(int $id): void { unset($this->tasks[$id]); }
}
```

## 4. Controller

Create `app/Controllers/TaskController.php`. Inject the service. We use constructor injection. Property injection is an anti-pattern. 

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Aether\Attributes\Controller;
use Aether\Attributes\Get;
use Aether\Attributes\Post;
use Aether\Attributes\Delete;
use Aether\Http\Request;
use Aether\Http\Response;
use App\Services\TaskService;

#[Controller(prefix: '/api/tasks')]
final class TaskController
{
    public function __construct(private TaskService $tasks) {}

    #[Get(path: '/')]
    public function index(): Response
    {
        return Response::json(['data' => $this->tasks->all()]);
    }

    #[Post(path: '/')]
    public function create(Request $r): Response
    {
        $body = $r->json();
        $task = $this->tasks->create($body['title'] ?? 'Untitled');
        return Response::json(['data' => $task]);
    }

    #[Delete(path: '/{id}')]
    public function destroy(Request $r): Response
    {
        $this->tasks->delete((int)$r->getRouteParam('id'));
        return Response::json(['status' => 'gone']);
    }
}
```

## 5. Boot It

Make `public/index.php`. 

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../aether.php';

use Aether\Kernel\Application;

$app = Application::create(AETHER_ROOT);

// If you didn't compile routes, you register them here.
$app->get('/api/tasks', 'App\\Controllers\\TaskController@index');
$app->post('/api/tasks', 'App\\Controllers\\TaskController@create');
$app->delete('/api/tasks/{id}', 'App\\Controllers\\TaskController@destroy');

$app->run();
```

## 6. Run it.

```bash
php -S localhost:8080 public/index.php
```

Curl it to test. It works. 

```bash
curl -X POST http://localhost:8080/api/tasks -d '{"title":"do work"}'
curl http://localhost:8080/api/tasks
```

## 7. AOT 

For production, compile the garbage out of it. 

```bash
php bin/aether aot:compile
```

That's it. It's not hard. Go write some code.
