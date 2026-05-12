# AETHER

**The Anti-Bloat Framework.** PHP 8.3+. Zero dependencies. Resident memory. AOT-compiled.

AETHER isn't here to hold your hand or manage your YAML files. It's built for engineers who are tired of `vendor/` directories larger than their production databases. It boots once, stays in memory, and uses Fibers to handle concurrency without the overhead of traditional request lifecycles.

## 🏛️ The Core Philosophy

1.  **Zero Dependencies**: No Composer. No `vendor/`. No security vulnerabilities in packages you didn't even know you had.
2.  **Resident Memory**: The application stays alive. Database connections stay open. Routes stay matched.
3.  **AOT First**: Reflection is for development. Production uses Ahead-of-Time compiled static PHP factories.
4.  **Byte-Level Precision**: We use Radix trees and byte manipulation instead of heavy Regex patterns.

---

## 📊 AETHER vs. The World

An honest comparison. No marketing fluff.

| Framework | Language | Architecture | Pros | Cons |
| :--- | :--- | :--- | :--- | :--- |
| **AETHER** | PHP | Resident/AOT | 240KB, < 0.1ms boot, Fiber-concurrency, Zero deps. | Tiny ecosystem, no community plugins, requires PHP 8.3+. |
| **Laravel** | PHP | Req/Resp | Massive ecosystem, incredible DX, Eloquent is powerful. | Heavy bloat (50MB+ vendor), slow boot (~20-50ms), Reflection-heavy. |
| **Swoole** | PHP/C++ | Event Loop | Blazing fast, true async, supports Coroutines. | Requires C extension, non-standard PHP behavior, complex debugging. |
| **Express** | JS | Event Loop | Simple, huge ecosystem, very flexible. | Middleware hell, callback/async soup, high memory for high load. |
| **Fastify** | JS | Event Loop | Faster than Express, low overhead, JSON-first. | JS single-threaded bottlenecks, dependency heavy. |
| **Gin / Fiber** | Go | Binary | Compiled binary, extreme throughput, type-safe. | Static typing can be verbose, requires learning Go's pointer/concurrency model. |
| **Actix / Axum** | Rust | Binary | Memory safety, fastest in the world, zero-cost abstractions. | Brutal learning curve (borrow checker), long compile times. |
| **FastAPI** | Python | ASGI | Great DX, auto-docs, Pydantic validation. | Python's GIL limits true parallelism, overhead of asyncio. |
| **Spring Boot** | Java | JVM | Enterprise standard, infinite features, very stable. | Enormous memory usage (500MB+ idle), slow startup (seconds), XML/Annotation hell. |

---

## 🛠️ Feature Suite

AETHER provides everything you need for a modern microservice in a single file-system tree.

- **Radix Router**: O(K) lookup. No regex. Supports wildcards and groups.
- **DI Container**: State-aware scopes (Persistent, Ephemeral, Transient).
- **Fiber Scheduler**: Cooperative multitasking for async I/O (DB, HTTP, Sockets).
- **AOT Compiler**: Generates static code for Routes, Hydrators, Validators, and Events.
- **Connection Pool**: Fiber-aware pooling for MySQL and PostgreSQL.
- **Entity Manager**: Zero-reflection Data Mapper ORM.
- **Job Queue**: In-memory background jobs with Fiber workers.
- **WebSocket Server**: Full RFC 6455 implementation.
- **File Storage**: Local + S3 (manual SigV4, no SDK).
- **Validation**: Attribute-based DTO validation compiled to raw PHP.
- **Shared Memory**: Cross-process caching via `shmop`.

---

## 🚀 Quick Start

### 1. Boot it
```php
<?php
require_once 'aether.php';
$app = Aether\Kernel\Application::create(AETHER_ROOT);

$app->get('/api/v1/user/{id}', 'App\Controllers\UserController@show');

$app->run();
```

### 2. Compile it (Production)
```bash
php bin/aether aot:compile
```
This scans your attributes and generates static files in `cache/`. At runtime, AETHER will skip all scanning and simply `require` the pre-optimized maps.

### 3. Serve it
```bash
php bin/aether serve --workers=8
```
Starts the WorkerManager. It forks into 8 processes, each managing its own event loop and connection pool.

---

## 📝 Honest Trade-offs

**Why use AETHER?**
- You want the absolute maximum performance PHP can offer without writing C.
- You hate managing 1000+ dependencies.
- You need a single-binary-like experience where you just copy a folder and it works.
- You are building high-performance microservices or real-time (WebSocket) apps.

**Why NOT use AETHER?**
- You need a CMS or a blog (use WordPress/Laravel).
- You need a specific third-party integration (Stripe, AWS SDK, etc.) and don't want to write the HTTP calls yourself.
- You are not comfortable with persistent-memory concepts (managing state, memory leaks).
- You want a framework with a huge StackOverflow presence.

## ⚖️ License
MIT. Built by engineers who value code over configuration.
