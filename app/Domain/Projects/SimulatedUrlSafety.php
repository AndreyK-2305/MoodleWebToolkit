<?php

namespace App\Domain\Projects;

class SimulatedUrlSafety
{
    public const MAX_LENGTH = 255;

    public function validationError(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return 'La URL es obligatoria.';
        }

        if (mb_strlen($url) > self::MAX_LENGTH) {
            return 'La URL no puede superar 255 caracteres.';
        }

        try {
            $parts = @parse_url($url);
        } catch (\ValueError) {
            $parts = false;
        }

        if (! is_array($parts)) {
            return 'Use una URL HTTP o HTTPS válida.';
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Use una URL HTTP o HTTPS válida.';
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return 'La URL no puede incluir usuario ni contraseña.';
        }

        $port = $parts['port'] ?? null;

        if ($port !== null && filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]) === false) {
            return 'La URL contiene un puerto no válido.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Use una URL HTTP o HTTPS válida.';
        }

        return null;
    }

    public function isSafe(?string $url): bool
    {
        return $this->validationError($url) === null;
    }

    public function safeDisplayValue(?string $url): string
    {
        return $this->isSafe($url) ? (string) $url : '';
    }
}
