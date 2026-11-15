<?php

/**
 * Portaliq Portal Form Email Code Controller Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalFormEmailCodeController;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A code is sent only for a form of this portal that asks for it, and the
 * outcomes become statuses.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class PortalFormEmailCodeControllerTest extends TestCase {

	/**
	 * The controller over doubles.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param PortalEmailVerification $verification The code rules.
	 * @param bool $bound Whether a binding exists.
	 *
	 * @return PortalFormEmailCodeController
	 */
	private function controller(array $fields, PortalEmailVerification $verification, bool $bound=true): PortalFormEmailCodeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.0.0.9');
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'zuid']);
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('bindingFor')->willReturn($bound ? ['portal' => 'zuid'] : null);
		$bindings->method('render')->willReturn(['fields' => $fields]);

		return new PortalFormEmailCodeController($request, $portals, $bindings, $verification);
	}//end controller()

	/**
	 * No code goes to an unknown form or to a form with no verified address.
	 *
	 * @return void
	 */
	public function testNoCodeGoesToAFormThatDoesNotAskForOne(): void {
		$verification = $this->createMock(PortalEmailVerification::class);
		$verification->expects($this->never())->method('request');
		$verification->expects($this->never())->method('check');
		$plain = [['name' => 'mail', 'type' => 'email']];
		$this->assertSame(404, $this->controller(fields: $plain, verification: $verification)->send('a', 'x@example.nl')->getStatus());
		$this->assertSame(404, $this->controller(fields: $plain, verification: $verification)->check('a', 'x@example.nl', '123456')->getStatus());
		$verify = [['name' => 'mail', 'type' => 'email', 'verify' => true]];
		$this->assertSame(404, $this->controller(fields: $verify, verification: $verification, bound: false)->send('a', 'x@example.nl')->getStatus());
	}//end testNoCodeGoesToAFormThatDoesNotAskForOne()

	/**
	 * The outcomes of the service become statuses, and a right code answers its proof.
	 *
	 * @return void
	 */
	public function testOutcomesBecomeStatuses(): void {
		$verify = [['name' => 'mail', 'type' => 'email', 'verify' => true]];
		$verification = $this->createMock(PortalEmailVerification::class);
		$verification->method('request')->willReturnOnConsecutiveCalls('sent', 'invalid', 'wait', 'throttled', 'unavailable', 'failed');
		$controller = $this->controller(fields: $verify, verification: $verification);
		$sent = $controller->send('a', 'x@example.nl');
		$this->assertSame(200, $sent->getStatus());
		$this->assertSame(60, $sent->getData()['resendAfter']);
		$this->assertSame([400, 429, 429, 503, 503], array_map(static fn ($r): int => $r->getStatus(), [
			$controller->send('a', 'bad'),
			$controller->send('a', 'x@example.nl'),
			$controller->send('a', 'x@example.nl'),
			$controller->send('a', 'x@example.nl'),
			$controller->send('a', 'x@example.nl'),
		]));

		$verification->method('check')->willReturnOnConsecutiveCalls(['ok' => true, 'error' => '', 'proof' => 'p.q'], ['ok' => false, 'error' => 'wrong', 'proof' => '']);
		$right = $controller->check('a', 'x@example.nl', '123456');
		$this->assertSame(['verified' => true, 'proof' => 'p.q'], $right->getData());
		$wrong = $controller->check('a', 'x@example.nl', '000000');
		$this->assertSame(400, $wrong->getStatus());
		$this->assertSame('wrong', $wrong->getData()['error']);
	}//end testOutcomesBecomeStatuses()
}//end class
