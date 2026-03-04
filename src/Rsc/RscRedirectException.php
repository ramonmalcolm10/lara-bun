<?php

namespace LaraBun\Rsc;

class RscRedirectException extends \RuntimeException
{
    public function __construct(
        private string $location,
        private int $status = 302,
    ) {
        parent::__construct("Redirect to {$location}");
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
