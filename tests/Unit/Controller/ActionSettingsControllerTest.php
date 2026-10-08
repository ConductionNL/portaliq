<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ActionSettingsController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\PageEditorService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-ORA-001 and REQ-ORA-002: the grants screen reads the catalogue joined with
 * the stored matrix, and a save keeps only known actions and existing groups.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/tasks.md#t04
 */
#[CoversClass(ActionSettingsController::class)]
class ActionSettingsControllerTest extends TestCase {
	private string $stored = '{}';

	public function testNonAdminIsRefused(): void {
		// The framework refuses a non-admin unless a method opts out; neither does.
		foreach (['index', 'update'] as $method) {
			$reflection = new ReflectionMethod(ActionSettingsController::class, $method);
			$this->assertSame([], $reflection->getAttributes(), $method . ' must not widen access');
			$this->assertDoesNotMatchRegularExpression('/@(NoAdminRequired|PublicPage)\b/', (string)$reflection->getDocComment());
		}
	}//end testNonAdminIsRefused()

	public function testTheCatalogueIsJoinedWithTheStoredGrants(): void {
		$this->stored = '{"portal.provision":["admin","Service desk"]}';

		$rows = $this->controller()->index()->getData()['actions'];
		$byAction = array_column($rows, null, 'action');

		$this->assertSame(['Service desk'], $byAction['portal.provision']['groups']);
		$this->assertSame('Create and manage portal accounts', $byAction['portal.provision']['label']);
		$this->assertSame([], $byAction['portal.create-poll']['groups'], 'unstored: only administrators');
		$this->assertNotSame('', $byAction['portal.provision']['description']);
	}//end testTheCatalogueIsJoinedWithTheStoredGrants()

	public function testUnknownGroupIsDropped(): void {
		$this->controller()->update(['portal.provision' => ['Service desk', 'ghost']]);

		$this->assertSame(['Service desk'], json_decode($this->stored, true)['portal.provision']);
	}//end testUnknownGroupIsDropped()

	public function testUnknownActionIsDropped(): void {
		$this->controller()->update(['portal.export' => ['Service desk'], 'portal.create-poll' => ['Service desk']]);

		$matrix = json_decode($this->stored, true);
		$this->assertArrayNotHasKey('portal.export', $matrix);
		$this->assertSame(['Service desk'], $matrix['portal.create-poll']);
	}//end testUnknownActionIsDropped()

	public function testEmptyingAGrantReturnsToAdminsOnlyAndKeepsTheOthers(): void {
		$this->stored = '{"portal.provision":["Service desk"],"portal.author-news":["Communicatie"]}';

		$this->controller()->update(['portal.provision' => []]);

		$matrix = json_decode($this->stored, true);
		$this->assertSame(['admin'], $matrix['portal.provision']);
		$this->assertSame(['Communicatie'], $matrix['portal.author-news']);
	}//end testEmptyingAGrantReturnsToAdminsOnlyAndKeepsTheOthers()

	private function controller(): ActionSettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn () => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);

		$groups = $this->getMockBuilder(PageEditorService::class)->disableOriginalConstructor()->onlyMethods(['availableGroups'])->getMock();
		$groups->method('availableGroups')->willReturn([
			['id' => 'Service desk', 'label' => 'Service desk'],
			['id' => 'Communicatie', 'label' => 'Communicatie'],
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ActionSettingsController(
			$this->createMock(IRequest::class),
			new ActionAuthService($appConfig, $this->createMock(IGroupManager::class)),
			$groups,
			$l10n
		);
	}//end controller()
}//end class
