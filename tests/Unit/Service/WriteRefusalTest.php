<?php

/**
 * WriteRefusal (site-action-forms): which written field a store refusal
 * names, and the kind of value it wants.
 *
 * The messages are OpenRegister's own wording (ValidateObject), as the
 * proof instance logged them on 9 October 2026.
 *
 * @category Tests
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

use OCA\Portaliq\Service\WriteRefusal;
use PHPUnit\Framework\TestCase;

/**
 * A refused value names its field, and only a field the portal wrote.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
 */
class WriteRefusalTest extends TestCase {
	/**
	 * The hours logged as text: the field, as a number.
	 *
	 * @return void
	 */
	public function testANumberSentAsTextNamesTheFieldAsANumber(): void {
		$invalid = (new WriteRefusal())->invalidFields(
			failure: "Property 'hoursSubmitted' should be type 'number' but is 'string'. Make sure the value is a number.",
			fields: ['bpvPlacementId', 'isoWeek', 'hoursSubmitted']
		);

		$this->assertSame(['hoursSubmitted' => 'number'], $invalid);
	}//end testANumberSentAsTextNamesTheFieldAsANumber()

	/**
	 * A path, a date format, a missing value and an unknown rule each map to
	 * their kind.
	 *
	 * @return void
	 */
	public function testEachRuleMapsToItsKind(): void {
		$refusal = new WriteRefusal();

		$this->assertSame(
			['count' => 'integer'],
			$refusal->invalidFields(failure: "Property '/count' should be type 'integer' but is 'string'.", fields: ['count'])
		);
		$this->assertSame(
			['birthDate' => 'date'],
			$refusal->invalidFields(failure: "Property 'birthDate' should match format 'date' but '1.3.2026' does not.", fields: ['birthDate'])
		);
		$this->assertSame(
			['isoWeek' => 'required'],
			$refusal->invalidFields(failure: 'The required property (isoWeek) is missing. Add it.', fields: ['isoWeek'])
		);
		$this->assertSame(
			['address' => 'invalid'],
			$refusal->invalidFields(failure: "Property 'address.street' allows at most 80 chars, has 90.", fields: ['address'])
		);
	}//end testEachRuleMapsToItsKind()

	/**
	 * A field the portal did not write, or a message without a property,
	 * names nothing and gives no response.
	 *
	 * @return void
	 */
	public function testAnotherFieldOrAnotherFailureNamesNothing(): void {
		$refusal = new WriteRefusal();

		$this->assertSame([], $refusal->invalidFields(failure: "Property 'organisation' should be type 'string' but is 'null'.", fields: ['notes']));
		$this->assertSame([], $refusal->invalidFields(failure: 'Database connection lost', fields: ['notes']));
		$this->assertNull($refusal->response(failure: '', data: ['notes' => 'x']));
	}//end testAnotherFieldOrAnotherFailureNamesNothing()

	/**
	 * The response is a 422 with the kinds and never the store's text.
	 *
	 * @return void
	 */
	public function testTheResponseIsA422WithoutTheStoresText(): void {
		$response = (new WriteRefusal())->response(
			failure: "Property 'hoursSubmitted' should be type 'number' but is 'string'.",
			data: ['hoursSubmitted' => '8', 'isoWeek' => '2026-W41']
		);

		$this->assertNotNull($response);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'invalid', 'invalid' => ['hoursSubmitted' => 'number']], $response->getData());
	}//end testTheResponseIsA422WithoutTheStoresText()
}//end class
