<?php

/**
 * SettingsServiceTest
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
use OCA\Portaliq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The settings surface, and what it is careful about.
 *
 * Two properties here are not conveniences. The instance's group list is
 * offered only to an administrator, because a picker needing it is no reason
 * to hand the org chart to every authenticated caller. And the register import
 * RE-APPLIES the configured editor groups, because the seed it imports carries
 * empty write rules — without that step every upgrade silently un-configures
 * page editing on an instance that had configured it.
 */
class SettingsServiceTest extends TestCase {

	/**
	 * Stored app-config values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];


	/**
	 * Reset the doubles' state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [];
	}//end setUp()


	/**
	 * Build the service with doubles.
	 *
	 * @param bool                   $isAdmin       Whether the session user is an admin.
	 * @param PageEditorService|null $pageEditor    The editor-group service double.
	 * @param object|null            $configService OpenRegister's ConfigurationService double, or null when absent.
	 * @param bool                   $orInstalled   Whether OpenRegister reports as installed.
	 *
	 * @return SettingsService The service.
	 */
	private function service(
		bool $isAdmin = true,
		?PageEditorService $pageEditor = null,
		?object $configService = null,
		bool $orInstalled = true,
	): SettingsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($orInstalled);
		$appManager->method('getAppPath')->willReturn('/nonexistent/portaliq');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$container = $this->createMock(ContainerInterface::class);
		if ($configService !== null) {
			$container->method('get')->willReturn($configService);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('absent'));
		}

		return new SettingsService(
			$appConfig,
			$appManager,
			$container,
			$groupManager,
			$session,
			$this->createMock(LoggerInterface::class),
			($pageEditor ?? $this->editor())
		);
	}//end service()


	/**
	 * A PageEditorService double.
	 *
	 * @param array $groups The configured groups it reports.
	 *
	 * @return PageEditorService The double.
	 */
	private function editor(array $groups = []): PageEditorService {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('getEditorGroups')->willReturn($groups);
		$editor->method('mayEdit')->willReturn(true);
		$editor->method('availableGroups')->willReturn([['id' => 'redacteuren', 'label' => 'Redacteuren']]);

		return $editor;
	}//end editor()


	/**
	 * An administrator is offered the instance's groups; nobody else is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testTheGroupListIsOfferedToAdministratorsOnly(): void {
		$this->assertArrayHasKey('availableGroups', $this->service(isAdmin: true)->getSettings());
		$this->assertArrayNotHasKey('availableGroups', $this->service(isAdmin: false)->getSettings());
	}//end testTheGroupListIsOfferedToAdministratorsOnly()


	/**
	 * Every caller learns whether THEY may edit.
	 *
	 * That answer is about the caller, not about the configuration, which is
	 * why it is not admin-gated: an interface deciding whether to offer an
	 * editing affordance has to be able to ask.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testEveryCallerLearnsWhetherTheyMayEdit(): void {
		$settings = $this->service(isAdmin: false)->getSettings();

		$this->assertTrue($settings['mayEditPages']);
		$this->assertSame([], $settings['editor_groups']);
	}//end testEveryCallerLearnsWhetherTheyMayEdit()


	/**
	 * A write persists the register binding and reports the new state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
	 */
	public function testAWritePersistsTheRegisterBinding(): void {
		$settings = $this->service()->updateSettings(['register' => 'portaliq']);

		$this->assertSame('portaliq', $settings['register']);
		$this->assertSame('portaliq', $this->stored['register']);
	}//end testAWritePersistsTheRegisterBinding()


	/**
	 * Editor groups are delegated, not stored as a string like the rest.
	 *
	 * Storing them here would save the setting and never reach the schema,
	 * which is where OpenRegister actually refuses a non-editor's write — the
	 * operator would see their chosen groups on every visit while the refusal
	 * behaved exactly as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testEditorGroupsAreDelegatedToTheServiceThatWritesTheSchema(): void {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('getEditorGroups')->willReturn([]);
		$editor->method('mayEdit')->willReturn(true);
		$editor->method('availableGroups')->willReturn([]);
		$editor->expects($this->once())
			->method('setEditorGroups')
			->with(['redacteuren'])
			->willReturn(['redacteuren']);

		$this->service(pageEditor: $editor)->updateSettings(['editor_groups' => ['redacteuren']]);

		$this->assertArrayNotHasKey(
			'editor_groups',
			$this->stored,
			'the groups belong in the schema, not in a config string of their own'
		);
	}//end testEditorGroupsAreDelegatedToTheServiceThatWritesTheSchema()


	/**
	 * A non-array value is ignored rather than written as one group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testAMalformedEditorGroupsValueIsIgnored(): void {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('getEditorGroups')->willReturn([]);
		$editor->method('mayEdit')->willReturn(true);
		$editor->method('availableGroups')->willReturn([]);
		$editor->expects($this->never())->method('setEditorGroups');

		$this->service(pageEditor: $editor)->updateSettings(['editor_groups' => 'redacteuren']);
	}//end testAMalformedEditorGroupsValueIsIgnored()


	/**
	 * Without OpenRegister the import refuses, and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
	 */
	public function testTheImportRefusesWithoutOpenRegister(): void {
		$result = $this->service(orInstalled: false)->loadConfiguration();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('OpenRegister', $result['message']);
	}//end testTheImportRefusesWithoutOpenRegister()


	/**
	 * A successful import RE-APPLIES the configured editor groups.
	 *
	 * The seed it just imported declares empty write rules — admin-only. Left
	 * alone, every upgrade would silently un-configure page editing on an
	 * instance that had configured it, and the symptom would be an editor who
	 * could edit yesterday being refused today with the settings page still
	 * showing their group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function testTheImportReAppliesTheEditorGroups(): void {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('getEditorGroups')->willReturn(['redacteuren']);
		$editor->method('mayEdit')->willReturn(true);
		$editor->method('availableGroups')->willReturn([]);
		$editor->expects($this->once())
			->method('applyToSchema')
			->with(['redacteuren'])
			->willReturn(true);

		$configService = new class {

			/**
			 * Report a successful import.
			 *
			 * @param string $appId   The app id.
			 * @param array  $data    The register document.
			 * @param string $version The version.
			 * @param bool   $force   Whether to force.
			 *
			 * @return array The result.
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['version' => $version];
			}
		};

		$result = $this->service(pageEditor: $editor, configService: $configService)->loadConfiguration();

		$this->assertTrue($result['success']);
	}//end testTheImportReAppliesTheEditorGroups()


	/**
	 * An import that returns nothing is reported as a failure, not a success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
	 */
	public function testAnEmptyImportResultIsAFailure(): void {
		$configService = new class {

			/**
			 * Report an empty import.
			 *
			 * @param string $appId   The app id.
			 * @param array  $data    The register document.
			 * @param string $version The version.
			 * @param bool   $force   Whether to force.
			 *
			 * @return array The empty result.
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return [];
			}
		};

		$result = $this->service(configService: $configService)->loadConfiguration();

		$this->assertFalse($result['success']);
	}//end testAnEmptyImportResultIsAFailure()


	/**
	 * An import that throws is caught and reported, not fatal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
	 */
	public function testAThrowingImportIsReportedRatherThanFatal(): void {
		$configService = new class {

			/**
			 * Fail the import.
			 *
			 * @param string $appId   The app id.
			 * @param array  $data    The register document.
			 * @param string $version The version.
			 * @param bool   $force   Whether to force.
			 *
			 * @return array Never returns.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				throw new \RuntimeException('register rejected');
			}
		};

		$result = $this->service(configService: $configService)->loadConfiguration();

		$this->assertFalse($result['success']);
		$this->assertSame('register rejected', $result['message']);
	}//end testAThrowingImportIsReportedRatherThanFatal()


	/**
	 * OpenRegister's presence is reported from the app manager.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
	 */
	public function testOpenRegisterAvailabilityIsReported(): void {
		$this->assertTrue($this->service()->isOpenRegisterAvailable());
		$this->assertFalse($this->service(orInstalled: false)->isOpenRegisterAvailable());
	}//end testOpenRegisterAvailabilityIsReported()
}//end class
