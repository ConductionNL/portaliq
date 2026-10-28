<?php

/**
 * Example data for PqEvenement: the registration form of Informatieavond warmtenet Oost.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The event's portal form binding.
 */
class ZuiddrechtWarmtenetFormTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * A draft binding on the Zuiddrecht portal at /warmtenet-oost creates pipelinq eventRegistrations, and the event names it.
	 *
	 * @return void
	 */
	public function testTheEventHasItsFormBinding(): void {
		$fragment = $this->fragment();
		$objects  = array_column($fragment['objects'], null, 'key');
		$binding  = $objects['formulier-warmtenet-oost']['data'];

		$this->assertSame(expected: 'zuiddrecht', actual: $binding['portal']);
		$this->assertSame(expected: '/warmtenet-oost', actual: $binding['route']);
		$this->assertSame(expected: 'Aanmelden informatieavond', actual: $binding['formName']);
		$this->assertSame(expected: ['register' => 'pipelinq', 'schema' => 'eventRegistration'], actual: $binding['destination']);
		$this->assertSame(expected: 'draft', actual: $binding['status']);

		$changes = array_column($fragment['changes'], null, 'key');
		$change  = $changes['evenement-warmtenet-oost-formulier'];
		$this->assertSame(expected: ['reference' => 'EVT-2026-0012'], actual: $change['where']);
		$this->assertSame(expected: ['formBinding' => '{{object:formulier-warmtenet-oost.id}}'], actual: $change['set']);
	}//end testTheEventHasItsFormBinding()
}//end class
