<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Service\ActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;

/**
 * An ActionAuthService double for the staff controllers: it grants or refuses
 * exactly the one action the controller under test is expected to check, and
 * refuses any other, so a test also pins which action an endpoint is gated by.
 */
trait StaffActionDoubleTrait {
	/**
	 * An ActionAuthService that grants (or refuses) `$action` only.
	 *
	 * @param string $action The action the controller must check.
	 * @param bool $granted Whether the signed-in user holds it.
	 *
	 * @return ActionAuthService
	 */
	private function staffActionAuth(string $action, bool $granted = true): ActionAuthService {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willReturnCallback(
			static function ($user, string $asked) use ($action, $granted): void {
				if ($asked !== $action || $granted === false) {
					throw new OCSForbiddenException("Action '{$asked}' not allowed for your groups");
				}
			}
		);

		return $actionAuth;
	}//end staffActionAuth()
}//end trait
