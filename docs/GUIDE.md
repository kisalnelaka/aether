# Guide: Build a Full-Stack App

Look, building a JSON API is trivial. You asked for a full web app from A-Z with UI frameworks. Fine. I'll walk you through building a complete, publishable web app. I'm going to show you how to wire up Tailwind CSS via CDN for speed, but the same logic applies if you use Vite, React, or whatever bloated frontend stack you prefer. 

I assume you know how to write PHP and HTML. If you don't, close this tab.

## 1. Directory Setup

Clone the framework. Stop using Composer to download half the internet.

```bash
git clone https://github.com/kisalnelaka/aether.git mywebapp
cd mywebapp
mkdir -p app/Controllers app/Views public/assets
```

## 2. Configuration

Tell the AOT compiler where your controllers are. Edit `config/aether.php`.

```php
return [
    'controllers' => [
        'App\\Controllers' => __DIR__ . '/../app/Controllers',
    ],
    // Keep your views out of the compiler. They aren't classes.
];
```

## 3. The View Engine

AETHER ships with a microscopic View engine that uses native PHP templates. No Blade, no Twig, no 50MB of parsing overhead. Just PHP.

Initialize it in your entry point (see Step 6).

Create `app/Views/layouts/app.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title ?? 'AETHER App') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-zinc-100 font-sans p-8">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-3xl font-bold mb-6 tracking-tight text-white">AETHER Web App</h1>
        <?= $content ?? '' ?>
    </div>
</body>
</html>
```

Create `app/Views/pages/home.php`:

```php
<div class="p-6 bg-zinc-900 border border-zinc-800 rounded-lg shadow-xl">
    <h2 class="text-xl font-semibold mb-4 text-emerald-400">System Status</h2>
    <p>Framework: <span class="text-zinc-400">AETHER</span></p>
    <p>Time: <span class="text-zinc-400"><?= date('H:i:s') ?></span></p>
    
    <form action="/submit" method="POST" class="mt-6 flex gap-4">
        <input type="text" name="message" placeholder="Type something..." 
               class="flex-1 bg-zinc-950 border border-zinc-800 rounded px-4 py-2 focus:outline-none focus:border-emerald-500 text-white">
        <button type="submit" 
                class="bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-2 rounded font-medium transition-colors">
            Send
        </button>
    </form>
</div>
```

## 4. The Controller

Serve the view using the `View` facade.

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Aether\Attributes\Controller;
use Aether\Attributes\Get;
use Aether\Attributes\Post;
use Aether\Http\Request;
use Aether\Http\Response;
use Aether\View\View;

#[Controller]
final class WebController
{
    #[Get(path: '/')]
    public function index(): Response
    {
        return View::response('pages.home', [
            'title' => 'Home - AETHER'
        ], layout: 'layouts.app');
    }

    #[Post(path: '/submit')]
    public function handleForm(Request $request): Response
    {
        // For a full app, you'd use DTO validation here.
        // For now, let's just grab the message.
        $msg = $request->post('message') ?? 'Nothing';
        
        return View::response('pages.home', [
            'title' => 'Success',
            'message' => "You sent: $msg"
        ], layout: 'layouts.app');
    }
}
```

## 5. Serving Static Files (Assets)

If you compile your frontend with Vite or React, you'll have CSS and JS files in `public/assets/`. AETHER routes can catch wildcards to serve them.

Add this to `app/Controllers/AssetController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Aether\Attributes\Controller;
use Aether\Attributes\Get;
use Aether\Http\Request;
use Aether\Http\Response;

#[Controller]
final class AssetController
{
    #[Get(path: '/assets/{path*}')]
    public function serve(Request $request): Response
    {
        $path = $request->getRouteParam('path');
        $file = __DIR__ . '/../../public/assets/' . $path;

        if (!is_file($file)) {
            return Response::html('Not Found', 404);
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = match($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'text/plain'
        };

        return (new Response())
            ->withStatus(200)
            ->withHeader('Content-Type', $mime)
            ->withBody(file_get_contents($file));
    }
}
```

## 6. The Entry Point

Create `public/index.php`. This boots the Kernel.

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../aether.php';

use Aether\Kernel\Application;

$app = Application::create(AETHER_ROOT);
$app->run();
```

## 7. Run It

Start the server.

```bash
php -S localhost:8080 -t public public/index.php
```

Open `http://localhost:8080` in your browser. You'll see your styled Tailwind app. It's rendering instantly because the router doesn't parse XML and the container doesn't reflect your classes. 

## 8. Ship to Production (AOT)

Before you deploy this to your VPS, compile the reflection away. If you skip this, it will scan attributes on every single request and I will judge you.

```bash
php bin/aether aot:compile
```

Now use a real process manager like RoadRunner or `php bin/aether serve` to keep the app resident in memory. Stop killing your process on every request. 
