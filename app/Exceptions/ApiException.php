<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiException extends HttpException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $statusCode = 400,
    ) {
        parent::__construct($statusCode, $message);
    }
}
