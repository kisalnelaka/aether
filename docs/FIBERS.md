# Fibers

## Overview

We use PHP 8.1+ Fibers. If you don't know what they are, go read the PHP manual. In short, they let us do non-blocking I/O without the callback hell or `.then().then()` garbage you see in Node.

When you run a DB query, the fiber suspends. The thread grabs the next fiber and works on it. When the DB comes back with data, your fiber resumes. Concurrent execution on a single thread. 

## Scheduler

This is the event loop. It manages ready fibers and fibers waiting on sockets or timers. 

```php
$scheduler = new Scheduler();

$deferred = $scheduler->defer(function () {
    return "Done";
});

$scheduler->run();
$result = $deferred->await();
```

## Deferred

It's a promise, basically. 

```php
$deferred = new Deferred();
$result = $deferred->await(); // suspends until someone resolves it
```

## Async DB

This wraps mysqli/pg. It intercepts the network call and suspends the fiber so your worker doesn't freeze waiting for the database. 

```php
$db = new AsyncDatabase($scheduler, [
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'myapp',
    'username' => 'root',
]);

// This suspends the fiber.
$users = $db->query('SELECT * FROM users');
```

## Async HTTP

Same thing, but for HTTP calls. 

```php
$http = new AsyncHttpClient($scheduler);

// Suspends the fiber.
$response = $http->get('https://api.example.com');
```

## In Controllers

If the kernel runs in Fiber mode, controllers are wrapped automatically. You just write normal looking code and it suspends behind the scenes. 

```php
#[Get(path: '/stats')]
public function stats(): Response
{
    // These run concurrently if they hit the network.
    $users = $this->db->query('...');
    $data = $this->http->get('...');

    return Response::json(['users' => $users]);
}
```

## Architecture Notes

- Fibers are cooperative. They don't preempt. If you write an infinite `while(true)` loop, you will lock the entire worker process and I will laugh at you.
- It uses `stream_select()`. It's fine. 
- Schedulers are per-worker. Don't try to share them. 
