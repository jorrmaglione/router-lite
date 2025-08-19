<?php

namespace Jorrmaglione\RouterLite\exceptions;

use Exception;
use JetBrains\PhpStorm\Pure;
use Throwable;

/**
 *
 */
class NotFoundException extends Exception {
    /**
     * @param string         $message
     * @param int            $code
     * @param Throwable|null $previous
     */
    #[Pure]
    public function __construct(string $message = "", int $code = 404, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}