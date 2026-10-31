<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ActionSettingsController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\PageEditorService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The rights per group, for the admin rights page (board PtBeheerdersrechten).
 *
 * @spec openspec/changes/admin-rights-page/specs/portal-admin-roles/spec.md#requirement-an-administrator-reads-the-rights-per-group-req-arp-001
 */
class ActionGroupsControllerTest extends TestCase {
	/**
	 * Each group that holds a right is listed once with its name, members and
	 * rights; administrators are not a row; a group without rights is absent.
	 *
	 * @return void
	 */
	public function testEachGroupWithARightIsListedWithItsRights(): void {
		$stored = '{"portal.provision":["servicedesk","communicatie"],"portal.author-news":["communicatie"]}';
		$data   = $this->controller(stored: $stored)->byGroup()->getData();

		$this->assertSame(expected: ['Communicatie', 'Servicedesk'], actual: array_column(array: $data['results'], column_key: 'name'));
		$this->assertSame(expected: [3, 5], actual: array_column(array: $data['results'], column_key: 'members'));
		$this->assertSame(expected: [2, 1], actual: array_column(array: $data['results'], column_key: 'count'));
		$this->assertNotContains(needle: 'admin', haystack: array_column(array: $data['results'], column_key: 'group'));
		$this->assertSame(expected: 2, actual: $data['total']);
	}//end testEachGroupWithARightIsListedWithItsRights()

	/**
	 * The route opts out of nothing: only an instance administrator reaches it.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorReachesIt(): void {
		$method = new ReflectionMethod(objectOrMethod: ActionSettingsController::class, method: 'byGroup');

		$this->assertEmpty(actual: $method->getAttributes(name: NoAdminRequired::class));
		$this->assertEmpty(actual: $method->getAttributes(name: PublicPage::class));
	}//end testOnlyAnAdministratorReachesIt()

	/**
	 * The controller over a stored matrix, two groups and the real seed.
	 *
	 * @param string $stored The stored matrix.
	 *
	 * @return ActionSettingsController
	 */
	private function controller(string $stored): ActionSettingsController {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(value: $stored);

		$editors = $this->createMock(originalClassName: PageEditorService::class);
		$editors->method('availableGroups')->willReturn(
			value: [['id' => 'servicedesk', 'label' => 'Servicedesk'], ['id' => 'communicatie', 'label' => 'Communicatie']]
		);

		$sizes = ['servicedesk' => 5, 'communicatie' => 3];
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($sizes): IGroup {
				$group = $this->createMock(originalClassName: IGroup::class);
				$group->method('count')->willReturn(value: $sizes[$gid]);
				return $group;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ActionSettingsController(
			request: $this->createMock(originalClassName: IRequest::class),
			actions: new ActionAuthService(appConfig: $appConfig, groupManager: $this->createMock(originalClassName: IGroupManager::class)),
			groups: $editors,
			l: $l10n,
			groupManager: $groupManager
		);
	}//end controller()
}//end class
