<?php

namespace Tests\Unit;

use App\Domain\Remote\RemoteCommandRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoteCommandRegistryTest extends TestCase
{
    private const SHA = '4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e';

    public function test_collector_operation_carries_only_identifiers_never_paths(): void
    {
        $command = app(RemoteCommandRegistry::class)->resolve('collector.742', [
            'workers' => 'auto', 'source' => 'synthetic-lab', 'package' => 'origen-lab', 'distribution' => self::SHA,
        ]);

        $this->assertTrue($command['cancellable']);
        $this->assertSame(['distribution='.self::SHA, 'package=origen-lab', 'source=synthetic-lab', 'workers=auto'], $command['arguments']);
    }

    /** @param array<string, string> $parameters */
    #[DataProvider('invalidCollectorParameters')]
    public function test_collector_parameters_are_closed(array $parameters): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RemoteCommandRegistry::class)->resolve('collector.742', $parameters);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidCollectorParameters(): iterable
    {
        $valid = ['distribution' => self::SHA, 'source' => 'synthetic-lab', 'package' => 'origen-lab', 'workers' => '2'];

        yield 'path as source' => [[...$valid, 'source' => '../etc/passwd']];
        yield 'config path' => [[...$valid, 'source' => '/var/www/html/config.php']];
        yield 'package with extension' => [[...$valid, 'package' => 'origen.zip']];
        yield 'package uppercase' => [[...$valid, 'package' => 'Origen']];
        yield 'short hash' => [[...$valid, 'distribution' => 'abc123']];
        yield 'workers out of range' => [[...$valid, 'workers' => '8']];
        yield 'missing workers' => [array_diff_key($valid, ['workers' => true])];
        yield 'extra option' => [[...$valid, 'restart' => '1']];
    }
}
