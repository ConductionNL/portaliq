<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AudienceMediaReader;
use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for an OpenRegister object.
 */
class FakeMediaObject {

	/**
	 * @param string               $uuid  The id.
	 * @param array<string, mixed> $data  The object's data.
	 * @param string|null          $owner The owner.
	 */
	public function __construct(private string $uuid, private array $data, private ?string $owner=null) {
	}

	public function getUuid(): string {
		return $this->uuid;
	}

	public function getObject(): array {
		return $this->data;
	}

	public function getOwner(): ?string {
		return $this->owner;
	}
}

/**
 * A stand-in for OpenRegister's ObjectService.
 */
class FakeMediaObjects {

	/**
	 * @var array<int, object>
	 */
	public array $rows = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	public function setRegister(string $register): void {
		$this->calls[] = ['register' => $register];
	}

	public function setSchema(string $schema): void {
		$this->calls[] = ['schema' => $schema];
	}

	public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array {
		$this->calls[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $this->rows;
	}
}

/**
 * A stand-in for OpenRegister's PermissionHandler: reads are allowed for the listed object ids.
 */
class FakeMediaPermissions {

	/**
	 * @var array<int, string>
	 */
	public array $readable = [];

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $asked = [];

	public mixed $throw = null;

	public function hasPermission(object $schema, string $action, ?string $userId=null, ?string $objectOwner=null, bool $_rbac=true, ?object $object=null, ?string $register=null): bool {
		if ($this->throw !== null) {
			throw $this->throw;
		}

		$this->asked[] = ['action' => $action, 'userId' => $userId, 'rbac' => $_rbac, 'id' => $object?->getUuid(), 'register' => $register];
		return in_array($object?->getUuid(), $this->readable, true);
	}
}

/**
 * A stand-in for OpenRegister's SchemaMapper.
 */
class FakeMediaSchemas {

	public bool $found = true;

	public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
		return $this->found === true ? [new \stdClass()] : [];
	}
}

/**
 * documents-grouped-per-record task 3: the media of an extra group are what
 * OpenRegister lets the person's Nextcloud account read, and nothing for a
 * session that is not one.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */
class AudienceMediaReaderTest extends TestCase {

	private const FATIMA = ['subjectRef' => 'fatima', 'provider' => 'nextcloud', 'trust' => 'low'];

	private FakeMediaObjects $objects;

	private FakeMediaPermissions $permissions;

	private FakeMediaSchemas $schemas;

	private MockObject $files;

	private function reader(bool $userExists=true): AudienceMediaReader {
		$this->objects     = new FakeMediaObjects();
		$this->permissions = new FakeMediaPermissions();
		$this->schemas     = new FakeMediaSchemas();
		$this->objects->rows = [
			new FakeMediaObject('guide', ['title' => 'Schoolgids 2026-2027', 'date' => '2026-08-20']),
			new FakeMediaObject('letter5', ['title' => 'Brief voor groep 5']),
			new FakeMediaObject('nofile', ['title' => 'Zonder bestand']),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			'OCA\\OpenRegister\\Service\\ObjectService' => $this->objects,
			'OCA\\OpenRegister\\Service\\Object\\PermissionHandler' => $this->permissions,
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $this->schemas,
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn($userExists);
		$this->files = $this->createMock(PortalFileReader::class);
		$this->files->method('listFiles')->willReturnCallback(static fn (string $register, string $schema, string $id): array => $id === 'nofile' ? [] : [['id' => 7, 'name' => $id . '.pdf', 'size' => 2048]]);

		return new AudienceMediaReader($container, $users, $this->files, $this->createMock(LoggerInterface::class));
	}

	public function testOnlyWhatOpenRegisterLetsTheAccountReadIsListed(): void {
		$reader = $this->reader();
		$this->permissions->readable = ['guide', 'nofile'];

		$entries = $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper');

		$this->assertSame([['id' => 'guide', 'title' => 'Schoolgids 2026-2027', 'kind' => 'document', 'date' => '2026-08-20', 'size' => 2048]], $entries);
		$this->assertSame(['read'], array_unique(array_column($this->permissions->asked, 'action')));
		$this->assertSame(['fatima'], array_unique(array_column($this->permissions->asked, 'userId')));
		$this->assertSame([true], array_unique(array_column($this->permissions->asked, 'rbac')));
		$this->assertSame([null], array_unique(array_column($this->permissions->asked, 'register')));
	}//end testOnlyWhatOpenRegisterLetsTheAccountReadIsListed()

	public function testASessionThatIsNoNextcloudAccountIsShownNothing(): void {
		foreach ([['subjectRef' => '999993653', 'provider' => '', 'trust' => 'substantial'], ['subjectRef' => 'fatima', 'provider' => 'email'], ['subjectRef' => '', 'provider' => 'nextcloud']] as $subject) {
			$reader = $this->reader();
			$this->permissions->readable = ['guide'];
			$this->assertSame([], $reader->list(subject: $subject, register: 'school-media', schema: 'paper'), json_encode($subject));
			$this->assertNull($reader->open(subject: $subject, register: 'school-media', schema: 'paper', id: 'guide'));
			$this->assertSame([], $this->permissions->asked);
		}

		$gone = $this->reader(userExists: false);
		$this->permissions->readable = ['guide'];
		$this->assertSame([], $gone->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));
	}//end testASessionThatIsNoNextcloudAccountIsShownNothing()

	public function testAFailedPermissionCheckOrAnUnknownSchemaShowsNothing(): void {
		$reader = $this->reader();
		$this->permissions->throw = new RuntimeException('down');
		$this->assertSame([], $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));

		$reader = $this->reader();
		$this->permissions->readable = ['guide'];
		$this->schemas->found = false;
		$this->assertSame([], $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));
	}//end testAFailedPermissionCheckOrAnUnknownSchemaShowsNothing()

	public function testOpeningAsksOpenRegisterAgain(): void {
		$reader = $this->reader();
		$this->permissions->readable = ['guide'];
		$stream = $this->createMock(StreamResponse::class);
		$this->files->expects($this->once())->method('streamFile')->with('school-media', 'paper', 'guide', '7')->willReturn($stream);

		$this->assertSame($stream, $reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'guide'));
		$this->assertNull($reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'letter5'));
		$this->assertNull($reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'unknown'));
	}//end testOpeningAsksOpenRegisterAgain()
}//end class
