<?php

declare(strict_types=1);

namespace Aether\Router;

/**
 * Radix Tree (Compressed Trie) Router — O(K) complexity, zero regex.
 *
 * @package Aether\Router
 */
final class RadixTree
{
    private RadixNode $root;
    /** @var array<string, RouteEntry> */
    private array $namedRoutes = [];
    private int $routeCount = 0;

    public function __construct()
    {
        $this->root = new RadixNode('/');
    }

    public function insert(
        string $method, string $path, string $handler,
        string $controllerClass = '', string $controllerMethod = '',
        array $middleware = [], string $name = '', array $defaults = [],
    ): void {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $entry = new RouteEntry($method, $path, $handler, $controllerClass, $controllerMethod, $middleware, $name, $defaults);
        if ($name !== '') { $this->namedRoutes[$name] = $entry; }
        $segments = $this->splitPath($path);
        $node = $this->root;

        foreach ($segments as $segment) {
            if ($segment === '') { continue; }
            if ($this->isWildcard($segment)) {
                $pn = substr($segment, 1, -2);
                if ($node->wildcardChild === null) {
                    $node->wildcardChild = new RadixNode($segment);
                    $node->wildcardChild->wildcardName = $pn;
                }
                $node = $node->wildcardChild;
                break;
            }
            if ($this->isDynamic($segment)) {
                $pn = substr($segment, 1, -1);
                if ($node->paramChild === null) {
                    $node->paramChild = new RadixNode($segment);
                    $node->paramChild->paramName = $pn;
                }
                $node = $node->paramChild;
                continue;
            }
            $fc = $segment[0];
            if (isset($node->children[$fc])) {
                $child = $node->children[$fc];
                $cl = $this->commonPrefixLength($child->segment, $segment);
                if ($cl < strlen($child->segment)) {
                    $node = $this->splitNode($node, $child, $fc, $cl, $segment);
                } elseif ($cl < strlen($segment)) {
                    $rem = substr($segment, $cl);
                    $rf = $rem[0];
                    if (!isset($child->children[$rf])) {
                        $child->children[$rf] = new RadixNode($rem);
                    }
                    $node = $child->children[$rf];
                } else {
                    $node = $child;
                }
            } else {
                $nn = new RadixNode($segment);
                $node->children[$fc] = $nn;
                $node = $nn;
            }
        }
        $node->handlers[$method] = $entry;
        $node->isLeaf = true;
        $this->routeCount++;
    }

    public function match(string $method, string $path): ?MatchResult
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        return $this->matchNode($this->root, $this->splitPath($path), 0, $method, []);
    }

    /** @return array<string> */
    public function matchAnyMethod(string $path): array
    {
        $path = '/' . trim($path, '/');
        $params = [];
        $node = $this->findNode($this->root, $this->splitPath($path), 0, $params);
        return ($node !== null && $node->isLeaf) ? $node->getAllowedMethods() : [];
    }

    public function generate(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route '{$name}' not found");
        }
        $path = $this->namedRoutes[$name]->path;
        foreach ($params as $k => $v) {
            $ph = '{' . $k . '}';
            $pos = strpos($path, $ph);
            if ($pos !== false) { $path = substr($path, 0, $pos) . $v . substr($path, $pos + strlen($ph)); }
            $wph = '{' . $k . '*}';
            $wp = strpos($path, $wph);
            if ($wp !== false) { $path = substr($path, 0, $wp) . $v . substr($path, $wp + strlen($wph)); }
        }
        return $path;
    }

    public function getRouteCount(): int { return $this->routeCount; }
    public function getRoot(): RadixNode { return $this->root; }
    /** @return array<string, RouteEntry> */
    public function getNamedRoutes(): array { return $this->namedRoutes; }

    /** @return array<string, mixed> */
    public function export(): array { return $this->exportNode($this->root); }

    public function import(array $data): void
    {
        $this->root = $this->importNode($data);
        $this->routeCount = 0;
        $this->namedRoutes = [];
        $this->rebuildNameIndex($this->root);
    }

    // ── Private: Traversal ──

    private function matchNode(RadixNode $node, array $segments, int $idx, string $method, array $params): ?MatchResult
    {
        if ($idx >= count($segments)) {
            return ($node->isLeaf && isset($node->handlers[$method]))
                ? new MatchResult($node->handlers[$method], $params) : null;
        }
        $seg = $segments[$idx];
        // Static match. Highest priority.
        if ($seg !== '' && isset($node->children[$seg[0]])) {
            $c = $node->children[$seg[0]];
            if ($c->segment === $seg) {
                $r = $this->matchNode($c, $segments, $idx + 1, $method, $params);
                if ($r !== null) return $r;
            }
        }
        // Dynamic param. Slower than static, but better than regex.
        if ($node->paramChild !== null) {
            $pp = $params;
            $pp[$node->paramChild->paramName] = $seg;
            $r = $this->matchNode($node->paramChild, $segments, $idx + 1, $method, $pp);
            if ($r !== null) return $r;
        }
        // Wildcard
        if ($node->wildcardChild !== null) {
            $rem = implode('/', array_slice($segments, $idx));
            $wp = $params;
            $wp[$node->wildcardChild->wildcardName] = $rem;
            if ($node->wildcardChild->isLeaf && isset($node->wildcardChild->handlers[$method])) {
                return new MatchResult($node->wildcardChild->handlers[$method], $wp);
            }
        }
        return null;
    }

    private function findNode(RadixNode $node, array $segments, int $idx, array &$params): ?RadixNode
    {
        if ($idx >= count($segments)) return $node;
        $seg = $segments[$idx];
        if ($seg !== '' && isset($node->children[$seg[0]])) {
            $c = $node->children[$seg[0]];
            if ($c->segment === $seg) {
                $f = $this->findNode($c, $segments, $idx + 1, $params);
                if ($f !== null) return $f;
            }
        }
        if ($node->paramChild !== null) {
            $params[$node->paramChild->paramName] = $seg;
            $f = $this->findNode($node->paramChild, $segments, $idx + 1, $params);
            if ($f !== null) return $f;
        }
        if ($node->wildcardChild !== null) {
            $params[$node->wildcardChild->wildcardName] = implode('/', array_slice($segments, $idx));
            return $node->wildcardChild;
        }
        return null;
    }

    // ── Private: Node Manipulation ──

    private function splitNode(RadixNode $parent, RadixNode $existing, string $fc, int $cl, string $newSeg): RadixNode
    {
        $cp = substr($existing->segment, 0, $cl);
        $er = substr($existing->segment, $cl);
        $nr = substr($newSeg, $cl);
        $inter = new RadixNode($cp);
        $existing->segment = $er;
        $inter->children[$er[0]] = $existing;
        $parent->children[$fc] = $inter;
        if ($nr !== '' && $nr !== false) {
            $nc = new RadixNode($nr);
            $inter->children[$nr[0]] = $nc;
            return $nc;
        }
        return $inter;
    }

    // ── Private: String Utilities (No Regex. Keep it out of my codebase) ──

    /** @return array<string> */
    private function splitPath(string $path): array
    {
        $segs = [];
        $cur = '';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            if ($path[$i] === '/') {
                if ($cur !== '') { $segs[] = $cur; $cur = ''; }
            } else { $cur .= $path[$i]; }
        }
        if ($cur !== '') $segs[] = $cur;
        return $segs;
    }

    private function isDynamic(string $s): bool
    {
        $l = strlen($s);
        return $l >= 3 && $s[0] === '{' && $s[$l - 1] === '}' && $s[$l - 2] !== '*';
    }

    private function isWildcard(string $s): bool
    {
        $l = strlen($s);
        return $l >= 4 && $s[0] === '{' && $s[$l - 1] === '}' && $s[$l - 2] === '*';
    }

    private function commonPrefixLength(string $a, string $b): int
    {
        $max = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $max && $a[$i] === $b[$i]) $i++;
        return $i;
    }

    // ── Private: Serialization ──

    private function exportNode(RadixNode $node): array
    {
        $d = ['segment' => $node->segment, 'isLeaf' => $node->isLeaf, 'handlers' => [], 'children' => []];
        foreach ($node->handlers as $m => $e) {
            $d['handlers'][$m] = ['method' => $e->method, 'path' => $e->path, 'handler' => $e->handler,
                'controllerClass' => $e->controllerClass, 'controllerMethod' => $e->controllerMethod,
                'middleware' => $e->middleware, 'name' => $e->name, 'defaults' => $e->defaults];
        }
        foreach ($node->children as $k => $c) $d['children'][$k] = $this->exportNode($c);
        if ($node->paramChild !== null) { $d['paramChild'] = $this->exportNode($node->paramChild); $d['paramName'] = $node->paramChild->paramName; }
        if ($node->wildcardChild !== null) { $d['wildcardChild'] = $this->exportNode($node->wildcardChild); $d['wildcardName'] = $node->wildcardChild->wildcardName; }
        return $d;
    }

    private function importNode(array $data): RadixNode
    {
        $n = new RadixNode($data['segment'] ?? '');
        $n->isLeaf = $data['isLeaf'] ?? false;
        foreach (($data['handlers'] ?? []) as $m => $h) {
            $n->handlers[$m] = new RouteEntry($h['method'], $h['path'], $h['handler'], $h['controllerClass'] ?? '', $h['controllerMethod'] ?? '', $h['middleware'] ?? [], $h['name'] ?? '', $h['defaults'] ?? []);
        }
        foreach (($data['children'] ?? []) as $k => $cd) $n->children[$k] = $this->importNode($cd);
        if (isset($data['paramChild'])) { $n->paramChild = $this->importNode($data['paramChild']); $n->paramChild->paramName = $data['paramName'] ?? null; }
        if (isset($data['wildcardChild'])) { $n->wildcardChild = $this->importNode($data['wildcardChild']); $n->wildcardChild->wildcardName = $data['wildcardName'] ?? null; }
        return $n;
    }

    private function rebuildNameIndex(RadixNode $node): void
    {
        foreach ($node->handlers as $e) { if ($e->name !== '') $this->namedRoutes[$e->name] = $e; $this->routeCount++; }
        foreach ($node->children as $c) $this->rebuildNameIndex($c);
        if ($node->paramChild !== null) $this->rebuildNameIndex($node->paramChild);
        if ($node->wildcardChild !== null) $this->rebuildNameIndex($node->wildcardChild);
    }
}
