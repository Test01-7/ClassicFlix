<?php
declare(strict_types=1);
namespace ClassicFlix\Support;

final class ValidationException extends HttpException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(422, 'Revisa los campos indicados.');
    }
}
