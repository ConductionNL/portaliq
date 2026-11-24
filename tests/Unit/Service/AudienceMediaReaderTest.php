<?php

/**
 * Audience Media Reader Test
 *
 * @category Tests
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
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
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
use stdClass;

/**
 * documents-grouped-per-record task 3: the media of an extra group are what
 * OpenRegister lets the person's Nextcloud account read, and nothing for a
 * session that is not one.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */
class AudienceMediaReaderTest extends TestCase {

	/**
	 * A session minted by the Nextcloud sign-in.
	 */
	private const FATIMA = ['subjectRef' => 'fatima', 'provider' => 'nextcloud', 'trust' => 'low'];

	/**
	 * The object ids OpenRegister lets the account read.
	 *
	 * @var array<int, string>
	 */
	private array $readable = [];

	/**
	 * What the permission check was asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * Whether the permission check throws.
	 *
	 * @var boolean
	 */
	private bool $checkFails = false;

	/**
	 * Whether the schema is found.
	 *
	 * @var boolean
	 */
	private bool $schemaFound = true;

	/**
	 * The file reader.
	 *
	 * @var MockObject&PortalFileReader
	 */
	private MockObject $files;


	/**
	 * An OpenRegister object as the reader sees it.
	 *
	 * @param string               $uuid The id.
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return object
	 */
	private function mediaObject(string $uuid, array $data): object {
		return new class ($uuid, $data) {
			/**
			 * Constructor.
			 *
			 * @param string               $uuid The id.
			 * @param array<string, mixed> $data The data.
			 */
			public function __construct(private string $uuid, private array $data) {
			}//end __construct()

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return null;
			}//end getOwner()
		};
	}//end mediaObject()


	/**
	 * The OpenRegister services the reader asks for, as stand-ins.
	 *
	 * @return array<string, object>
	 */
	private function services(): array {
		$rows = [
			$this->mediaObject(uuid: 'guide', data: ['title' => 'Schoolgids 2026-2027', 'date' => '2026-08-20']),
			$this->mediaObject(uuid: 'letter5', data: ['title' => 'Brief voor groep 5']),
			$this->mediaObject(uuid: 'nofile', data: ['title' => 'Zonder bestand']),
		];

		return [
			'OCA\\OpenRegister\\Service\\ObjectService'           => new class ($rows) {
				/**
				 * Constructor.
				 *
				 * @param array<int, object> $rows The rows findAll() answers.
				 */
				public function __construct(private array $rows) {
				}//end __construct()

				/**
				 * @param string $register The register.
				 *
				 * @return void
				 */
				public function setRegister(string $register): void {
				}//end setRegister()

				/**
				 * @param string $schema The schema.
				 *
				 * @return void
				 */
				public function setSchema(string $schema): void {
				}//end setSchema()

				/**
				 * @param array<string, mixed> $config        The read.
				 * @param boolean              $_rbac         RBAC.
				 * @param boolean              $_multitenancy Multitenancy.
				 *
				 * @return array<int, object>
				 */
				public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array {
					return $this->rows;
				}//end findAll()
			},
			'OCA\\OpenRegister\\Service\\Object\\PermissionHandler' => new class ($this) {
				/**
				 * Constructor.
				 *
				 * @param AudienceMediaReaderTest $test The test, which holds the answers.
				 */
				public function __construct(private AudienceMediaReaderTest $test) {
				}//end __construct()

				/**
				 * @param object      $schema      The schema.
				 * @param string      $action      The action.
				 * @param string|null $userId      The account.
				 * @param string|null $objectOwner The owner.
				 * @param boolean     $_rbac       RBAC.
				 * @param object|null $object      The object.
				 * @param string|null $register    The register.
				 *
				 * @return boolean
				 */
				public function hasPermission(
					object $schema,
					string $action,
					?string $userId=null,
					?string $objectOwner=null,
					bool $_rbac=true,
					?object $object=null,
					?string $register=null
				): bool {
					return $this->test->answer(action: $action, userId: $userId, rbac: $_rbac, id: (string)$object?->getUuid(), register: $register);
				}//end hasPermission()
			},
			'OCA\\OpenRegister\\Db\\SchemaMapper'                  => new class ($this) {
				/**
				 * Constructor.
				 *
				 * @param AudienceMediaReaderTest $test The test, which says whether the schema exists.
				 */
				public function __construct(private AudienceMediaReaderTest $test) {
				}//end __construct()

				/**
				 * @param string  $slug          The slug.
				 * @param integer $limit         The limit.
				 * @param integer $offset        The offset.
				 * @param boolean $_rbac         RBAC.
				 * @param boolean $_multitenancy Multitenancy.
				 *
				 * @return array<int, object>
				 */
				public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
					return $this->test->schemas();
				}//end findBySlug()
			},
		];
	}//end services()


	/**
	 * What the stand-in permission check answers, recorded.
	 *
	 * @param string      $action   The action.
	 * @param string|null $userId   The account.
	 * @param boolean     $rbac     Whether RBAC applies.
	 * @param string      $id       The object id.
	 * @param string|null $register The register handed in.
	 *
	 * @return boolean
	 */
	public function answer(string $action, ?string $userId, bool $rbac, string $id, ?string $register): bool {
		if ($this->checkFails === true) {
			throw new RuntimeException(message: 'down');
		}

		$this->asked[] = ['action' => $action, 'userId' => $userId, 'rbac' => $rbac, 'register' => $register];
		return in_array(needle: $id, haystack: $this->readable, strict: true);
	}//end answer()


	/**
	 * What the stand-in schema mapper answers.
	 *
	 * @return array<int, object>
	 */
	public function schemas(): array {
		if ($this->schemaFound === false) {
			return [];
		}

		return [new stdClass()];
	}//end schemas()


	/**
	 * The reader over the stand-ins.
	 *
	 * @param boolean $userExists Whether the session's account exists.
	 *
	 * @return AudienceMediaReader
	 */
	private function reader(bool $userExists=true): AudienceMediaReader {
		$this->readable    = [];
		$this->asked       = [];
		$this->checkFails  = false;
		$this->schemaFound = true;
		$services          = $this->services();
		$container         = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('userExists')->willReturn($userExists);
		$this->files = $this->createMock(originalClassName: PortalFileReader::class);
		$this->files->method('listFiles')->willReturnCallback(
			static function (string $register, string $schema, string $id): array {
				if ($id === 'nofile') {
					return [];
				}

				return [['id' => 7, 'name' => $id . '.pdf', 'size' => 2048]];
			}
		);

		return new AudienceMediaReader(
			container: $container,
			users: $users,
			files: $this->files,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end reader()


	/**
	 * Only what OpenRegister lets the account read, with a file, is listed;
	 * each check is a read with RBAC as the account, without elevation.
	 *
	 * @return void
	 */
	public function testOnlyWhatOpenRegisterLetsTheAccountReadIsListed(): void {
		$reader         = $this->reader();
		$this->readable = ['guide', 'nofile'];

		$entries = $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper');

		$expected = [['id' => 'guide', 'title' => 'Schoolgids 2026-2027', 'kind' => 'document', 'date' => '2026-08-20', 'size' => 2048]];
		$this->assertSame(expected: $expected, actual: $entries);
		foreach ($this->asked as $asked) {
			$this->assertSame(expected: ['action' => 'read', 'userId' => 'fatima', 'rbac' => true, 'register' => null], actual: $asked);
		}
	}//end testOnlyWhatOpenRegisterLetsTheAccountReadIsListed()


	/**
	 * A session that is no Nextcloud account is shown nothing, and OpenRegister is not asked.
	 *
	 * @return void
	 */
	public function testASessionThatIsNoNextcloudAccountIsShownNothing(): void {
		$subjects = [
			['subjectRef' => '999993653', 'provider' => '', 'trust' => 'substantial'],
			['subjectRef' => 'fatima', 'provider' => 'email'],
			['subjectRef' => '', 'provider' => 'nextcloud'],
		];
		foreach ($subjects as $subject) {
			$reader         = $this->reader();
			$this->readable = ['guide'];
			$this->assertSame(expected: [], actual: $reader->list(subject: $subject, register: 'school-media', schema: 'paper'));
			$this->assertNull(actual: $reader->open(subject: $subject, register: 'school-media', schema: 'paper', id: 'guide'));
			$this->assertSame(expected: [], actual: $this->asked);
		}

		$gone           = $this->reader(userExists: false);
		$this->readable = ['guide'];
		$this->assertSame(expected: [], actual: $gone->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));
	}//end testASessionThatIsNoNextcloudAccountIsShownNothing()


	/**
	 * A permission check that throws, or an unknown schema, shows nothing.
	 *
	 * @return void
	 */
	public function testAFailedPermissionCheckOrAnUnknownSchemaShowsNothing(): void {
		$reader           = $this->reader();
		$this->checkFails = true;
		$this->assertSame(expected: [], actual: $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));

		$reader            = $this->reader();
		$this->readable    = ['guide'];
		$this->schemaFound = false;
		$this->assertSame(expected: [], actual: $reader->list(subject: self::FATIMA, register: 'school-media', schema: 'paper'));
	}//end testAFailedPermissionCheckOrAnUnknownSchemaShowsNothing()


	/**
	 * Opening asks OpenRegister again and streams the first file.
	 *
	 * @return void
	 */
	public function testOpeningAsksOpenRegisterAgain(): void {
		$reader         = $this->reader();
		$this->readable = ['guide'];
		$stream         = $this->createMock(originalClassName: StreamResponse::class);
		$this->files->expects($this->once())->method('streamFile')->with('school-media', 'paper', 'guide', '7')->willReturn($stream);

		$this->assertSame(expected: $stream, actual: $reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'guide'));
		$this->assertNull(actual: $reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'letter5'));
		$this->assertNull(actual: $reader->open(subject: self::FATIMA, register: 'school-media', schema: 'paper', id: 'unknown'));
	}//end testOpeningAsksOpenRegisterAgain()
}//end class
