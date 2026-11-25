<?php

/**
 * DestinationRefusal and InboxDeletion: what a resident is told when a
 * destination refuses a form, and which inbox messages a resident may delete.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Kernel
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

namespace OCA\Portaliq\Tests\Unit\Kernel;

use OCA\Portaliq\Kernel\Contracts\PortalInboxReaderInterface;
use OCA\Portaliq\Kernel\DestinationRefusal;
use OCA\Portaliq\Kernel\InboxDeletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The destination refusal and the inbox deletion rule.
 */
class DestinationRefusalTest extends TestCase {
	/**
	 * A destination's answer and what the resident is told.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
	 */
	public static function answers(): array {
		return [
			'refused fields'        => [
				['status' => 422, 'errors' => ['postcode' => 'unknown']],
				['body' => ['error' => 'refused_by_destination', 'errors' => ['postcode' => 'unknown']], 'status' => 422],
			],
			'refused without list'  => [['status' => 422], ['body' => ['error' => 'refused_by_destination', 'errors' => []], 'status' => 422]],
			'forbidden'             => [['status' => 403, 'errors' => ['x']], ['body' => ['error' => 'form_not_submittable'], 'status' => 403]],
			'not found'             => [['status' => 404], ['body' => ['error' => 'form_not_submittable'], 'status' => 404]],
			'a server error'        => [['status' => 500], ['body' => ['error' => 'try_again_later'], 'status' => 503]],
			'a numeric string'      => [['status' => '422'], ['body' => ['error' => 'refused_by_destination', 'errors' => []], 'status' => 422]],
			'no status'             => [[], ['body' => ['error' => 'try_again_later'], 'status' => 503]],
			'no answer at all'      => [null, ['body' => ['error' => 'try_again_later'], 'status' => 503]],
			'an unexpected success' => [['status' => 201], ['body' => ['error' => 'try_again_later'], 'status' => 503]],
			'with its error code'   => [['status' => 503, 'code' => 'OF-1A2B'], ['body' => ['error' => 'try_again_later', 'code' => 'OF-1A2B'], 'status' => 503]],
			'an empty error code'   => [['status' => 503, 'code' => ''], ['body' => ['error' => 'try_again_later'], 'status' => 503]],
			'a code on a refusal'   => [['status' => 404, 'code' => 'OF-1A2B'], ['body' => ['error' => 'form_not_submittable'], 'status' => 404]],
		];
	}//end answers()

	/**
	 * Field refusals pass their errors on, a closed form is named, anything else is retryable.
	 *
	 * @param array<string, mixed>|null $submitted The destination's answer.
	 * @param array<string, mixed> $expected What the resident is told.
	 *
	 * @return void
	 */
	#[DataProvider('answers')]
	public function testTheRefusalTheResidentSees(?array $submitted, array $expected): void {
		$this->assertSame(expected: $expected, actual: DestinationRefusal::of(submitted: $submitted));
	}//end testTheRefusalTheResidentSees()

	/**
	 * The resident's own messages may always be deleted; others only when the collection says so.
	 *
	 * @return void
	 */
	public function testWhichMessagesAResidentMayDelete(): void {
		$own = PortalInboxReaderInterface::OWN_MESSAGES;
		$this->assertTrue(condition: InboxDeletion::residentMayDelete(collection: ['register' => $own['register'], 'schema' => $own['schema']]));
		$this->assertTrue(condition: InboxDeletion::residentMayDelete(collection: ['register' => 'zaken', 'schema' => 'bericht', 'deletable' => true]));
		$this->assertFalse(condition: InboxDeletion::residentMayDelete(collection: ['register' => 'zaken', 'schema' => 'bericht']));
		$loose = ['register' => 'zaken', 'schema' => 'bericht', 'deletable' => 'true'];
		$this->assertFalse(condition: InboxDeletion::residentMayDelete(collection: $loose));
		$this->assertFalse(condition: InboxDeletion::residentMayDelete(collection: ['register' => $own['register'], 'schema' => 'other']));
		$this->assertFalse(condition: InboxDeletion::residentMayDelete(collection: []));
	}//end testWhichMessagesAResidentMayDelete()
}//end class
