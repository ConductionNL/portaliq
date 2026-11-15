<?php

/**
 * Tests for the Yivi attribute filter and form prefill.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Signin
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-resident-can-sign-in-with-yivi-and-share-only-what-the-form-asks-req-rif-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\Signin\YiviAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a Yivi login keeps of the disclosed attributes, which a form asks for,
 * and which of its fields they fill.
 */
#[CoversClass(YiviAttributes::class)]
class YiviAttributesTest extends TestCase {

	private const NAME = 'pbdf.gemeente.personalData.fullname';

	private const EMAIL = 'pbdf.sidn-pbdf.email.email';

	/**
	 * The filter under test.
	 *
	 * @var YiviAttributes
	 */
	private YiviAttributes $yivi;

	/**
	 * Build the filter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->yivi = new YiviAttributes();
	}//end setUp()

	/**
	 * Attribute ids with the Yivi shape and text values are kept, trimmed.
	 *
	 * @return void
	 */
	public function testDisclosedAttributesAreKeptTrimmed(): void {
		$this->assertSame(
			[self::NAME => 'Sanne de Vries', self::EMAIL => 'sanne@example.nl'],
			$this->yivi->disclosed(claim: [self::NAME => ' Sanne de Vries ', self::EMAIL => 'sanne@example.nl'])
		);
	}//end testDisclosedAttributesAreKeptTrimmed()

	/**
	 * Anything that is not an attribute id with a text value is dropped.
	 *
	 * @return void
	 */
	public function testAnythingElseInTheClaimIsDropped(): void {
		$claim = [
			'bsn' => '123456789',
			'pbdf..name' => 'x',
			'pbdf.name space' => 'x',
			self::NAME => ['Sanne'],
			self::EMAIL => '   ',
			'pbdf.gemeente.personalData.over18' => 'Yes',
			'pbdf.gemeente.personalData.photo' => str_repeat('a', (YiviAttributes::MAX_VALUE_LENGTH + 1)),
			0 => 'zero',
		];

		$this->assertSame(['pbdf.gemeente.personalData.over18' => 'Yes'], $this->yivi->disclosed(claim: $claim));
		$this->assertSame([], $this->yivi->disclosed(claim: 'pbdf.a.b'));
		$this->assertSame([], $this->yivi->disclosed(claim: null));
	}//end testAnythingElseInTheClaimIsDropped()

	/**
	 * A claim carries at most MAX_ATTRIBUTES attributes, the first ones.
	 *
	 * @return void
	 */
	public function testAClaimIsCappedAtTheFirstAttributes(): void {
		$claim = [];
		for ($i = 0; $i < (YiviAttributes::MAX_ATTRIBUTES + 5); $i++) {
			$claim['pbdf.example.attr' . $i] = 'v' . $i;
		}

		$kept = $this->yivi->disclosed(claim: $claim);

		$this->assertCount(YiviAttributes::MAX_ATTRIBUTES, $kept);
		$this->assertSame('v0', $kept['pbdf.example.attr0']);
		$this->assertArrayNotHasKey('pbdf.example.attr' . YiviAttributes::MAX_ATTRIBUTES, $kept);
	}//end testAClaimIsCappedAtTheFirstAttributes()

	/**
	 * The form's request keeps valid entries, trims the field and keeps the
	 * first field of an attribute named twice.
	 *
	 * @return void
	 */
	public function testTheRequestedAttributesAreTheValidEntries(): void {
		$binding = [
			'yiviAttributes' => [
				['attribute' => self::NAME, 'field' => ' applicantName '],
				['attribute' => self::NAME, 'field' => 'otherField'],
				['attribute' => 'bsn', 'field' => 'bsn'],
				['attribute' => self::EMAIL, 'field' => ''],
				['attribute' => self::EMAIL],
				'not an entry',
				['attribute' => self::EMAIL, 'field' => 'applicantEmail'],
			],
		];

		$this->assertSame(
			[
				['attribute' => self::NAME, 'field' => 'applicantName'],
				['attribute' => self::EMAIL, 'field' => 'applicantEmail'],
			],
			$this->yivi->requested(binding: $binding)
		);
		$this->assertSame([], $this->yivi->requested(binding: []));
		$this->assertSame([], $this->yivi->requested(binding: ['yiviAttributes' => 'all']));
	}//end testTheRequestedAttributesAreTheValidEntries()

	/**
	 * Only attributes the form asks for fill fields, and only fields it has.
	 *
	 * @return void
	 */
	public function testThePrefillFillsOnlyAskedAttributesIntoExistingFields(): void {
		$binding = [
			'yiviAttributes' => [
				['attribute' => self::NAME, 'field' => 'applicantName'],
				['attribute' => self::EMAIL, 'field' => 'applicantEmail'],
				['attribute' => 'pbdf.gemeente.address.city', 'field' => 'city'],
			],
		];
		$disclosed = [
			self::NAME => 'Sanne de Vries',
			'pbdf.gemeente.address.city' => 'Zuiddrecht',
			'pbdf.gemeente.personalData.over18' => 'Yes',
		];
		$fields = [['name' => 'applicantName'], ['name' => 'applicantEmail'], ['name' => 'over18'], 'broken'];

		$this->assertSame(
			['applicantName' => 'Sanne de Vries'],
			$this->yivi->prefill(binding: $binding, disclosed: $disclosed, fields: $fields)
		);
	}//end testThePrefillFillsOnlyAskedAttributesIntoExistingFields()

	/**
	 * Two attributes aimed at one field: the first one disclosed fills it.
	 *
	 * @return void
	 */
	public function testTheFirstDisclosedAttributeOfAFieldWins(): void {
		$binding = [
			'yiviAttributes' => [
				['attribute' => self::EMAIL, 'field' => 'contact'],
				['attribute' => 'pbdf.pbdf.mobilenumber.mobilenumber', 'field' => 'contact'],
			],
		];

		$this->assertSame(
			['contact' => '0612345678'],
			$this->yivi->prefill(binding: $binding, disclosed: ['pbdf.pbdf.mobilenumber.mobilenumber' => '0612345678'], fields: [['name' => 'contact']])
		);
		$this->assertSame(
			['contact' => 'a@example.nl'],
			$this->yivi->prefill(
				binding: $binding,
				disclosed: [self::EMAIL => 'a@example.nl', 'pbdf.pbdf.mobilenumber.mobilenumber' => '0612345678'],
				fields: [['name' => 'contact']]
			)
		);
	}//end testTheFirstDisclosedAttributeOfAFieldWins()
}//end class
