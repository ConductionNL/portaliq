<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests the OR file reader: it degrades to a safe empty/null result without
 * OpenRegister or on any OR error (never an exception to the caller), it never
 * exposes the raw stored path in a listing, and it delegates streaming to
 * OpenRegister's own `FileService::streamFile()` rather than re-implementing
 * Content-Disposition sanitisation.
 *
 * The reader first resolves the object UUID to a full ObjectEntity through
 * OpenRegister's ObjectService (a bare string id leaves the file service unable
 * to locate the object folder), so the container mock resolves BOTH the object
 * service (whose `find()` returns the entity) and the file service.
 *
 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
 * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
 */
class PortalFileReaderTest extends TestCase {

	private const FS = 'OCA\\OpenRegister\\Service\\FileService';

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'contributions';

	private const SCHEMA = 'contribution';

	/**
	 * Build a container mock that resolves the object service (returning the
	 * given entity from `find()`) and the file service.
	 *
	 * @param object $fileService The stubbed OpenRegister FileService.
	 * @param object|null $entity The entity `ObjectService::find()` returns.
	 *
	 * @return ContainerInterface
	 */
	private function containerFor(object $fileService, ?object $entity): ContainerInterface {
		$objectService = new class($entity) {
			public function __construct(
				private readonly ?object $entity,
			) {
			}//end __construct()

			public function find(...$args): ?object {
				return $this->entity;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				self::OS => $objectService,
				self::FS => $fileService,
				default => throw new RuntimeException('unexpected service ' . $id),
			}
		);

		return $container;
	}//end containerFor()

	public function testListFilesReturnsEmptyArrayWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertSame([], $reader->listFiles(self::REGISTER, self::SCHEMA, 'd-1'));

	}//end testListFilesReturnsEmptyArrayWhenOpenRegisterUnavailable()

	public function testListFilesReturnsEmptyArrayWhenOpenRegisterThrows(): void {
		$fileService = new class {
			public function getFiles(object $object, ?bool $sharedFilesOnly = false): array {
				throw new RuntimeException('OR error');
			}//end getFiles()
		};

		$container = $this->containerFor($fileService, new stdClass());

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertSame([], $reader->listFiles(self::REGISTER, self::SCHEMA, 'd-1'));

	}//end testListFilesReturnsEmptyArrayWhenOpenRegisterThrows()

	/**
	 * When the object UUID cannot be resolved to an entity, the listing is empty
	 * — the file service is never reached.
	 */
	public function testListFilesReturnsEmptyArrayWhenObjectDoesNotResolve(): void {
		$fileService = new class {
			public function getFiles(object $object, ?bool $sharedFilesOnly = false): array {
				throw new RuntimeException('must not be called');
			}//end getFiles()
		};

		$container = $this->containerFor($fileService, null);

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertSame([], $reader->listFiles(self::REGISTER, self::SCHEMA, 'd-1'));

	}//end testListFilesReturnsEmptyArrayWhenObjectDoesNotResolve()

	/**
	 * Only safe metadata (id/name/size) is ever shaped into the listing — the
	 * raw stored path is never exposed, even if the underlying node exposed one.
	 */
	public function testListFilesShapesToSafeMetadataOnlyNoStoredPath(): void {
		$node = new class {
			public function getId(): int {
				return 42;
			}//end getId()

			public function getName(): string {
				return 'besluit.pdf';
			}//end getName()

			public function getSize(): int {
				return 1024;
			}//end getSize()

			public function getPath(): string {
				return '/files/__groupfolders/1/portaliq/objects/d-1/besluit.pdf';
			}//end getPath()
		};

		$fileService = new class($node) {
			public function __construct(
				private readonly object $node,
			) {
			}//end __construct()

			public function getFiles(object $object, ?bool $sharedFilesOnly = false): array {
				return [$this->node];
			}//end getFiles()
		};

		$container = $this->containerFor($fileService, new stdClass());

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$files = $reader->listFiles(self::REGISTER, self::SCHEMA, 'd-1');

		$this->assertSame([['id' => 42, 'name' => 'besluit.pdf', 'size' => 1024]], $files);
		foreach ($files as $file) {
			$this->assertArrayNotHasKey('path', $file);
		}

	}//end testListFilesShapesToSafeMetadataOnlyNoStoredPath()

	public function testStreamFileReturnsNullWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertNull($reader->streamFile(self::REGISTER, self::SCHEMA, 'd-1', '42'));

	}//end testStreamFileReturnsNullWhenOpenRegisterUnavailable()

	/**
	 * A fileId that does not resolve inside the OWNED object's own folder (a
	 * foreign file, or none at all) yields null — identical to any other
	 * refusal, no existence oracle.
	 */
	public function testStreamFileReturnsNullWhenFileDoesNotResolve(): void {
		$fileService = new class {
			public function getFile(object $object, string $file): ?object {
				return null;
			}//end getFile()
		};

		$container = $this->containerFor($fileService, new stdClass());

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertNull($reader->streamFile(self::REGISTER, self::SCHEMA, 'd-1', 'not-mine'));

	}//end testStreamFileReturnsNullWhenFileDoesNotResolve()

	public function testStreamFileReturnsNullWhenOpenRegisterThrows(): void {
		$fileService = new class {
			public function getFile(object $object, string $file): ?object {
				throw new RuntimeException('OR error');
			}//end getFile()
		};

		$container = $this->containerFor($fileService, new stdClass());

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertNull($reader->streamFile(self::REGISTER, self::SCHEMA, 'd-1', '42'));

	}//end testStreamFileReturnsNullWhenOpenRegisterThrows()

	/**
	 * When the file resolves, streaming is DELEGATED to OpenRegister's own
	 * `FileService::streamFile()` (never re-implemented here) and its result is
	 * returned unchanged.
	 */
	public function testStreamFileDelegatesToOpenRegistersStreamFileOnSuccess(): void {
		$file = $this->createMock(\OCP\Files\File::class);
		$expected = $this->createMock(StreamResponse::class);

		$fileService = new class($file, $expected) {
			public function __construct(
				private readonly object $file,
				private readonly object $stream,
			) {
			}//end __construct()

			public function getFile(object $object, string $file): object {
				return $this->file;
			}//end getFile()

			public function streamFile(object $file): object {
				return $this->stream;
			}//end streamFile()
		};

		$container = $this->containerFor($fileService, new stdClass());

		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));
		$this->assertSame($expected, $reader->streamFile(self::REGISTER, self::SCHEMA, 'd-1', '42'));

	}//end testStreamFileDelegatesToOpenRegistersStreamFileOnSuccess()

	/**
	 * The released listing asks OpenRegister for the object's shared files
	 * only, so a file the organisation never published stays off the list.
	 * The filter is OpenRegister's own (`getFiles(sharedFilesOnly: true)`),
	 * not a second rule kept here (portaliq#798).
	 */
	public function testTheReleasedListingAsksOpenRegisterForSharedFilesOnly(): void {
		$released = new class {
			public function getId(): int {
				return 7;
			}//end getId()

			public function getName(): string {
				return 'besluit.pdf';
			}//end getName()

			public function getSize(): int {
				return 2048;
			}//end getSize()
		};
		$internal = new class {
			public function getId(): int {
				return 8;
			}//end getId()

			public function getName(): string {
				return 'intern-advies.pdf';
			}//end getName()

			public function getSize(): int {
				return 512;
			}//end getSize()
		};

		$fileService = new class($released, $internal) {
			/**
			 * The sharedFilesOnly flag each call was made with.
			 *
			 * @var array<int, bool|null>
			 */
			public array $asked = [];

			public function __construct(
				private readonly object $released,
				private readonly object $internal,
			) {
			}//end __construct()

			public function getFiles(object $object, ?bool $sharedFilesOnly = false): array {
				$this->asked[] = $sharedFilesOnly;
				if ($sharedFilesOnly === true) {
					return [$this->released];
				}

				return [$this->released, $this->internal];
			}//end getFiles()
		};

		$container = $this->containerFor($fileService, new stdClass());
		$reader = new PortalFileReader($container, $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[['id' => 7, 'name' => 'besluit.pdf', 'size' => 2048]],
			$reader->listReleasedFiles(register: self::REGISTER, schema: self::SCHEMA, id: 'd-1')
		);
		$this->assertSame([true], $fileService->asked);

		// The default listing is unchanged: the upload path still needs every
		// name in the folder to avoid overwriting one.
		$this->assertCount(2, $reader->listFiles(register: self::REGISTER, schema: self::SCHEMA, id: 'd-1'));
		$this->assertSame([true, false], $fileService->asked);
	}//end testTheReleasedListingAsksOpenRegisterForSharedFilesOnly()
	/**
	 * Only the files carrying the tag are listed: a resident's own uploads,
	 * never a staff note in the same folder (cases-documents-on-the-case,
	 * REQ-CDC-004). Tags come from OpenRegister's FileService::getFileTags().
	 *
	 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function testTheTaggedListingKeepsOnlyTaggedFiles(): void {
		$node = static fn (int $id, string $name): object => new class ($id, $name) {
			public function __construct(private int $id, private string $name) {
			}//end __construct()

			public function getId(): int {
				return $this->id;
			}//end getId()

			public function getName(): string {
				return $this->name;
			}//end getName()

			public function getSize(): int {
				return 10;
			}//end getSize()
		};
		$fileService = new class ([$node(1, 'bewijs.pdf'), $node(2, 'intern-advies.pdf')]) {
			public function __construct(private array $files) {
			}//end __construct()

			public function getFiles(object $object, ?bool $sharedFilesOnly = false): array {
				return $this->files;
			}//end getFiles()

			public function getFileTags(string $fileId): array {
				return ($fileId === '1' ? ['portal:from-applicant', 'other'] : ['intern']);
			}//end getFileTags()
		};

		$reader = new PortalFileReader($this->containerFor($fileService, new stdClass()), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[['id' => 1, 'name' => 'bewijs.pdf', 'size' => 10]],
			$reader->listTaggedFiles(register: self::REGISTER, schema: self::SCHEMA, id: 'd-1', tag: 'portal:from-applicant')
		);

	}//end testTheTaggedListingKeepsOnlyTaggedFiles()

}//end class
