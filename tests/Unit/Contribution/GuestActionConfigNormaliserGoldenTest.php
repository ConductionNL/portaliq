<?php

/**
 * GuestActionConfigNormaliser::normaliseAction(): every guest action kept,
 * stripped or dropped, as recorded.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\GuestActionConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The guest action, as recorded.
 */
class GuestActionConfigNormaliserGoldenTest extends TestCase {
	/**
	 * A guest action needs a token field, low trust at most, and a local
	 * endpoint and preview; its texts stay only as strings; a non-guest
	 * flag is dropped and an action without one is untouched.
	 *
	 * @return void
	 */
	public function testEveryGuestActionIsAsRecorded(): void {
		$normaliser = new GuestActionConfigNormaliser();
		$guest      = ['id' => 'g', 'guest' => true, 'tokenField' => 'token', 'endpoint' => '/apps/x/api'];

		$this->assertSame(['id' => 'g'], $normaliser->normaliseAction(['id' => 'g']));
		$this->assertSame(['id' => 'g'], $normaliser->normaliseAction(['id' => 'g', 'guest' => 'true']));
		$this->assertSame(
			['id' => 'g', 'guest' => true, 'tokenField' => 'token', 'endpoint' => '/apps/x/api', 'label' => 'L', 'minTrust' => 'low', 'previewEndpoint' => '/apps/x/preview'],
			$normaliser->normaliseAction($guest + ['label' => 'L', 'confirmText' => 7, 'successText' => null, 'minTrust' => 'low', 'previewEndpoint' => '/apps/x/preview'])
		);

		foreach ([['tokenField' => '1token'], ['tokenField' => 7], ['minTrust' => 'substantial'], ['endpoint' => '//evil.example/x'], ['endpoint' => 'https://evil.example'], ['endpoint' => 'apps/x'], ['previewEndpoint' => null], ['previewEndpoint' => '/a://b']] as $bad) {
			$this->assertNull($normaliser->normaliseAction($bad + $guest), json_encode($bad));
		}
	}//end testEveryGuestActionIsAsRecorded()
}//end class
