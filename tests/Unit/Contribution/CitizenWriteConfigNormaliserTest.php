<?php

/**
 * Tests for the citizen write declaration a case app puts on an update action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A declaration the portal cannot trust is dropped whole, and a dropped
 * declaration closes the surface rather than opening it.
 *
 * @covers \OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteConfigNormaliserTest extends TestCase {
	/**
	 * The three required keys survive and the rest take their defaults, so a
	 * case app declares only what it moves.
	 */
	public function testTheDefaultsFillInWhatWasNotDeclared(): void {
		$action = (new CitizenWriteConfigNormaliser())->normaliseAction(action: [
			'type' => 'update',
			'citizenWrite' => [
				'typeField' => 'zaaktype',
				'typeRegister' => 'zaken',
				'typeSchema' => 'zaaktype',
			],
		]);

		$this->assertSame(
			[
				'typeField' => 'zaaktype',
				'typeRegister' => 'zaken',
				'typeSchema' => 'zaaktype',
				'statusField' => 'status',
				'recordField' => 'portalWrites',
				'documentsField' => 'portalDocuments',
			],
			$action['citizenWrite']
		);
	}//end testTheDefaultsFillInWhatWasNotDeclared()

	/**
	 * A declared override replaces the default.
	 */
	public function testADeclaredOverrideWins(): void {
		$action = (new CitizenWriteConfigNormaliser())->normaliseAction(action: [
			'type' => 'update',
			'citizenWrite' => [
				'typeField' => 'zaaktype',
				'typeRegister' => 'zaken',
				'typeSchema' => 'zaaktype',
				'statusField' => 'zaakstatus',
				'recordField' => 'portaalMutaties',
			],
		]);

		$this->assertSame('zaakstatus', $action['citizenWrite']['statusField']);
		$this->assertSame('portaalMutaties', $action['citizenWrite']['recordField']);
	}//end testADeclaredOverrideWins()

	/**
	 * Every malformed shape drops the whole declaration: a missing required
	 * key, a non-string key, a non-array declaration, and an action that is
	 * not an update.
	 *
	 * @dataProvider malformedDeclarations
	 *
	 * @param array<string, mixed> $action The action as declared.
	 */
	public function testAMalformedDeclarationIsDropped(array $action): void {
		$normalised = (new CitizenWriteConfigNormaliser())->normaliseAction(action: $action);

		$this->assertArrayNotHasKey('citizenWrite', $normalised);
	}//end testAMalformedDeclarationIsDropped()

	/**
	 * The malformed shapes.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformedDeclarations(): array {
		$complete = ['typeField' => 'zaaktype', 'typeRegister' => 'zaken', 'typeSchema' => 'zaaktype'];
		$missing = $complete;
		unset($missing['typeSchema']);
		$blank = $complete;
		$blank['typeRegister'] = '';
		$wrongType = $complete;
		$wrongType['typeField'] = ['zaaktype'];

		return [
			'missing key' => [['type' => 'update', 'citizenWrite' => $missing]],
			'blank value' => [['type' => 'update', 'citizenWrite' => $blank]],
			'non-string value' => [['type' => 'update', 'citizenWrite' => $wrongType]],
			'not an array' => [['type' => 'update', 'citizenWrite' => 'ja']],
			'not an update action' => [['type' => 'create', 'citizenWrite' => $complete]],
		];
	}//end malformedDeclarations()

	/**
	 * An action that declares nothing is returned untouched, so the key's
	 * absence never becomes an empty declaration something could read as open.
	 */
	public function testAnActionWithoutADeclarationIsUntouched(): void {
		$action = ['id' => 'plain', 'type' => 'update', 'fields' => ['a']];

		$this->assertSame($action, (new CitizenWriteConfigNormaliser())->normaliseAction(action: $action));
	}//end testAnActionWithoutADeclarationIsUntouched()
}//end class
