# Architecture

## The Setup

AETHER isn't like normal PHP. Standard PHP boots up, handles one request, dies, and throws away everything. That's a massive waste of CPU cycles. AETHER boots **once** and stays alive. It runs as a persistent four-module kernel. 

## Diagram

```
                    ┌─────────────────────┐
                    │      Request         │
                    │   (RoadRunner/       │
                    │    sockets/etc)      │
                    └──────────┬──────────┘
                               │
                    ┌──────────▼──────────┐
                    │   Worker Manager     │
                    │   (Process Pool)     │
                    └──────────┬──────────┘
                               │
                    ┌──────────▼──────────┐
                    │      Kernel          │
                    │   (The actual app)   │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                │
    ┌─────────▼──────┐ ┌──────▼──────┐ ┌───────▼──────┐
    │  Radix Tree    │ │  Middleware  │ │  DI Container │
    │  Router        │ │  Pipeline    │ │  (Scope safe) │
    └─────────┬──────┘ └──────┬──────┘ └───────┬──────┘
              │                │                │
              └────────────────┼────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │    Controller        │
                    │  (Runs in a fiber)   │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                │
    ┌─────────▼──────┐ ┌──────▼──────┐ ┌───────▼──────┐
    │  Async DB      │ │  Async HTTP │ │  Shmop Cache │
    └────────────────┘ └─────────────┘ └──────────────┘
```

## The Four Modules

### Module Alpha: Persistent Execution Engine

This is what keeps the lights on. 
1. **WorkerManager** forks N processes. 
2. The Kernel boots once per worker. 
3. Workers listen on stdin/stdout or sockets. 
4. Ephemeral (request-scoped) services get trashed after every request so you don't leak memory. Persistent services stay.
5. Workers recycle after a set number of requests. Because let's face it, your code will probably leak something eventually. 
6. `SIGTERM` shuts it down nicely. `SIGUSR2` reloads it.

### Module Beta: Radix-Trie Router

Regex routers are a crutch. This uses a compressed prefix tree. 
- Lookup time is O(K), where K is the number of path segments. It doesn't matter if you have 10 routes or 10,000 routes. 
- No regex. None. It iterates bytes and compares strings.
- Static > Params > Wildcard. That's the priority. 
- It dumps the whole tree to a PHP array on compilation so OPcache can chew through it instantly. 

### Module Gamma: Fiber-Async I/O

We use PHP 8.1 Fibers. If your controller hits the DB, the fiber suspends. The scheduler grabs another fiber from the queue and works on that. 
- When the socket has data, the suspended fiber resumes. 
- This means you handle concurrent requests on a single thread without blocking. It's cooperative multitasking. Just don't write an infinite loop. 

### Module Delta: Zero-Reflection Schema

Reflection at runtime is slow. Don't do it.
1. When you run the AOT compiler, it scans your `#[Route]` and `#[Inject]` attributes. 
2. It generates plain PHP factory functions. 
3. At runtime, the container just calls `new Class(...)`. No reflection needed. 

## The Lifecycle

1. Worker gets HTTP data.
2. Creates an immutable Request object.
3. Router walks the tree to find a match. 
4. Params get shoved into the Request.
5. Global middleware runs.
6. Route middleware runs.
7. Controller fires.
8. Response bubbles back up.
9. Response goes to the client.
10. Container purges all Ephemeral garbage. 
11. Back to step 1. 

## Memory Rules

| Scope | How long it lives | Example |
|---|---|---|
| **Persistent** | Until the worker dies | DB pools, config data |
| **Ephemeral** | Until the request ends | Auth contexts, the request itself |
| **Transient** | Until you lose the reference | Random DTOs |

If you screw this up and put request data in a Persistent service, you will leak data between users. The container forces this separation so you don't mess it up.
