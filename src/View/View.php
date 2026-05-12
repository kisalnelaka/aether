<?php

declare(strict_types=1);

namespace Aether\View;

/**
 * Minimal View Engine. PHP is already a template language.
 * I'm not writing Blade. I'm not writing Twig. I'm writing
 * a 100-line class that renders PHP files with data extraction
 * and layout inheritance. That's all you need.
 *
 * If you want component-based UI, use React on the frontend
 * and let AETHER serve JSON. That's what APIs are for.
 *
 * @package Aether\View
 */
final class View
{
    private static string $viewPath = '';
    private static string $layoutPath = '';

    /** @var array<string, callable> Registered view helpers */
    private static array $helpers = [];

    /**
     * Configure view paths. Call this once during boot.
     */
    public static function configure(string $viewPath, string $layoutPath = ''): void
    {
        self::$viewPath = rtrim($viewPath, '/\\');
        self::$layoutPath = $layoutPath !== '' ? rtrim($layoutPath, '/\\') : self::$viewPath;
    }

    /**
     * Register a helper function available in all views.
     * e.g. View::helper('url', fn(string $path) => '/app' . $path);
     */
    public static function helper(string $name, callable $fn): void
    {
        self::$helpers[$name] = $fn;
    }

    /**
     * Render a view file with optional layout wrapping.
     *
     * @param string $view        View name (dot notation: 'pages.home' => pages/home.php)
     * @param array<string, mixed> $data  Variables available in the template
     * @param string|null $layout  Layout name, or null for no layout
     * @return string              Rendered HTML
     */
    public static function render(string $view, array $data = [], ?string $layout = null): string
    {
        $viewFile = self::resolveViewPath($view);

        if (!is_file($viewFile)) {
            throw new \RuntimeException("View '{$view}' not found at: {$viewFile}");
        }

        // Render the view content
        $content = self::renderFile($viewFile, $data);

        // Wrap in layout if specified
        if ($layout !== null) {
            $layoutFile = self::resolveLayoutPath($layout);
            if (!is_file($layoutFile)) {
                throw new \RuntimeException("Layout '{$layout}' not found at: {$layoutFile}");
            }

            $layoutData = array_merge($data, ['content' => $content]);
            $content = self::renderFile($layoutFile, $layoutData);
        }

        return $content;
    }

    /**
     * Render a view and return an HTTP Response directly.
     * Convenience method so controllers don't have to import Response.
     */
    public static function response(
        string $view,
        array $data = [],
        ?string $layout = null,
        int $status = 200,
    ): \Aether\Http\Response {
        $html = self::render($view, $data, $layout);
        return \Aether\Http\Response::html($html, $status);
    }

    /**
     * Escape output. Use this in templates instead of raw echo.
     * <?= View::e($userInput) ?>
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a partial (sub-template) from within a view.
     * <?= View::partial('components.header', ['title' => 'Home']) ?>
     */
    public static function partial(string $view, array $data = []): string
    {
        return self::render($view, $data);
    }

    /**
     * Conditional CSS class builder. Because writing ternaries in HTML is ugly.
     * <?= View::classes(['active' => $isActive, 'hidden' => !$show]) ?>
     *
     * @param array<string, bool> $classes
     */
    public static function classes(array $classes): string
    {
        $result = [];
        foreach ($classes as $class => $condition) {
            if ($condition) {
                $result[] = $class;
            }
        }
        return implode(' ', $result);
    }

    // ── Private ──

    private static function renderFile(string $file, array $data): string
    {
        // Make helpers available as local functions
        foreach (self::$helpers as $name => $fn) {
            $data[$name] = $fn;
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean() ?: '';
    }

    private static function resolveViewPath(string $view): string
    {
        // Convert dot notation to directory separators
        $path = str_replace('.', DIRECTORY_SEPARATOR, $view);
        return self::$viewPath . DIRECTORY_SEPARATOR . $path . '.php';
    }

    private static function resolveLayoutPath(string $layout): string
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $layout);
        return self::$layoutPath . DIRECTORY_SEPARATOR . $path . '.php';
    }
}
