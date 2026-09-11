<?php

namespace Tests\Unit;

use App\Domain\Artifacts\ResumableSha256;
use PHPUnit\Framework\TestCase;

class ResumableSha256Test extends TestCase
{
    public function test_hash_state_can_resume_across_binary_and_utf8_chunks(): void
    {
        $contents = str_repeat("á\0binary", 40_000);
        $hash = ResumableSha256::start();
        $hash->update(substr($contents, 0, 65_537));
        $resumed = ResumableSha256::resume($hash->state());

        foreach (str_split(substr($contents, 65_537), 17_123) as $chunk) {
            $resumed->update($chunk);
        }

        $this->assertSame(strlen($contents), $resumed->length());
        $this->assertSame(hash('sha256', $contents), $resumed->finish());
        $this->assertSame(hash('sha256', $contents), $resumed->finish());
    }
}
