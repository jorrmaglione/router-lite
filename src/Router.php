<?php

declare(strict_types=1);

namespace Jorrmaglione\RouterLite;

use Jorrmaglione\RouterLite\exceptions\NotFoundException;
use Jorrmaglione\RouterLite\exceptions\MethodNotAllowedException;

/**
 *
 */
final class Router {
    /**
     * @var Route[]
     */
    private array $routes;
    /**
     * @var mixed
     */
    private mixed $notFoundHandler;
    /**
     * @var string
     */
    private string $basePath;

    public function __construct() {
        $this->routes = [];
        $this->basePath = '';
        $this->notFoundHandler = null;
    }

    /**
     * @param string $basePath
     *
     * @return void
     */
    public function setBasePath(string $basePath): void {
        $basePath = '/' . trim($basePath, '/');
        $this->basePath = ($basePath === '/') ? '' : $basePath;
    }

    /**
     * @param callable $handler
     *
     * @return void
     */
    public function setNotFound(callable $handler): void {
        $this->notFoundHandler = $handler;
    }

    /**
     * @param string     $pattern
     * @param callable   $handler
     * @param callable[] $before
     * @param callable[] $after
     *
     * @return void
     */
    public function get(string $pattern, callable $handler, array $before = [], array $after = []): void {
        $this->add('GET', $pattern, $handler, $before, $after);
    }

    /**
     * @param string     $pattern
     * @param callable   $handler
     * @param callable[] $before
     * @param callable[] $after
     *
     * @return void
     */
    public function post(string $pattern, callable $handler, array $before = [], array $after = []): void {
        $this->add('POST', $pattern, $handler, $before, $after);
    }

    /**
     * @param string     $pattern
     * @param callable   $handler
     * @param callable[] $before
     * @param callable[] $after
     *
     * @return void
     */
    public function put(string $pattern, callable $handler, array $before = [], array $after = []): void {
        $this->add('PUT', $pattern, $handler, $before, $after);
    }

    /**
     * @param string     $pattern
     * @param callable   $handler
     * @param callable[] $before
     * @param callable[] $after
     *
     * @return void
     */
    public function delete(string $pattern, callable $handler, array $before = [], array $after = []): void {
        $this->add('DELETE', $pattern, $handler, $before, $after);
    }

    /**
     * @param string        $pattern
     * @param callable|null $handler
     *
     * @return void
     */
    public function options(string $pattern, callable $handler = null): void {
        // If no handler, we’ll auto answer Allow in dispatch()
        $this->add('OPTIONS', $pattern, $handler ?? fn() => null, [], []);
    }

    /**
     * @param string        $pattern
     * @param callable|null $handler
     *
     * @return void
     */
    public function head(string $pattern, callable $handler = null): void {
        // If no handler, we fall back to GET handler without a body
        $this->add('HEAD', $pattern, $handler ?? fn() => null, [], []);
    }

    /**
     * Dispatch the current request (or supplied method/URI).
     * - HEAD falls back to GET (common practice).
     * - Returns 404 if no route; 405 + Allow if path matched but method not allowed.
     * - Returns 405 if no route and no handler for OPTIONS.
     *
     * @param string|null $method
     * @param string|null $uri
     *
     * @throws MethodNotAllowedException
     * @throws NotFoundException
     */
    public function run(?string $method = null, ?string $uri = null): void {
        $method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url($uri ?? ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';

        // strip configured basePath
        if ($this->basePath && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath)) ?: '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Prefer the exact method; if HEAD, also try GET
        $methodsToTry = ($method === 'HEAD') ? ['HEAD', 'GET'] : [$method];

        foreach ($methodsToTry as $methodToTry) {
            $result = $this->match($methodToTry, $path);

            if ($result !== null) {
                // before middleware
                foreach ($result['handler']['before'] as $mw) {
                    if (call_user_func_array($mw, $result['vars']) === false) {
                        return;
                    }
                }
                
                // controller
                call_user_func_array($result['handler']['controller'], $result['vars']);

                // after middleware
                foreach ($result['handler']['after'] as $mw) {
                    if (call_user_func_array($mw, $result['vars']) === false) {
                        return;
                    }
                }

                return;
            }
        }

        // If we get here, either no path matched (404) or only wrong-method matches exist (405).
        // Detect 405 by scanning paths that match regardless of method:
        $allow = $this->methodsForPath($path);

        if ($allow) {
            header('Allow: ' . implode(', ', $allow));
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        http_response_code(404);
        $this->notFoundHandler ? call_user_func($this->notFoundHandler) : print 'Not Found';
    }

    /**
     * @param string     $method
     * @param string     $pattern
     * @param callable   $controller
     * @param callable[] $before
     * @param callable[] $after
     *
     * @return void
     */
    private function add(string $method, string $pattern, callable $controller, array $before, array $after): void {
        $newRoute = Route::create($pattern);

        $route = $this->routes[$newRoute->getTemplate()] ?? null;
        if ($route !== null) {
            $this->routes[$newRoute->getTemplate()] = $route->withHandler($method, $controller, $before, $after);
            return;
        }

        $this->routes[$newRoute->getTemplate()] = $newRoute->withHandler($method, $controller, $before, $after);
    }

    /**
     * @param string $method
     * @param string $path
     *
     * @return array{route:Route,vars:string[],handler:callable,allow:string[]}|null
     */
    private function match(string $method, string $path): ?array {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route->getCompiled(), $path, $m)) {
                continue;
            }

            // path-matched — record allowed methods for potential 405
            foreach ($route->allowedMethods() as $am) {
                $allowed[$am] = true;
            }

            $handler = $route->handlerFor($method);
            if (!$handler) {
                continue; // wrong method for this route; keep scanning (there could be another route with the same path)
            }

            // extract named captures first; fallback to numeric
            $vars = [];
            foreach ($m as $k => $v) {
                if (!is_int($k)) {
                    $vars[] = $v;
                }
            }

            if (!empty($vars)) {
                array_shift($m);
                $vars = $m;
            }

            return [
                'route' => $route,
                'vars' => $vars,
                'handler' => $handler,
                'allow' => array_keys($allowed),
            ];
        }

        return null; // no path matched at all
    }

    /**
     * Return the union of allowed methods for routes matching $path (path-only check).
     *
     * @param string $path
     *
     * @return array
     */
    private function methodsForPath(string $path): array {
        $allow = [];
        foreach ($this->routes as $route) {
            if (preg_match($route->getCompiled(), $path)) {
                foreach ($route->allowedMethods() as $m) {
                    $allow[$m] = true;
                }
            }
        }
        return array_keys($allow);
    }
}
