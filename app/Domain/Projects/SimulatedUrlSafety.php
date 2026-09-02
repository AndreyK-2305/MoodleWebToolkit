<?php

namespace App\Domain\Projects;

class SimulatedUrlSafety
{
    public function hasEmbeddedCredentials(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && (array_key_exists('user', $parts) || array_key_exists('pass', $parts));
    }

    public function safeDisplayValue(?string $url): string
    {
        return $this->hasEmbeddedCredentials($url) ? '' : ($url ?? '');
    }
}
