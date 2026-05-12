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
- AOT Compiler: route scanning, hydrator generation, validation rule compilation, event dispatch maps, and ORM entity mapping.
- High-performance native WebSocket Server (RFC 6455) using PHP Fibers.
- In-memory Job Queue with fiber-based background workers and automatic retries.
- Zero-reflection Entity Manager (ORM) with AOT-compiled hydrators.
- SDK-less S3 Storage driver with manual AWS Signature V4 implementation.
- Minimalist View Engine with layout inheritance and partials.
- Attribute-based Validation Engine (#[Validate]) with AOT compilation.
- Attribute-based Event Bus (#[Listener]) with AOT dispatch maps.
- Fiber-aware Connection Pooling for MySQL and PostgreSQL.
- Shmop-based cross-process cache with sub-millisecond latency.
- Worker Manager with pcntl_fork process spawning and hot-reload.
- CLI tool with full AOT toolchain and server management.
- Comprehensive technical documentation and cross-language performance comparisons.
