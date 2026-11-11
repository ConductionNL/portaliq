<?php

/**
 * Every guard the app puts on the pre-delete event also hears a move out of the register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Listener\MediaWriteGuardListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * OpenRegister dispatches ObjectLeavingEvent, not ObjectDeletingEvent, before a
 * move takes an object out of its register, so a guard on the delete event alone
 * is skipped by a move.
 */
final class DeleteGuardsHearAMoveTest extends TestCase {
	/**
	 * The app's own register() puts every delete guard on the move event too.
	 *
	 * @return void
	 */
	public function testEveryDeleteGuardAlsoHearsAMove(): void {
		$recorded = [];
		$context  = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$recorded): void {
				$recorded[$event][] = $listener;
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$deleting = array_unique($recorded['OCA\\OpenRegister\\Event\\ObjectDeletingEvent'] ?? []);
		$this->assertContains(needle: MediaWriteGuardListener::class, haystack: $deleting);
		$this->assertSame(expected: [], actual: array_values(array_diff($deleting, ($recorded['OCA\\OpenRegister\\Event\\ObjectLeavingEvent'] ?? []))));
	}//end testEveryDeleteGuardAlsoHearsAMove()
}//end class
