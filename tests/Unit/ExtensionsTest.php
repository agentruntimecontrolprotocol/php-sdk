<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Extensions\ExtensionNamespace;
use Arcp\Extensions\ExtensionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function coreNames(): iterable
    {
        yield 'session.open' => ['session.open'];
        yield 'tool.invoke' => ['tool.invoke'];
        yield 'job.heartbeat' => ['job.heartbeat'];
        yield 'stream.chunk' => ['stream.chunk'];
        yield 'cancel' => ['cancel'];
        yield 'ack' => ['ack'];
        yield 'metric' => ['metric'];
        yield 'log' => ['log'];
    }

    #[DataProvider('coreNames')]
    public function testCoreTypesAcknowledgedAsCore(string $type): void
    {
        self::assertTrue(ExtensionNamespace::isCore($type));
        self::assertFalse(ExtensionNamespace::isValidExtension($type));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function extensionNames(): iterable
    {
        yield 'arcpx.example.v1' => ['arcpx.example.v1', true];
        yield 'arcpx.acme.workflow.v2' => ['arcpx.acme.workflow.v2', true];
        yield 'reverse-dns' => ['com.acme.workflow.v2', true];
        yield 'too short' => ['arcpx', false];
        yield 'empty' => ['', false];
        yield 'uppercase first segment rejected' => ['ArcpX.example.v1', false];
        yield 'core prefix rejected' => ['session.foo', false];
        yield 'bare x- rejected (not a type name)' => ['x-foo', false];
    }

    #[DataProvider('extensionNames')]
    public function testExtensionRecognition(string $type, bool $valid): void
    {
        self::assertSame($valid, ExtensionNamespace::isValidExtension($type));
    }

    public function testEnsureValidExtensionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExtensionNamespace::ensureValidExtension('not.valid.v_NOPE');
    }

    public function testRegistryAdvertisesAndQueries(): void
    {
        $reg = new ExtensionRegistry(['arcpx.acme.v1', 'com.example.foo.v3']);
        self::assertTrue($reg->isAdvertised('arcpx.acme.v1'));
        self::assertTrue($reg->isAdvertised('com.example.foo.v3'));
        self::assertFalse($reg->isAdvertised('arcpx.unknown.v1'));
        self::assertSame(['arcpx.acme.v1', 'com.example.foo.v3'], $reg->listAdvertised());
    }

    public function testRegistryRejectsInvalidExtensionAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExtensionRegistry(['session.open']);
    }

    public function testDispositionForCoreType(): void
    {
        $reg = new ExtensionRegistry();
        self::assertSame('core', $reg->dispositionFor('session.open', false));
    }

    public function testDispositionForAdvertisedExtension(): void
    {
        $reg = new ExtensionRegistry(['arcpx.acme.v1']);
        self::assertSame('advertised', $reg->dispositionFor('arcpx.acme.v1', false));
    }

    public function testDispositionForUnadvertisedOptionalIsDrop(): void
    {
        $reg = new ExtensionRegistry();
        self::assertSame('drop', $reg->dispositionFor('arcpx.unknown.v1', true));
    }

    public function testDispositionForUnadvertisedMandatoryIsNack(): void
    {
        $reg = new ExtensionRegistry();
        self::assertSame('nack', $reg->dispositionFor('arcpx.unknown.v1', false));
    }

    public function testDispositionForMalformedTypeIsNack(): void
    {
        $reg = new ExtensionRegistry();
        self::assertSame('nack', $reg->dispositionFor('totally invalid', false));
        self::assertSame('nack', $reg->dispositionFor('totally invalid', true));
    }
}
