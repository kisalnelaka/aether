# CLI

It's a CLI tool. It does what it says. 

```bash
php bin/aether <command>
```

## Commands

### `version`
Tells you the version. 

### `routes:compile`
Builds the route cache. 

### `aot:compile`
Runs the whole AOT build process. Routes, hydrators, classmaps. Run this before you deploy. 

### `aot:clear`
Wipes the cache. Run this if things get weird because you changed an attribute and didn't recompile. 

### `routes:list`
Lists your routes in a table.

### `container:diag`
Shows container memory stats and service counts. 

### `serve`
Boots the worker manager and starts the app in persistent memory mode. Use this in production instead of `php -S`.

Configured in `config/aether.php`:
- `workers`: Number of forks. Keep it sane. 
- `worker_mode`: `stdio` or `socket` or whatever. 
- `max_requests`: Recycle count. 

Send `SIGTERM` to kill it cleanly. Send `SIGUSR2` to hot-reload workers. 
