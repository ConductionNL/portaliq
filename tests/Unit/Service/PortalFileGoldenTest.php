<?php

/**
 * PortalFileGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The file reader and writer answer every way OpenRegister can be missing or
 * fail exactly as recorded: which services they ask for, how they look the
 * object up, what they log and what they hand back.
 *
 * @covers \OCA\Portaliq\Service\PortalFileReader
 * @covers \OCA\Portaliq\Service\PortalFileWriter
 * @uses   \OCA\Portaliq\Service\FileAttachment
 */
class PortalFileGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/portal-file-reader-writer.json';

	private const FILE_SERVICE = 'OCA\\OpenRegister\\Service\\FileService';

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What happens so far, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every public read and the write answer as recorded, per situation.
	 *
	 * @return void
	 */
	public function testEveryOutcomeIsAsRecorded(): void {
		$actual = [];
		foreach (['fileServiceThrows', 'fileServiceNotObject', 'objectServiceThrows', 'objectServiceNotObject', 'objectMissing', 'findAnswersScalar', 'found'] as $situation) {
			$actual[$situation] = [
				'listFiles' => $this->outcome(situation: $situation, call: static fn (PortalFileReader $r): mixed => $r->listFiles(register: 'reg', schema: 'sch', id: 'obj-1')),
				'listReleasedFiles' => $this->outcome(situation: $situation, call: static fn (PortalFileReader $r): mixed => $r->listReleasedFiles(register: 'reg', schema: 'sch', id: 'obj-1')),
				'listTaggedFiles' => $this->outcome(situation: $situation, call: static fn (PortalFileReader $r): mixed => $r->listTaggedFiles(register: 'reg', schema: 'sch', id: 'obj-1', tag: 'keep')),
				'streamFile' => $this->outcome(situation: $situation, call: static fn (PortalFileReader $r): mixed => $r->streamFile(register: 'reg', schema: 'sch', id: 'obj-1', fileId: '7')),
				'attachFile' => $this->outcome(situation: $situation, call: static fn (PortalFileWriter $w): mixed => $w->attachFile(register: 'reg', schema: 'sch', id: 'obj-1', fileName: 'upload.pdf', content: 'abc', tags: ['t'])),
				'attachFileBare' => $this->outcome(situation: $situation . ':bare', call: static fn (PortalFileWriter $w): mixed => $w->attachFile(register: 'reg', schema: 'sch', id: 'obj-1', fileName: 'upload.pdf', content: 'abc')),
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryOutcomeIsAsRecorded()

	/**
	 * Run one call against a reader or writer set up for the situation.
	 *
	 * @param string   $situation How OpenRegister behaves.
	 * @param callable $call      The call; its parameter type picks the class.
	 *
	 * @return array<string, mixed> The answer and the log.
	 */
	private function outcome(string $situation, callable $call): array {
		$this->log = [];
		[$situation, $bare] = array_pad(explode(':', $situation), 2, '');
		$container = $this->container(situation: $situation, bare: $bare === 'bare');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->log[] = ['warning' => $message, 'context' => $context];
		});

		$type = (string)(new \ReflectionFunction($call))->getParameters()[0]->getType();
		$subject = new $type($container, $logger);
		$answer = $call($subject);
		if ($answer instanceof StreamResponse) {
			$answer = 'stream';
		}

		return ['answer' => $answer, 'log' => $this->log];
	}//end outcome()

	/**
	 * A container that answers the two OpenRegister services as the situation says.
	 *
	 * @param string $situation How OpenRegister behaves.
	 * @param bool   $bare      Whether the stored file has no accessors.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $situation, bool $bare): ContainerInterface {
		$test = $this;
		$files = new class ($test, $bare) {
			public function __construct(private PortalFileGoldenTest $test, private bool $bare) {
			}

			public function getFiles(mixed $object, bool $sharedFilesOnly = false): array {
				$this->test->note(['getFiles' => ['object' => $object->id, 'sharedFilesOnly' => $sharedFilesOnly]]);
				return [PortalFileGoldenTest::storedFile(), new \stdClass(), ['id' => 3]];
			}

			public function getFileTags(string $fileId): array {
				$this->test->note(['getFileTags' => $fileId]);
				if ($fileId === '') {
					throw new RuntimeException('no id');
				}

				return ['keep'];
			}

			public function getFile(mixed $object, string $file): mixed {
				$this->test->note(['getFile' => ['object' => $object->id, 'file' => $file]]);
				return $this->test->fileNode();
			}

			public function streamFile(File $file): StreamResponse {
				$this->test->note('streamFile');
				return new StreamResponse('x');
			}

			public function addFile(mixed $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], mixed $_schema = null, mixed $_register = null): mixed {
				$this->test->note(['addFile' => ['object' => $objectEntity->id, 'fileName' => $fileName, 'content' => $content, 'tags' => $tags, '_schema' => $_schema, '_register' => $_register]]);
				if ($this->bare === true) {
					return new \stdClass();
				}

				return PortalFileGoldenTest::storedFile();
			}
		};
		$objects = new class ($test, $situation) {
			public function __construct(private PortalFileGoldenTest $test, private string $situation) {
			}

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->test->note(['find' => compact('id', 'register', 'schema', '_rbac', '_multitenancy')]);
				if ($this->situation === 'objectMissing') {
					throw new DoesNotExistException('Object not found');
				}

				if ($this->situation === 'findAnswersScalar') {
					return 'nope';
				}

				$entity = new \stdClass();
				$entity->id = $id;
				return $entity;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($situation, $files, $objects): mixed {
			$this->log[] = ['get' => $id];
			$service = $objects;
			if ($id === self::FILE_SERVICE) {
				$service = $files;
			}

			if (($situation === 'fileServiceThrows' && $id === self::FILE_SERVICE) || ($situation === 'objectServiceThrows' && $id === self::OBJECT_SERVICE)) {
				throw new RuntimeException('not installed');
			}

			if (($situation === 'fileServiceNotObject' && $id === self::FILE_SERVICE) || ($situation === 'objectServiceNotObject' && $id === self::OBJECT_SERVICE)) {
				return 'not an object';
			}

			return $service;
		});

		return $container;
	}//end container()

	/**
	 * Record one event in the log.
	 *
	 * @param mixed $event The event.
	 *
	 * @return void
	 */
	public function note(mixed $event): void {
		$this->log[] = $event;
	}//end note()

	/**
	 * A stored file with every accessor.
	 *
	 * @return object
	 */
	public static function storedFile(): object {
		return new class {
			public function getId(): int {
				return 9;
			}

			public function getName(): int {
				return 42;
			}

			public function getSize(): int {
				return 4;
			}
		};
	}//end storedFile()

	/**
	 * A Nextcloud file node.
	 *
	 * @return File
	 */
	public function fileNode(): File {
		return $this->createMock(File::class);
	}//end fileNode()
}//end class
