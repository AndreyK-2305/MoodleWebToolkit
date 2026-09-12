<?php

namespace Tests\Unit;

use App\Domain\Artifacts\ResumableSha256;
use PHPUnit\Framework\TestCase;

class ResumableSha256Test extends TestCase
{
    public function test_known_vectors_block_boundaries_binary_utf8_and_resumption_match_php(): void
    {
        $vectors = [
            '',
            'abc',
            'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq',
            str_repeat('a', 55),
            str_repeat('b', 56),
            str_repeat('c', 63),
            str_repeat('d', 64),
            str_repeat('e', 65),
            implode('', array_map(chr(...), range(0, 255))),
            str_repeat("á🙂\0", 137),
        ];

        foreach ($vectors as $contents) {
            $offsets = array_values(array_unique([0, min(1, strlen($contents)), intdiv(strlen($contents), 2), strlen($contents)]));

            foreach ($offsets as $offset) {
                $hash = ResumableSha256::start();
                $hash->update(substr($contents, 0, $offset));
                $resumed = ResumableSha256::resume($hash->state());

                foreach (str_split(substr($contents, $offset), 1) as $byte) {
                    $resumed->update($byte);
                }

                $expected = hash('sha256', $contents);
                $this->assertSame(strlen($contents), $resumed->length());
                $this->assertSame($expected, $resumed->finish());
                $this->assertSame($expected, $resumed->finish());
            }
        }
    }

    public function test_invalid_or_tampered_persisted_state_is_rejected(): void
    {
        $valid = ResumableSha256::start()->state();
        $invalidStates = [
            [...$valid, 'hash' => [-1, ...array_slice($valid['hash'], 1)]],
            [...$valid, 'hash' => [0x1_0000_0000, ...array_slice($valid['hash'], 1)]],
            [...$valid, 'hash' => ['not-an-integer', ...array_slice($valid['hash'], 1)]],
            [...$valid, 'buffer' => base64_encode(str_repeat('x', 64)), 'length' => 64],
            [...$valid, 'length' => -1],
            [...$valid, 'buffer' => base64_encode('abc'), 'length' => 4],
            [...$valid, 'buffer' => '***'],
        ];

        foreach ($invalidStates as $index => $state) {
            try {
                ResumableSha256::resume($state);
                $this->fail("El estado SHA-256 manipulado {$index} debía rechazarse.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
