<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFileWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * A resident's upload is tagged, so the case screen can list it apart from
 * everything else in the case folder (cases-documents-on-the-case, REQ-CDC-004).
 * The fake file service mirrors OpenRegister's FileService::addFile() signature.
 *
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
 */
class PortalFileWriterTest extends TestCase {

	/**
	 * The tags handed to OpenRegister are the ones the caller asked for, and
	 * none when it asked for none.
	 *
	 * @return void
	 */
	public function testCitizenUploadIsTagged(): void {
		$fileService = new class {
			/**
			 * Every addFile() call's tags.
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $tags = [];

			/**
			 * OpenRegister's addFile(), recorded.
			 *
			 * @param mixed      $objectEntity The object.
			 * @param string     $fileName     The name.
			 * @param mixed      $content      The bytes.
			 * @param bool       $share        Share it.
			 * @param array      $tags         The tags.
			 * @param mixed|null $_schema      The schema.
			 * @param mixed|null $_register    The register.
			 * @param mixed|null $registerId   The register id.
			 *
			 * @return object
			 */
			public function addFile(mixed $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], mixed $_schema = null, mixed $_register = null, mixed $registerId = null): object {
				$this->tags[] = $tags;
				return new class ($fileName) {
					/**
					 * @param string $name The name.
					 */
					public function __construct(private string $name) {
					}

					/**
					 * @return int
					 */
					public function getId(): int {
						return 9;
					}

					/**
					 * @return string
					 */
					public function getName(): string {
						return $this->name;
					}

					/**
					 * @return int
					 */
					public function getSize(): int {
						return 4;
					}
				};
			}
		};
		$objectService = new class {
			/**
			 * @param mixed ...$args The lookup.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				return new stdClass();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => (str_ends_with($id, 'FileService') ? $fileService : $objectService)
		);
		$writer = new PortalFileWriter($container, $this->createMock(LoggerInterface::class));

		$this->assertNotNull($writer->attachFile(register: 'zaken', schema: 'zaak', id: 'z-1', fileName: 'bewijs.pdf', content: 'data', tags: [PortalFileWriter::TAG_FROM_APPLICANT]));
		$writer->attachFile(register: 'zaken', schema: 'zaak', id: 'z-1', fileName: 'other.pdf', content: 'data');

		$this->assertSame([['portal:from-applicant'], []], $fileService->tags);
	}//end testCitizenUploadIsTagged()
}//end class
