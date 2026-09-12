<?php

namespace App\Domain\Artifacts;

use InvalidArgumentException;

final class ResumableSha256
{
    /** @var list<int> */
    private array $hash;

    private string $buffer;

    private int $length;

    /**
     * @param  list<int>|null  $hash
     */
    private function __construct(?array $hash = null, string $buffer = '', int $length = 0)
    {
        $this->hash = $hash ?? [
            0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A,
            0x510E527F, 0x9B05688C, 0x1F83D9AB, 0x5BE0CD19,
        ];
        $this->buffer = $buffer;
        $this->length = $length;
    }

    public static function start(): self
    {
        return new self;
    }

    /** @param array<string, mixed> $state */
    public static function resume(array $state): self
    {
        $encodedBuffer = $state['buffer'] ?? null;
        $hash = $state['hash'] ?? null;
        $length = $state['length'] ?? null;

        if (! is_string($encodedBuffer)
            || ! is_array($hash)
            || ! array_is_list($hash)
            || count($hash) !== 8
            || ! is_int($length)
            || $length < 0
        ) {
            throw new InvalidArgumentException('El estado SHA-256 reanudable no es válido.');
        }

        $validatedHash = [];

        foreach ($hash as $word) {
            if (! is_int($word) || $word < 0 || $word > 0xFFFFFFFF) {
                throw new InvalidArgumentException('El estado SHA-256 reanudable no es válido.');
            }

            $validatedHash[] = $word;
        }

        $buffer = base64_decode($encodedBuffer, true);

        if ($buffer === false
            || strlen($buffer) >= 64
            || $length < strlen($buffer)
            || $length % 64 !== strlen($buffer)
        ) {
            throw new InvalidArgumentException('El estado SHA-256 reanudable no es válido.');
        }

        return new self($validatedHash, $buffer, $length);
    }

    public function update(string $bytes): void
    {
        $this->length += strlen($bytes);
        $bytes = $this->buffer.$bytes;
        $completeLength = strlen($bytes) - (strlen($bytes) % 64);

        for ($offset = 0; $offset < $completeLength; $offset += 64) {
            $this->compress(substr($bytes, $offset, 64));
        }

        $this->buffer = substr($bytes, $completeLength);
    }

    /** @return array{hash: list<int>, buffer: string, length: int} */
    public function state(): array
    {
        return [
            'hash' => $this->hash,
            'buffer' => base64_encode($this->buffer),
            'length' => $this->length,
        ];
    }

    public function length(): int
    {
        return $this->length;
    }

    public function finish(): string
    {
        $copy = new self($this->hash, $this->buffer, $this->length);
        $padding = "\x80";
        $zeroes = (56 - (($copy->length + 1) % 64) + 64) % 64;
        $high = intdiv($copy->length, 0x20000000);
        $low = ($copy->length << 3) & 0xFFFFFFFF;
        $copy->update($padding.str_repeat("\0", $zeroes).pack('N2', $high, $low));

        return bin2hex(pack('N8', ...$copy->hash));
    }

    private function compress(string $block): void
    {
        $constants = [
            0x428A2F98, 0x71374491, 0xB5C0FBCF, 0xE9B5DBA5, 0x3956C25B, 0x59F111F1, 0x923F82A4, 0xAB1C5ED5,
            0xD807AA98, 0x12835B01, 0x243185BE, 0x550C7DC3, 0x72BE5D74, 0x80DEB1FE, 0x9BDC06A7, 0xC19BF174,
            0xE49B69C1, 0xEFBE4786, 0x0FC19DC6, 0x240CA1CC, 0x2DE92C6F, 0x4A7484AA, 0x5CB0A9DC, 0x76F988DA,
            0x983E5152, 0xA831C66D, 0xB00327C8, 0xBF597FC7, 0xC6E00BF3, 0xD5A79147, 0x06CA6351, 0x14292967,
            0x27B70A85, 0x2E1B2138, 0x4D2C6DFC, 0x53380D13, 0x650A7354, 0x766A0ABB, 0x81C2C92E, 0x92722C85,
            0xA2BFE8A1, 0xA81A664B, 0xC24B8B70, 0xC76C51A3, 0xD192E819, 0xD6990624, 0xF40E3585, 0x106AA070,
            0x19A4C116, 0x1E376C08, 0x2748774C, 0x34B0BCB5, 0x391C0CB3, 0x4ED8AA4A, 0x5B9CCA4F, 0x682E6FF3,
            0x748F82EE, 0x78A5636F, 0x84C87814, 0x8CC70208, 0x90BEFFFA, 0xA4506CEB, 0xBEF9A3F7, 0xC67178F2,
        ];
        $unpacked = unpack('N16', $block);

        if ($unpacked === false) {
            throw new InvalidArgumentException('El bloque SHA-256 no es válido.');
        }

        /** @var list<int> $words */
        $words = array_values($unpacked);

        for ($index = 16; $index < 64; $index++) {
            $s0 = $this->rotate($words[$index - 15], 7) ^ $this->rotate($words[$index - 15], 18) ^ ($words[$index - 15] >> 3);
            $s1 = $this->rotate($words[$index - 2], 17) ^ $this->rotate($words[$index - 2], 19) ^ ($words[$index - 2] >> 10);
            $words[$index] = ($words[$index - 16] + $s0 + $words[$index - 7] + $s1) & 0xFFFFFFFF;
        }

        [$a, $b, $c, $d, $e, $f, $g, $h] = $this->hash;

        for ($index = 0; $index < 64; $index++) {
            $sum1 = $this->rotate($e, 6) ^ $this->rotate($e, 11) ^ $this->rotate($e, 25);
            $choice = ($e & $f) ^ ((~$e) & $g);
            $temporary1 = ($h + $sum1 + $choice + $constants[$index] + $words[$index]) & 0xFFFFFFFF;
            $sum0 = $this->rotate($a, 2) ^ $this->rotate($a, 13) ^ $this->rotate($a, 22);
            $majority = ($a & $b) ^ ($a & $c) ^ ($b & $c);
            $temporary2 = ($sum0 + $majority) & 0xFFFFFFFF;
            $h = $g;
            $g = $f;
            $f = $e;
            $e = ($d + $temporary1) & 0xFFFFFFFF;
            $d = $c;
            $c = $b;
            $b = $a;
            $a = ($temporary1 + $temporary2) & 0xFFFFFFFF;
        }

        $this->hash = [
            ($this->hash[0] + $a) & 0xFFFFFFFF,
            ($this->hash[1] + $b) & 0xFFFFFFFF,
            ($this->hash[2] + $c) & 0xFFFFFFFF,
            ($this->hash[3] + $d) & 0xFFFFFFFF,
            ($this->hash[4] + $e) & 0xFFFFFFFF,
            ($this->hash[5] + $f) & 0xFFFFFFFF,
            ($this->hash[6] + $g) & 0xFFFFFFFF,
            ($this->hash[7] + $h) & 0xFFFFFFFF,
        ];
    }

    private function rotate(int $value, int $bits): int
    {
        return (($value >> $bits) | (($value << (32 - $bits)) & 0xFFFFFFFF)) & 0xFFFFFFFF;
    }
}
