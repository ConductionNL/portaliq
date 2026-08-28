<?php

/**
 * PageEditorServiceTest
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

use OCA\Portaliq\Service\PageEditorService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Who may edit portal pages, and whether the answer reaches the schema.
 *
 * The predicate and the schema write are tested as two separate things on
 * purpose: a UI that hides its button while the schema stays open to every
 * authenticated user is the exact failure this class exists to prevent, and it
 * looks completely correct from the interface.
 */
class PageEditorServiceTest extends TestCase {

	/**
	 * The stored config value, as the double keeps it.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The authorization block the schema double was last given.
	 *
	 * @var array|null
	 */
	private ?array $written = null;


	/**
	 * A service wired to doubles.
	 *
	 * @param bool        $isAdmin     Whether the session user is an admin.
	 * @param array       $memberships Group ids the session user belongs to.
	 * @param string|null $uid         The session uid, or null for no session.
	 * @param bool        $withMapper  Whether OpenRegister's schema mapper resolves.
	 *
	 * @return PageEditorService The service.
	 */
	private function service(
		bool $isAdmin = false,
		array $memberships = [],
		?string $uid = 'editor',
		bool $withMapper = true,
	): PageEditorService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->stored !== '' ? $this->stored : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $gid) => in_array($gid, $memberships, true)
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($withMapper === true) {
			$container->method('get')->willReturn($this->schemaMapper());
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('no OpenRegister'));
		}

		return new PageEditorService(
			$appConfig,
			$groupManager,
			$session,
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()


	/**
	 * A schema mapper double that records the authorization it is handed.
	 *
	 * @return object The mapper.
	 */
	private function schemaMapper(): object {
		$test = $this;

		return new class($test) {

			/**
			 * The test case, for recording.
			 *
			 * @var object
			 */
			private object $test;


			/**
			 * Constructor.
			 *
			 * @param object $test The test case.
			 */
			public function __construct(object $test) {
				$this->test = $test;
			}


			/**
			 * Resolve the app-owned schema.
			 *
			 * @param string $slug        The schema slug.
			 * @param string $application The owning app.
			 *
			 * @return object|null The schema double.
			 */
			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				if ($slug !== 'page' || $application !== 'portaliq') {
					return null;
				}

				return new class {

					/**
					 * The block, seeded with the shipped read rules.
					 *
					 * @var array
					 */
					public array $authorization = [
						'read' => [
							['group' => 'public', 'match' => ['status' => 'published']],
							'authenticated',
						],
					];


					/**
					 * Read the block.
					 *
					 * @return array The block.
					 */
					public function getAuthorization(): array {
						return $this->authorization;
					}


					/**
					 * Write the block.
					 *
					 * @param array|null $authorization The block.
					 *
					 * @return void
					 */
					public function setAuthorization(?array $authorization): void {
						$this->authorization = ($authorization ?? []);
					}
				};
			}


			/**
			 * Record what was stored.
			 *
			 * @param object $entity The schema double.
			 *
			 * @return object The entity.
			 */
			public function update(object $entity): object {
				$this->test->record($entity->getAuthorization());
				return $entity;
			}
		};
	}//end schemaMapper()


	/**
	 * Record the authorization block a schema was updated with.
	 *
	 * @param array $authorization The block.
	 *
	 * @return void
	 */
	public function record(array $authorization): void {
		$this->written = $authorization;
	}//end record()


	/**
	 * Reset the doubles' state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = '';
		$this->written = null;
	}//end setUp()


	/**
	 * A visitor with no session may never edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testAnAnonymousVisitorMayNotEdit(): void {
		$this->assertFalse($this->service(uid: null)->mayEdit());
	}//end testAnAnonymousVisitorMayNotEdit()


	/**
	 * An administrator may edit without being in any configured group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testAnAdministratorMayEdit(): void {
		$this->assertTrue($this->service(isAdmin: true)->mayEdit());
	}//end testAnAdministratorMayEdit()


	/**
	 * A member of a configured group may edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testAConfiguredEditorMayEdit(): void {
		$this->stored = json_encode(['redacteuren']);

		$this->assertTrue($this->service(memberships: ['redacteuren'])->mayEdit());
	}//end testAConfiguredEditorMayEdit()


	/**
	 * An authenticated user in no configured group may not edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testAnAuthenticatedNonEditorMayNotEdit(): void {
		$this->stored = json_encode(['redacteuren']);

		$this->assertFalse($this->service(memberships: ['gebruikers'])->mayEdit());
	}//end testAnAuthenticatedNonEditorMayNotEdit()


	/**
	 * No configured groups means administrators only — not everybody.
	 *
	 * The tempting reading of an empty list is "unrestricted". That reading is
	 * how a public portal's pages become writable by every account on the
	 * instance, so it is asserted in the direction that would be silent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testAnEmptySettingDoesNotMeanEveryone(): void {
		$this->assertFalse($this->service(memberships: ['gebruikers'])->mayEdit());
	}//end testAnEmptySettingDoesNotMeanEveryone()


	/**
	 * Saving the groups writes them into the schema's write rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testSavingWritesTheSchemaWriteRules(): void {
		$service = $this->service(isAdmin: true);

		$stored = $service->setEditorGroups([['id' => 'redacteuren', 'label' => 'Redacteuren'], 'webmasters', 'redacteuren', '']);

		$this->assertSame(['redacteuren', 'webmasters'], $stored, 'Objects, duplicates and blanks must normalise to unique ids.');
		$this->assertSame(['redacteuren', 'webmasters'], $this->written['create']);
		$this->assertSame(['redacteuren', 'webmasters'], $this->written['update']);
		$this->assertSame(['redacteuren', 'webmasters'], $this->written['delete']);
	}//end testSavingWritesTheSchemaWriteRules()


	/**
	 * The public read rule survives a write of the editor groups.
	 *
	 * Rewriting `read` here would take every published page off the air for
	 * anonymous visitors — a portal outage caused by configuring who may edit
	 * it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testThePublicReadRuleSurvives(): void {
		$this->service(isAdmin: true)->setEditorGroups(['redacteuren']);

		$this->assertSame(
			[
				['group' => 'public', 'match' => ['status' => 'published']],
				'authenticated',
			],
			$this->written['read']
		);
	}//end testThePublicReadRuleSurvives()


	/**
	 * Clearing the setting writes empty rules, which grant to nobody.
	 *
	 * An empty rule list is not the same as no rule list: OpenRegister reads a
	 * missing write rule as default-open for authenticated users and an empty
	 * one as "nobody", with administrators bypassing either way. Clearing the
	 * setting must produce the second.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testClearingTheSettingClosesTheSchema(): void {
		$service = $this->service(isAdmin: true);
		$service->setEditorGroups(['redacteuren']);
		$service->setEditorGroups([]);

		$this->assertSame([], $this->written['create']);
		$this->assertSame([], $this->written['update']);
		$this->assertSame([], $this->written['delete']);
		$this->assertArrayHasKey('read', $this->written);
	}//end testClearingTheSettingClosesTheSchema()


	/**
	 * A value stored by hand as a bare string configures that one group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testABareStringValueIsReadAsOneGroup(): void {
		$this->stored = 'redacteuren';

		$this->assertSame(['redacteuren'], $this->service()->getEditorGroups());
	}//end testABareStringValueIsReadAsOneGroup()


	/**
	 * Without OpenRegister the setting still saves, and says the schema did not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testTheSchemaWriteReportsFailureWhenOpenRegisterIsAbsent(): void {
		$service = $this->service(isAdmin: true, withMapper: false);

		$this->assertSame(['redacteuren'], $service->setEditorGroups(['redacteuren']));
		$this->assertFalse($service->applyToSchema(['redacteuren']));
		$this->assertNull($this->written);
	}//end testTheSchemaWriteReportsFailureWhenOpenRegisterIsAbsent()
}//end class
