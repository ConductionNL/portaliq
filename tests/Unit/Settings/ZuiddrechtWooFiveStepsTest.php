<?php
/**
 * Example data for the dossiq case boards: the Zuiddrecht Woo cases are on
 * version 2 of the Woo request case type, whose five steps the boards draw.
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
 * @spec openspec/specs/example-set-woo-five-steps/spec.md#requirement-the-zuiddrecht-woo-cases-are-on-the-five-step-version
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every Woo case of the fragment looks up version 2 and one of its five statuses.
 *
 * @spec openspec/specs/example-set-woo-five-steps/spec.md#requirement-the-zuiddrecht-woo-cases-are-on-the-five-step-version
 */
class ZuiddrechtWooFiveStepsTest extends TestCase {

	private const FIVE_STEPS = ['Ontvangen', 'In behandeling', 'Besluit', 'Publiceren', 'Afgehandeld'];

	/**
	 * The fragment's objects, keyed.
	 *
	 * @return array<string, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * Each Woo case type lookup names version 2, and each status lookup on it one of the five steps.
	 *
	 * @return void
	 */
	public function testEveryWooLookupIsOnVersionTwo(): void {
		$woo = 0;
		foreach ($this->objects() as $key => $object) {
			$lookups = ($object['lookups'] ?? []);
			$types = array_filter($lookups, static fn (array $lookup): bool => ($lookup['schema'] ?? '') === 'caseType' && ($lookup['where']['identifier'] ?? '') === 'woo-verzoek');
			foreach ($types as $type) {
				$woo++;
				$this->assertSame(expected: 2, actual: ($type['where']['version'] ?? null), message: $key);
			}

			if ($types === []) {
				continue;
			}

			foreach ($lookups as $lookup) {
				if (($lookup['schema'] ?? '') === 'statusType') {
					$this->assertContains(needle: $lookup['where']['name'], haystack: self::FIVE_STEPS, message: $key);
				}
			}
		}//end foreach

		$this->assertGreaterThan(expected: 2, actual: $woo);
	}//end testEveryWooLookupIsOnVersionTwo()

	/**
	 * The Lindelaan case is In behandeling after Ontvangen; its sub-cases In behandeling and Ontvangen.
	 *
	 * @return void
	 */
	public function testTheLindelaanCasesShowTheBoardsSteps(): void {
		$objects = $this->objects();
		$name = static fn (string $key, string $lookup): string => $objects[$key]['lookups'][$lookup]['where']['name'];

		$this->assertSame(expected: 'In behandeling', actual: $name('woo-fietspad', 'status'));
		$this->assertSame(expected: 'Ontvangen', actual: $name('woo-fietspad', 'ontvangen'));
		$this->assertSame(expected: 'In behandeling', actual: $name('woo-fietspad-deelzaak-zienswijze', 'status'));
		$this->assertSame(expected: 'Ontvangen', actual: $name('woo-fietspad-deelzaak-lakken', 'status'));
	}//end testTheLindelaanCasesShowTheBoardsSteps()
}//end class
