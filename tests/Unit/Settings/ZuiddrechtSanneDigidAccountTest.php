<?php

/**
 * Example data for DqContact Bereikbaar via: Sanne de Vries signs in to Mijn
 * Zuiddrecht with DigiD, so her portal account names DigiD and her BSN and the
 * contact page offers "Mijn Zuiddrecht, berichtenbox".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Sanne's DigiD portal account.
 */
class ZuiddrechtSanneDigidAccountTest extends TestCase {

	/**
	 * Her account is a DigiD account on her BSN; the demo sign-in still finds it by her account id.
	 *
	 * @return void
	 */
	public function testSanneSignsInWithDigid(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$account = array_column($set['objects'], 'data', 'key')['portaalaccount-sanne'];

		$this->assertSame(['digid', '{{cast:sanne.bsn}}'], [$account['identityType'], $account['identityRef']]);
		$this->assertSame('{{cast:sanne.uid}}', $account['subjectRef']);
	}//end testSanneSignsInWithDigid()
}//end class
