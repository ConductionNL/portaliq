<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR file reader: it degrades to a safe empty/null result without
 * OpenRegister or on any OR error (never an exception to the caller), it never
 * exposes the raw stored path in a listing, and it delegates streaming to
 * OpenRegister's own `FileService::streamFile()` rather than re-implementing
 * Content-Disposition sanitisation.
 *
 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
 * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
 */
class PortalFileReaderTest extends TestCase
{

    private const FS = 'OCA\\OpenRegister\\Service\\FileService';

    public function testListFilesReturnsEmptyArrayWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertSame([], $reader->listFiles('d-1'));

    }//end testListFilesReturnsEmptyArrayWhenOpenRegisterUnavailable()

    public function testListFilesReturnsEmptyArrayWhenOpenRegisterThrows(): void
    {
        $fileService = new class {
            public function getFiles(string $object): array
            {
                throw new RuntimeException('OR error');
            }//end getFiles()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id) => ($id === self::FS ? $fileService : throw new RuntimeException('unexpected'))
        );

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertSame([], $reader->listFiles('d-1'));

    }//end testListFilesReturnsEmptyArrayWhenOpenRegisterThrows()

    /**
     * Only safe metadata (id/name/size) is ever shaped into the listing — the
     * raw stored path is never exposed, even if the underlying node exposed one.
     */
    public function testListFilesShapesToSafeMetadataOnlyNoStoredPath(): void
    {
        $node = new class {
            public function getId(): int
            {
                return 42;
            }//end getId()

            public function getName(): string
            {
                return 'besluit.pdf';
            }//end getName()

            public function getSize(): int
            {
                return 1024;
            }//end getSize()

            public function getPath(): string
            {
                return '/files/__groupfolders/1/portaliq/objects/d-1/besluit.pdf';
            }//end getPath()
        };

        $fileService = new class ($node) {
            public function __construct(private readonly object $node)
            {
            }//end __construct()

            public function getFiles(string $object): array
            {
                return [$this->node];
            }//end getFiles()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($fileService);

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $files  = $reader->listFiles('d-1');

        $this->assertSame([['id' => 42, 'name' => 'besluit.pdf', 'size' => 1024]], $files);
        foreach ($files as $file) {
            $this->assertArrayNotHasKey('path', $file);
        }

    }//end testListFilesShapesToSafeMetadataOnlyNoStoredPath()

    public function testStreamFileReturnsNullWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertNull($reader->streamFile('d-1', '42'));

    }//end testStreamFileReturnsNullWhenOpenRegisterUnavailable()

    /**
     * A fileId that does not resolve inside the OWNED object's own folder (a
     * foreign file, or none at all) yields null — identical to any other
     * refusal, no existence oracle.
     */
    public function testStreamFileReturnsNullWhenFileDoesNotResolve(): void
    {
        $fileService = new class {
            public function getFile(string $object, string $file): ?object
            {
                return null;
            }//end getFile()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($fileService);

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertNull($reader->streamFile('d-1', 'not-mine'));

    }//end testStreamFileReturnsNullWhenFileDoesNotResolve()

    public function testStreamFileReturnsNullWhenOpenRegisterThrows(): void
    {
        $fileService = new class {
            public function getFile(string $object, string $file): ?object
            {
                throw new RuntimeException('OR error');
            }//end getFile()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($fileService);

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertNull($reader->streamFile('d-1', '42'));

    }//end testStreamFileReturnsNullWhenOpenRegisterThrows()

    /**
     * When the file resolves, streaming is DELEGATED to OpenRegister's own
     * `FileService::streamFile()` (never re-implemented here) and its result is
     * returned unchanged.
     */
    public function testStreamFileDelegatesToOpenRegistersStreamFileOnSuccess(): void
    {
        $file     = $this->createMock(\OCP\Files\File::class);
        $expected = $this->createMock(StreamResponse::class);

        $fileService = new class ($file, $expected) {
            public function __construct(private readonly object $file, private readonly object $stream)
            {
            }//end __construct()

            public function getFile(string $object, string $file): object
            {
                return $this->file;
            }//end getFile()

            public function streamFile(object $file): object
            {
                return $this->stream;
            }//end streamFile()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($fileService);

        $reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
        $this->assertSame($expected, $reader->streamFile('d-1', '42'));

    }//end testStreamFileDelegatesToOpenRegistersStreamFileOnSuccess()
}//end class
