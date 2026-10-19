<?php

declare(strict_types=1);

namespace Maxx\Ai\Auth;

interface TokenProvider
{
    public function token(): string;
}
