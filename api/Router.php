<?php

declare(strict_types=1);

namespace PorscheConnect\Api;

class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);
        $path = $this->normalize(parse_url($path, PHP_URL_PATH) ?: '/');

        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $params = $this->match($route, $path);
            if ($params !== null) {
                return $handler($params);
            }
        }

        return null;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @return array<string, string>|null
     */
    private function match(string $route, string $path): ?array
    {
        $pattern = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $route);
        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
