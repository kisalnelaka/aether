# Contributing

Look, I'm open to PRs, but I'm not going to merge garbage. 

## Setup

1. Clone it.
2. Make sure you have PHP 8.3+. 
3. Run the tests. If they fail on your machine, figure out why before complaining.
   ```bash
   php tests/RouterTest.php
   php tests/ContainerTest.php
   ```

## Coding Rules

If you break these, I'm closing the PR without looking at it.

1. `declare(strict_types=1);` at the top of every file.
2. NO REGEX. Don't even try to sneak a `preg_match` in. Use `strpos`, `substr`, or iterate the bytes yourself. 
3. Don't add Composer. If you need a library, write it yourself or don't use it.
4. No Reflection at runtime. We do that at compile time (AOT).
5. Type everything. Params, returns, properties. If PHPStan complains at level 9, fix it.
6. Don't introduce circular deps.

## PR Process

1. Fork it. 
2. Branch off `main`.
3. Write code that doesn't suck. 
4. Add tests if you added a feature.
5. Make sure the existing tests pass.
6. Open a PR. Tell me what it does and why it's needed. Keep it brief. 

## Issues

If you find a bug, tell me how to reproduce it. Give me the OS, PHP version, and exact code that caused it. Don't just say "it crashed".

If you want to change the core architecture (the radix tree, the scheduler, the worker protocol), open an issue to discuss it first. I spent a lot of time optimizing those and I'm not rewriting them just because you prefer a different pattern.
