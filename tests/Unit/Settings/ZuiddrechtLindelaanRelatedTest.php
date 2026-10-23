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
 * @spec openspec/changes/example-set-lindelaan-related/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Gerelateerd tab's tables have rows on the Lindelaan case.
 *
 * @spec openspec/changes/example-set-lindelaan-related/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
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
		foreach ($subs as $sub) {
			$this->assertNotEmpty(actual: $sub['data']['deadline']);
		}
	}//end testTheLindelaanCaseHasTwoSubCases()

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
}//end class
