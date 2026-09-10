<?php

declare(strict_types=1);

namespace Mazaya\License\Exceptions;

use RuntimeException;

class InvalidLicense extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
