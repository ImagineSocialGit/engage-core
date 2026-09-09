<?php

namespace App\Support\HumanVerification\Data;

final readonly class HumanVerificationRequest
{
    /**
     * @param list<string> $expectedHostnames
     */
    public function __construct(
        public string $surface,
        public string $token,
        public string $expectedAction,
        public array $expectedHostnames,
        public ?string $remoteIp = null,
    ) {}
}