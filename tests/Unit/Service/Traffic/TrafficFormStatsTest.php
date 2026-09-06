<?php

/**
 * Unit tests for TrafficFormStats.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficFormStats;
use PHPUnit\Framework\TestCase;

/**
 * Starts, submits, abandons and the field people leave on.
 */
class TrafficFormStatsTest extends TestCase {

	/**
	 * A form event.
	 *
	 * @param string               $name   The event name.
	 * @param array<string, mixed> $params The params.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $name, array $params): array {
		return ['name' => $name, 'pagePath' => '/campagne', 'params' => $params];
	}//end event()


	/**
	 * Two starts, one submit, one abandon on the email field; the email
	 * field's average time is the mean of its visits.
	 *
	 * @return void
	 */
	public function testAFormIsCountedFromItsEvents(): void {
		$sessions = [
			['visitor' => 'h1', 'explicit' => false, 'events' => [
				$this->event('form_start', ['formId' => 'aanmelden']),
				$this->event('form_field', ['formId' => 'aanmelden', 'fieldId' => 'name', 'ms' => 4000]),
				$this->event('form_field', ['formId' => 'aanmelden', 'fieldId' => 'email', 'ms' => 30000]),
				$this->event('form_submit', ['formId' => 'aanmelden']),
			]],
			['visitor' => 'h2', 'explicit' => false, 'events' => [
				$this->event('form_start', ['formId' => 'aanmelden']),
				$this->event('form_field', ['formId' => 'aanmelden', 'fieldId' => 'email', 'ms' => 10000]),
				$this->event('form_abandon', ['formId' => 'aanmelden', 'lastFieldId' => 'email']),
			]],
			['visitor' => 'h3', 'explicit' => false, 'events' => [$this->event('page_view', [])]],
		];

		$rows = (new TrafficFormStats())->rows(sessions: $sessions);

		$this->assertCount(1, $rows);
		$this->assertSame('aanmelden', $rows[0]['formId']);
		$this->assertSame(2, $rows[0]['starts']);
		$this->assertSame(1, $rows[0]['submits']);
		$this->assertSame(1, $rows[0]['abandons']);
		$this->assertSame(0.5, $rows[0]['completionRate']);
		$this->assertSame(
			[
				['fieldId' => 'email', 'avgMs' => 20000, 'abandonedHere' => 1],
				['fieldId' => 'name', 'avgMs' => 4000, 'abandonedHere' => 0],
			],
			$rows[0]['fields'],
			'the field most left is first'
		);
	}//end testAFormIsCountedFromItsEvents()


	/**
	 * A form event without a form id, or a value-shaped param, counts for
	 * nothing and stores nothing: this class reads ids and times only.
	 *
	 * @return void
	 */
	public function testAFormEventWithoutAnIdIsIgnored(): void {
		$rows = (new TrafficFormStats())->rows(sessions: [
			['visitor' => 'h1', 'explicit' => false, 'events' => [
				$this->event('form_start', []),
				$this->event('form_field', ['fieldId' => 'name', 'ms' => 100]),
			]],
		]);

		$this->assertSame([], $rows);
	}//end testAFormEventWithoutAnIdIsIgnored()


	/**
	 * A submit with no start still counts (a form on an external page whose
	 * client only reports submits), with a completion rate of 0 rather than
	 * a division by zero.
	 *
	 * @return void
	 */
	public function testASubmitWithoutAStartHasNoRate(): void {
		$rows = (new TrafficFormStats())->rows(sessions: [
			['visitor' => 'h1', 'explicit' => false, 'events' => [$this->event('form_submit', ['formId' => 'contact'])]],
		]);

		$this->assertSame(1, $rows[0]['submits']);
		$this->assertSame(0, $rows[0]['starts']);
		$this->assertSame(0.0, $rows[0]['completionRate']);
		$this->assertSame([], $rows[0]['fields']);
	}//end testASubmitWithoutAStartHasNoRate()
}
