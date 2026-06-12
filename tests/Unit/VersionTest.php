<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testProtocolVersionIsOnePointOne(): void
    {
        self::assertSame('1.1', Version::PROTOCOL_VERSION);
    }

    public function testImplKindIsStable(): void
    {
        self::assertSame('arcp-php', Version::IMPL_NAME);
    }

    public function testImplVersionIsSemverLike(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[A-Za-z0-9.-]+)?$/',
            Version::IMPL_VERSION,
        );
    }
}
