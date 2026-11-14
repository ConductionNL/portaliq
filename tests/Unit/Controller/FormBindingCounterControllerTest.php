<?php

/**
 * Unit tests for FormBindingCounterController.
 *
 * @category Tests
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\FormBindingCounterController;
use OCA\Portaliq\Service\Intake\FormBindingCounter;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The status panel's endpoint answers the counter without its `found` flag,
 * and 404 for a binding that cannot be read.
 *
 * @covers \OCA\Portaliq\Controller\FormBindingCounterController
 */
class FormBindingCounterControllerTest extends TestCase {

	/**
	 * A controller whose counter answers $answer for binding b-1.
	 *
	 * @param array<string, mixed> $answer The counter's answer.
	 *
	 * @return FormBindingCounterController
	 */
	private function controller(array $answer): FormBindingCounterController {
		$counter = $this->createMock(FormBindingCounter::class);
		$counter->expects($this->once())->method('forBinding')->with('b-1')->willReturn($answer);

		return new FormBindingCounterController(request: $this->createMock(IRequest::class), counter: $counter);
	}//end controller()

	/**
	 * The period's count against the limit.
	 *
	 * @return void
	 */
	public function testTheCounterAnswersTheCountAndTheLimit(): void {
		$response = $this->controller(['found' => true, 'limited' => true, 'count' => 41, 'limit' => 500, 'per' => 'month'])->show(id: 'b-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['limited' => true, 'count' => 41, 'limit' => 500, 'per' => 'month'], $response->getData());
	}//end testTheCounterAnswersTheCountAndTheLimit()

	/**
	 * A binding without a limit says so.
	 *
	 * @return void
	 */
	public function testABindingWithoutALimitIsNotLimited(): void {
		$response = $this->controller(['found' => true, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null])->show(id: 'b-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['limited' => false, 'count' => null, 'limit' => null, 'per' => null], $response->getData());
	}//end testABindingWithoutALimitIsNotLimited()

	/**
	 * An unknown binding answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownBindingIsNotFound(): void {
		$response = $this->controller(['found' => false, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null])->show(id: 'b-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'binding_not_found'], $response->getData());
	}//end testAnUnknownBindingIsNotFound()
}//end class
