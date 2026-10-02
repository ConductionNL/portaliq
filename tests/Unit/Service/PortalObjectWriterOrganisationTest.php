<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The organisation is stamped on create and never written on update.
 *
 * Seen live on 2026-10-02: a resident withdrawing dossiq case 2026-0004 had
 * `organisation: default-organisation` written into the case, because the
 * update re-stamped the portal's tenant. The tenant decides ownership when an
 * object is created; after that an update keeps whatever the stored object
 * has, and adds nothing when it has none.
 *
 * The doubles are built with `onlyMethods` on OpenRegister's own
 * ObjectService and ObjectEntity (or on the signature stubs under
 * tests/Stubs/OpenRegister when OpenRegister is absent), so a call the real
 * class cannot take fails here too.
 *
 * @spec openspec/changes/archive/2026-09-07-portal-scoped-crud/tasks.md#T2
 */
class PortalObjectWriterOrganisationTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What the writer handed to saveObject, per call.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: ?string}>
	 */
	private array $saves = [];

	public static function setUpBeforeClass(): void {
		if (class_exists(ObjectEntity::class) === false) {
			require_once __DIR__ . '/../../Stubs/OpenRegister/Db/ObjectEntity.php';
		}

		if (class_exists(ObjectService::class) === false) {
			require_once __DIR__ . '/../../Stubs/OpenRegister/Service/ObjectService.php';
		}
	}//end setUpBeforeClass()

	protected function setUp(): void {
		$this->saves = [];
	}//end setUp()

	/**
	 * The live case: the stored object has no organisation and the resident's
	 * portal has one. The update must not add it.
	 */
	public function testUpdateAddsNoOrganisationToAnObjectThatHasNone(): void {
		$writer = $this->writer(stored: ['id' => 'case-4', 'subjectRef' => 's1', 'status' => 'open']);

		$result = $writer->updateObject('dossiq', 'case', 'subjectRef', 's1', 'default-organisation', 'case-4', ['status' => 'withdrawn']);

		$this->assertNotNull($result);
		$this->assertCount(1, $this->saves);
		$this->assertSame('withdrawn', $this->saves[0]['object']['status']);
		$this->assertSame('case-4', $this->saves[0]['uuid']);
		$this->assertArrayNotHasKey('organisation', $this->saves[0]['object']);
	}//end testUpdateAddsNoOrganisationToAnObjectThatHasNone()

	/**
	 * A stored empty organisation passes the tenant check and stays empty.
	 */
	public function testUpdateKeepsAStoredEmptyOrganisationEmpty(): void {
		$writer = $this->writer(stored: ['id' => 'case-4', 'subjectRef' => 's1', 'organisation' => '', 'status' => 'open']);

		$writer->updateObject('dossiq', 'case', 'subjectRef', 's1', 'default-organisation', 'case-4', ['status' => 'withdrawn']);

		$this->assertCount(1, $this->saves);
		$this->assertSame('', $this->saves[0]['object']['organisation']);
	}//end testUpdateKeepsAStoredEmptyOrganisationEmpty()

	/**
	 * A stored organisation is kept, even when the subject's portal names none.
	 */
	public function testUpdateKeepsTheStoredOrganisationWhenThePortalHasNone(): void {
		$writer = $this->writer(stored: ['id' => 'case-4', 'subjectRef' => 's1', 'organisation' => 'org-1', 'status' => 'open']);

		$writer->updateObject('dossiq', 'case', 'subjectRef', 's1', '', 'case-4', ['status' => 'withdrawn']);

		$this->assertCount(1, $this->saves);
		$this->assertSame('org-1', $this->saves[0]['object']['organisation']);
	}//end testUpdateKeepsTheStoredOrganisationWhenThePortalHasNone()

	/**
	 * A payload that carries an organisation changes nothing: it can neither
	 * overwrite a stored one nor add one where there was none.
	 */
	public function testUpdateIgnoresAnOrganisationInThePayload(): void {
		$writer = $this->writer(stored: ['id' => 'case-4', 'subjectRef' => 's1', 'organisation' => 'org-1']);
		$writer->updateObject('dossiq', 'case', 'subjectRef', 's1', 'org-1', 'case-4', ['organisation' => 'org-2', 'note' => 'x']);

		$this->assertSame('org-1', $this->saves[0]['object']['organisation']);
		$this->assertSame('x', $this->saves[0]['object']['note']);

		$this->saves = [];
		$writer = $this->writer(stored: ['id' => 'case-5', 'subjectRef' => 's1']);
		$writer->updateObject('dossiq', 'case', 'subjectRef', 's1', 'org-1', 'case-5', ['organisation' => 'org-2']);

		$this->assertArrayNotHasKey('organisation', $this->saves[0]['object']);
	}//end testUpdateIgnoresAnOrganisationInThePayload()

	/**
	 * The tenant check itself still holds: a row of another organisation is
	 * refused before anything is written.
	 */
	public function testUpdateStillRefusesAnotherOrganisationsObject(): void {
		$writer = $this->writer(stored: ['id' => 'case-4', 'subjectRef' => 's1', 'organisation' => 'org-2']);

		$this->assertNull($writer->updateObject('dossiq', 'case', 'subjectRef', 's1', 'org-1', 'case-4', ['status' => 'withdrawn']));
		$this->assertSame([], $this->saves);
	}//end testUpdateStillRefusesAnotherOrganisationsObject()

	/**
	 * When the organisation IS the scope field (access requests), the scope
	 * re-stamp still writes the verified tenant.
	 */
	public function testUpdateWithOrganisationAsScopeFieldKeepsTheVerifiedTenant(): void {
		$writer = $this->writer(stored: ['id' => 'req-1', 'organisation' => 'org-1', 'status' => 'pending']);

		$writer->updateObject('portaliq', 'accessRequest', 'organisation', 'org-1', 'org-1', 'req-1', ['status' => 'granted']);

		$this->assertSame('org-1', $this->saves[0]['object']['organisation']);
		$this->assertSame('granted', $this->saves[0]['object']['status']);
	}//end testUpdateWithOrganisationAsScopeFieldKeepsTheVerifiedTenant()

	/**
	 * Create is where the tenant is decided: it still stamps the portal's
	 * organisation over anything the payload says.
	 */
	public function testCreateStillStampsTheOrganisation(): void {
		$writer = $this->writer(stored: null);

		$writer->createObject('dossiq', 'case', 'subjectRef', 's1', 'default-organisation', ['title' => 'X', 'organisation' => 'org-2']);

		$this->assertCount(1, $this->saves);
		$this->assertSame('default-organisation', $this->saves[0]['object']['organisation']);
		$this->assertNull($this->saves[0]['uuid']);
	}//end testCreateStillStampsTheOrganisation()

	/**
	 * A writer over an ObjectService double that serves one stored row (or
	 * none) and records every save.
	 *
	 * @param array<string, mixed>|null $stored The row `find()` returns.
	 */
	private function writer(?array $stored): PortalObjectWriter {
		/** @var ObjectService&MockObject $objectService */
		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();

		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($stored): ?ObjectEntity {
				if ($stored === null || ($stored['id'] ?? null) !== $id) {
					return null;
				}

				return $this->entity(row: $stored);
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'uuid' => $uuid];
				return $this->entity(row: $object);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
	}//end writer()

	/**
	 * An ObjectEntity double that serialises to the given row.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
