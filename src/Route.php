<?php

declare(strict_types=1);

namespace Jorrmaglione\RouterLite;

/**
 * Route class.
 */
final class Route {
    /**
     * Normalized template, e.g. "/users/{id:\d+}"
     *
     * @var string
     */
    private string $template;

    /**
     * PCRE, e.g. "~^/users/(?P<id>\d+)$~"
     *
     * @var string
     */
    private string $compiled;

    /**
     * @var string[]
     */
    private array $tokens;

    /**
     * @var array<string, array{controller: mixed, before: array, after: array}>
     */
    private array $handlers = [];

    /**
     * Route constructor.
     *
     * @param string   $template
     * @param string   $compiled
     * @param string[] $tokens
     */
    private function __construct(string $template, string $compiled, array $tokens) {
        $this->template = $template;
        $this->compiled = $compiled;
        $this->tokens = $tokens;
    }

    /**
     * Normalize a route pattern.
     *
     * @param string $pattern
     *
     * @return string
     */
    private static function normalize(string $pattern): string {
        if ($pattern === '') {
            $pattern = '/';
        }

        if ($pattern !== '/' && str_ends_with($pattern, '/')) {
            $pattern = rtrim($pattern, '/');
        }

        return $pattern;
    }

    /**
     * Compile a route pattern into a PCRE.
     *
     * @param string $pattern
     *
     * @return array
     */
    private static function compile(string $pattern): array {
        // Short-circuit raw PCRE BEFORE doing any replacements.
        if (preg_match('/^[#~].*\^/', $pattern)) {
            return [$pattern, /* tokens */ []];
        }

        $tokens = [];

        // {name:regex}
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*):(.+?)}/',
            function ($m) use (&$tokens) {
                $tokens[] = $m[1];
                return '(?P<' . $m[1] . '>' . $m[2] . ')';
            },
            $pattern
        );

        // {name} -> default segment [^/]+
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)}/',
            function ($m) use (&$tokens) {
                $tokens[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $regex
        );

        return ['~^' . $regex . '$~', $tokens];
    }

    /**
     * Create a new route.
     *
     * @param string $template
     *
     * @return Route
     */
    public static function create(string $template): self {
        $template = self::normalize($template);
        [$compiled, $tokens] = self::compile($template);
        return new self($template, $compiled, $tokens);
    }

    /**
     * @return string
     */
    public function getTemplate(): string {
        return $this->template;
    }

    /**
     * @return string
     */
    public function getCompiled(): string {
        return $this->compiled;
    }

    /**
     * @return array
     */
    public function getTokens(): array {
        return $this->tokens;
    }

    /**
     * Get the allowed methods for this route.
     *
     * @return array
     */
    public function allowedMethods(): array {
        return array_keys($this->handlers);
    }

    /**
     * Set the handler for a given HTTP method. (Immutable)
     *
     * @param string $method
     * @param mixed  $controller
     * @param array  $before
     * @param array  $after
     *
     * @return self
     */
    public function withHandler(string $method, mixed $controller, array $before = [], array $after = []): self {
        $method = strtoupper($method);
        $clone = clone $this;
        $clone->handlers[strtoupper($method)] = [
            'controller' => $controller,
            'before' => array_values($before),
            'after' => array_values($after),
        ];
        return $clone;
    }

    /**
     * Get the handler for a given HTTP method.
     *
     * @return array{controller: callable, before: callable[], after: callable[]}|null
     */
    public function handlerFor(string $method): ?array {
        $m = strtoupper($method);
        return $this->handlers[$m] ?? null;
    }
}
