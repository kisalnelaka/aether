# Routing

## The Router

It's a Radix Tree. It doesn't use regex because regex is slow and compiling patterns at runtime is a waste of CPU. It splits your URI into segments and walks the tree. 

## Defining Routes

### Manual 

If you like typing a lot:
```php
$app = Application::create(__DIR__);

$app->get('/users', 'UserController@index');
$app->post('/users', 'UserController@store');
$app->get('/users/{id}', 'UserController@show', 'users.show');
```

### Attributes 

Do this instead. It's cleaner and the compiler understands it.

```php
#[Controller(prefix: '/api/v1')]
final class UserController
{
    #[Get(path: '/users', name: 'users.index')]
    public function index(Request $request): Response { ... }

    #[Get(path: '/users/{id}', name: 'users.show')]
    public function show(Request $request): Response { ... }
}
```

### Groups

```php
$app->group('/admin', function ($router) {
    $router->get('/dashboard', 'AdminController@dashboard');
}, middleware: ['AuthMiddleware']);
```

## Params

Stick a name in brackets. 

```php
$app->get('/users/{id}', 'UserController@show');
```

Get it out in the controller:

```php
$id = $request->getRouteParam('id');
```

## Wildcards

If you need to catch everything else (like serving files), use an asterisk. 

```php
$app->get('/files/{path*}', 'FileController@serve');
```

## Names

Give routes a name if you want to generate URLs later. 

```php
$app->get('/users/{id}', 'UserController@show', 'users.show');
$url = $app->getRouter()->url('users.show', ['id' => '42']);
```

## Priority

If paths conflict, it picks them in this order:
1. Exact static matches
2. Dynamic params (`{id}`)
3. Wildcards (`{path*}`)

## Compilation (AOT)

You need to compile this for production. 

```bash
php bin/aether routes:compile
```

This dumps the whole tree into a massive PHP array in `cache/compiled_routes.php`. When the kernel boots, it just `require`s the file. No scanning, no parsing, no reflection. OPcache turns it into near-native code. 

## Tree Structure 

If you register:
```
GET /users
GET /users/list
GET /users/{id}
```

The tree looks like this in memory:
```
root
└── "users"
    ├── (leaf: GET /users)
    ├── "list" (leaf: GET /users/list)
    └── {id} (param)
        └── (leaf: GET /users/{id})
```

It only checks the segments it needs to. It's fast. Don't break it.
