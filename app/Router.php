<?php
declare(strict_types=1);

namespace App;

use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:array<string,string>,3:mixed}> */
    private array $routes = [];

    public function get(string $path, $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);
        $this->routes[] = [$method, '#^' . $regex . '$#', $params, $handler];
    }

    public function dispatch(string $method, string $path): never
    {
        $method = strtoupper($method);
        $path = $path !== '/' ? rtrim($path, '/') : '/';

        if (str_starts_with($path, '/api/') && !in_array($method, ['GET', 'HEAD'], true)) {
            Auth::checkOrigin();
            Auth::requireCsrf();
        }

        foreach ($this->routes as [$routeMethod, $regex, $names, $handler]) {
            if ($routeMethod !== $method || preg_match($regex, $path, $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($names as $index => $name) {
                $params[$name] = rawurldecode((string) ($matches[$index] ?? ''));
            }
            try {
                if (is_array($handler) && is_string($handler[0] ?? null)) {
                    $handler = [new $handler[0](), $handler[1]];
                }
                $handler($params, new Request());
            } catch (HttpError $e) {
                \App\Support\Response::error($e->getMessage(), $e->status, $e->extra);
            }
            exit;
        }

        if (str_starts_with($path, '/api/')) {
            \App\Support\Response::error('接口不存在', 404);
        }
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
}
