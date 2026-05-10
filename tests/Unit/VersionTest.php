<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testProtocolVersionIsOnePointZero(): void
    {
        self::assertSame('1.0', Version::PROTOCOL_VERSION);
    }

    public function testImplKindIsStable(): void
    {
        self::assertSame('arcp-php', Version::IMPL_KIND);
    }

    public function testImplVersionIsSemverLike(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[A-Za-z0-9.-]+)?$/',
            Version::IMPL_VERSION,
        );
    }
}
