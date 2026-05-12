# Changelog

All notable changes to the AETHER framework are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-12

### Added

- Radix Tree router with O(K) lookup complexity, zero regular expressions.
- Support for static routes, dynamic parameters (`{id}`), and wildcard catch-all routes (`{path*}`).
- Route pre-compilation to static PHP arrays for JIT optimization.
- State-safe dependency injection container with three scopes: Persistent, Ephemeral, Transient.
- Circular dependency detection at bind time.
- PHP 8 Attributes for route definition: `#[Get]`, `#[Post]`, `#[Put]`, `#[Delete]`, `#[Patch]`, `#[Route]`.
- `#[Controller]` attribute for class-level route prefixes and shared middleware.
- `#[Inject]`, `#[Persistent]`, `#[Ephemeral]` attributes for dependency injection.
- AOT Compiler: route scanning, hydrator generation, and classmap compilation.
- Fiber-based event loop scheduler with I/O socket polling and timer support.
- Async database abstraction for MySQL (mysqli) and PostgreSQL (pg_*).
- Async HTTP client using non-blocking stream sockets.
- Deferred/Promise implementation for Fiber coordination.
- Immutable HTTP Request object compatible with resident-memory servers.
- HTTP Response builder with static factories for JSON, HTML, redirects, and errors.
- Middleware pipeline with FIFO execution chain.
- Shared memory cache via shmop for cross-process data sharing.
- Worker Manager with pcntl_fork process spawning.
- Signal handling: SIGTERM for graceful shutdown, SIGUSR2 for hot-reload.
- RoadRunner-compatible stdin/stdout worker protocol.
- Worker auto-recycling after configurable request count.
- Custom PSR-4 autoloader with AOT classmap support.
- CLI tool with commands: version, routes:compile, aot:compile, aot:clear, routes:list, container:diag, serve.
- Full documentation: Architecture, Routing, Container, Fibers, AOT, CLI, Tutorial.
- Example application with annotated controller.
- Router and Container test suites.
