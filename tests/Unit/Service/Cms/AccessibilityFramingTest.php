<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Cms\AccessibilityFraming;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The site may be framed by its own origin only for a measurement request
 * from a user who may measure (site-accessibility-statement REQ-SAS-001).
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityFramingTest extends TestCase {

	public function testOnlyAMeasurementRequestFromAManagerMayBeFramed(): void {
		$this->assertTrue($this->framing(admin: true)->allowsSelf(request: $this->request(measure: '1')));
		$this->assertFalse($this->framing(admin: true)->allowsSelf(request: $this->request(measure: '')));
		$this->assertFalse($this->framing(admin: false)->allowsSelf(request: $this->request(measure: '1')));
		$this->assertFalse($this->framing(admin: true, signedIn: false)->allowsSelf(request: $this->request(measure: '1')));
	}//end testOnlyAMeasurementRequestFromAManagerMayBeFramed()

	private function request(string $measure): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($key === 'measure' ? $measure : $default));

		return $request;
	}//end request()

	private function framing(bool $admin, bool $signedIn = true): AccessibilityFraming {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{}');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$groups->method('getUserGroupIds')->willReturn(['residents']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		return new AccessibilityFraming(userSession: $session, actionAuth: new ActionAuthService($config, $groups));
	}//end framing()
}//end class
