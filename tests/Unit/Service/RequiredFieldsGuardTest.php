<?php

/**
 * RequiredFieldsGuard (site-multi-step-forms REQ-SMF-024): what counts as
 * empty, which fields it checks, and the refusal it answers.
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

use OCA\Portaliq\Service\RequiredFieldsGuard;
use PHPUnit\Framework\TestCase;

/**
 * The server half of an action's required fields.
 */
class RequiredFieldsGuardTest extends TestCase {
	/**
	 * The action under test: two required fields, one with its own words, an
	 * optional one and a required file field.
	 *
	 * @return array<string, mixed>
	 */
	private function action(): array {
		return [
			'id'           => 'startWooVerzoek',
			'fields'       => ['onderwerp', 'documentSoorten', 'toelichting', 'bijlage'],
			'fieldConfigs' => [
				'onderwerp'       => ['required' => true, 'requiredMessage' => 'Vertel waar uw verzoek over gaat'],
				'documentSoorten' => ['required' => true],
				'toelichting'     => ['size' => 'large'],
				'bijlage'         => ['required' => true, 'type' => 'file'],
			],
		];
	}//end action()

	/**
	 * Absent, null, blank text and an empty list are empty; zero and false
	 * are answers; the file field is never checked.
	 *
	 * @return void
	 */
	public function testWhatCountsAsEmpty(): void {
		$guard = new RequiredFieldsGuard();

		$this->assertSame(
			['onderwerp' => 'Vertel waar uw verzoek over gaat', 'documentSoorten' => ''],
			$guard->missing(action: $this->action(), body: ['onderwerp' => '   ', 'documentSoorten' => []])
		);
		$this->assertSame(['documentSoorten' => ''], $guard->missing(action: $this->action(), body: ['onderwerp' => 'Subsidies']));
		$this->assertSame([], $guard->missing(action: $this->action(), body: ['onderwerp' => 0, 'documentSoorten' => false]));
		$this->assertSame(
			['onderwerp' => 'Vertel waar uw verzoek over gaat', 'documentSoorten' => ''],
			$guard->missing(action: $this->action(), body: null)
		);
	}//end testWhatCountsAsEmpty()

	/**
	 * The refusal is a 400 naming every missing field; a full body goes on.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesEveryMissingField(): void {
		$guard   = new RequiredFieldsGuard();
		$refusal = $guard->refusal(action: $this->action(), body: ['documentSoorten' => ['besluiten']]);

		$this->assertNotNull($refusal);
		$this->assertSame(400, $refusal->getStatus());
		$this->assertSame(
			['error' => 'required_missing', 'errors' => ['onderwerp' => 'Vertel waar uw verzoek over gaat']],
			$refusal->getData()
		);
		$this->assertNull($guard->refusal(action: $this->action(), body: ['onderwerp' => 'x', 'documentSoorten' => ['alles']]));
	}//end testTheRefusalNamesEveryMissingField()

	/**
	 * An action without field configs, or with a malformed one, requires
	 * nothing.
	 *
	 * @return void
	 */
	public function testAnActionWithoutConfigsRequiresNothing(): void {
		$guard = new RequiredFieldsGuard();

		$this->assertSame([], $guard->missing(action: ['fields' => ['a']], body: []));
		$this->assertSame([], $guard->missing(action: ['fields' => ['a'], 'fieldConfigs' => 'x'], body: []));
		$this->assertSame([], $guard->missing(action: ['fields' => [7, 'a'], 'fieldConfigs' => ['a' => 'x']], body: []));
	}//end testAnActionWithoutConfigsRequiresNothing()
}//end class
