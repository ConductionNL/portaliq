<?php
/**
 * Example data for DqZaakGerelateerd: the Lindelaan Woo case has two sub-cases,
 * is about the earlier cycle-path permit, and names the objects it is about.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Gerelateerd tab's tables have rows on the Lindelaan case.
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
 */
class ZuiddrechtLindelaanRelatedTest extends TestCase {

	/**
	 * The fragment's objects, keyed, in file order.
	 *
	 * @return array<string, array<string, mixed>> The objects by key.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * Two sub-cases hang under the Lindelaan case, one with Lars and one with Pieter.
	 *
	 * @return void
	 */
	public function testTheLindelaanCaseHasTwoSubCases(): void {
		$subs = array_filter(
			$this->objects(),
			static fn (array $object): bool => ($object['schema'] === 'case') && (($object['data']['parentCase'] ?? '') === '{{object:woo-fietspad.id}}')
		);
		$titles = array_column(array_column($subs, 'data'), 'assignee', 'title');
		$this->assertSame(
			expected: ['Zienswijze Aannemersbedrijf Van Wijk' => '{{cast:lars.uid}}', 'Lakken offerte lantaarnpalen' => '{{cast:pieter.uid}}'],
			actual: $titles
		);
	}//end testTheLindelaanCaseHasTwoSubCases()

	/**
	 * A dossiq case row writes no property the case schema declares readOnly: dossiq derives those (`deadline`
	 * from the statutory term), OpenRegister keeps its own value, and the install reports the field as written
	 * but not kept (:8080, 24 Oct: both Lindelaan sub-cases' deadline). Read against the dossiq case schema
	 * fixture.
	 *
	 * @return void
	 */
	public function testACaseRowWritesNoReadOnlyProperty(): void {
		$schema   = json_decode((string)file_get_contents(__DIR__.'/../../Fixtures/dossiq-case-schema-d179.json'), true)['schema'];
		$readOnly = array_keys(
			array_filter($schema['properties'], static fn (mixed $p): bool => is_array($p) === true && ($p['readOnly'] ?? false) === true)
		);
		$written  = [];
		foreach ($this->objects() as $key => $object) {
			if (($object['register'] ?? '') !== 'dossiq' || ($object['schema'] ?? '') !== 'case') {
				continue;
			}

			foreach (array_intersect(array_keys($object['data'] ?? []), $readOnly) as $field) {
				$written[] = $key.'.'.$field;
			}
		}

		$this->assertSame(expected: [], actual: $written);
	}//end testACaseRowWritesNoReadOnlyProperty()

	/**
	 * The Woo case is about the cycle-path permit, which closed before it and comes first in the file.
	 *
	 * @return void
	 */
	public function testTheWooCaseIsAboutTheCyclePathPermit(): void {
		$objects = $this->objects();
		$keys = array_keys($objects);
		$permit = $objects['omgevingsvergunning-fietspad']['data'];
		$this->assertSame(expected: 'Aanleg fietspad Lindelaan', actual: $permit['title']);
		$this->assertNotEmpty(actual: $permit['endDate']);
		$this->assertLessThan(
			expected: array_search('woo-fietspad', $keys, true),
			actual: array_search('omgevingsvergunning-fietspad', $keys, true)
		);

		$woo = $objects['woo-fietspad']['data'];
		$this->assertSame(expected: ['{{object:omgevingsvergunning-fietspad.id}}'], actual: $woo['subjectCases']);
		$this->assertSame(
			expected: [['caseId' => '{{object:omgevingsvergunning-fietspad.id}}', 'aardRelatie' => 'subject']],
			actual: $woo['relatedCases']
		);
	}//end testTheWooCaseIsAboutTheCyclePathPermit()

	/**
	 * The case names the cycle path and two lamp posts it is about.
	 *
	 * @return void
	 */
	public function testTheCaseNamesItsObjects(): void {
		$links = array_values(
			array_filter(
				$this->objects(),
				static fn (array $object): bool => ($object['schema'] === 'caseObject') && (($object['data']['case'] ?? '') === '{{object:woo-fietspad.id}}')
			)
		);
		$this->assertSame(
			expected: ['BGT wgl.0505.1234', 'LM-0014', 'LM-0015'],
			actual: array_column(array_column($links, 'data'), 'objectIdentification')
		);
	}//end testTheCaseNamesItsObjects()

	/**
	 * Each case object points at its object in the public-space register,
	 * found by identification, so the case shows the object's own name and
	 * status. The two lamp-post reports link the lamp post they are about.
	 *
	 * @return void
	 */
	public function testTheCaseObjectsPointAtThePublicSpaceRegister(): void {
		$objects = array_column($this->objects(), null, 'key');
		$expected = [
			'woo-fietspad-object-fietspad'          => ['road-section', 'BGT wgl.0505.1234', 'woo-fietspad'],
			'woo-fietspad-object-lichtmast-14'      => ['street-light', 'LM-0014', 'woo-fietspad'],
			'woo-fietspad-object-lichtmast-15'      => ['street-light', 'LM-0015', 'woo-fietspad'],
			'melding-lantaarnpaal-object'           => ['street-light', 'LP-0703', 'melding-lantaarnpaal'],
			'melding-lantaarnpaal-lindelaan-object' => ['street-light', 'LP-1408', 'melding-lantaarnpaal-lindelaan'],
		];

		foreach ($expected as $key => [$schema, $identification, $case]) {
			$link = $objects[$key];
			$this->assertSame(
				expected: ['register' => 'public-space', 'schema' => $schema, 'where' => ['identification' => $identification]],
				actual: $link['lookups']['object'],
				message: $key
			);
			$this->assertSame(
				expected: '{{instance:baseUrl}}/index.php/apps/openregister/api/objects/public-space/'.$schema.'/{{lookup:object}}',
				actual: $link['data']['objectUrl'],
				message: $key
			);
			$this->assertSame(
				expected: [$identification, '{{object:'.$case.'.id}}'],
				actual: [$link['data']['objectIdentification'], $link['data']['case']],
				message: $key
			);
		}

		$this->assertSame(
			expected: ['Lantaarnpaal', 'Lantaarnpaal'],
			actual: [
				$objects['melding-lantaarnpaal-object']['data']['objectType'],
				$objects['melding-lantaarnpaal-lindelaan-object']['data']['objectType'],
			]
		);
	}//end testTheCaseObjectsPointAtThePublicSpaceRegister()
}//end class
