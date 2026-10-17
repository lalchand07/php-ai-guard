<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

final class EmailPattern extends RegexPattern
{
    public function __construct()
    {
        parent::__construct('EMAIL', '/(?<![\w.+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![\w-])/');
    }
}
