<?php

declare(strict_types=1);

namespace Maxx\Ai\Exceptions;

final class VerificationException extends SdkException
{
    public function __construct()
    {
        parent::__construct('Untrusted billing signature, claims, or delivery.');
    }
}
