<?php

/**
 * AccountProvisionRun: whether an account may be provisioned, and the
 * identity fields it is written with.
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

use OCA\Portaliq\Service\AccountProvisionRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The account provision run.
 */
class AccountProvisionRunTest extends TestCase {
	/**
	 * A run with the given parts.
	 *
	 * @param string $audience The audience.
	 * @param string $organisation The organisation.
	 * @param string $type The identity type.
	 * @param string $ref The identity reference.
	 * @param string $email The email address.
	 *
	 * @return AccountProvisionRun
	 */
	private function provisionRun(string $audience, string $organisation, string $type, string $ref, string $email): AccountProvisionRun {
		$run = new AccountProvisionRun(audience: $audience, organisation: $organisation);
		$run->identityType = $type;
		$run->identityRef  = $ref;
		$run->email        = $email;
		return $run;
	}//end provisionRun()

	/**
	 * Runs and whether they may provision an account.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
	 */
	public static function runs(): array {
		return [
			'an identity'               => ['resident', 'org-1', 'digid', 'bsn-1', '', true],
			'an email address'          => ['resident', 'org-1', '', '', 'a@example.org', true],
			'both'                      => ['business', 'org-1', 'eherkenning', '12345678', 'a@example.org', true],
			'no audience'               => ['', 'org-1', 'digid', 'bsn-1', 'a@example.org', false],
			'no organisation'           => ['resident', '', 'digid', 'bsn-1', 'a@example.org', false],
			'half an identity, no mail' => ['resident', 'org-1', 'digid', '', '', false],
			'a reference without type'  => ['resident', 'org-1', '', 'bsn-1', '', false],
			'nothing to know them by'   => ['resident', 'org-1', '', '', '', false],
		];
	}//end runs()

	/**
	 * An account needs an audience, an organisation, and an identity or an address.
	 *
	 * @param string $audience The audience.
	 * @param string $organisation The organisation.
	 * @param string $type The identity type.
	 * @param string $ref The identity reference.
	 * @param string $email The email address.
	 * @param bool $may Whether the run may provision.
	 *
	 * @return void
	 */
	#[DataProvider('runs')]
	public function testWhetherARunMayProvision(string $audience, string $organisation, string $type, string $ref, string $email, bool $may): void {
		$run = $this->provisionRun(audience: $audience, organisation: $organisation, type: $type, ref: $ref, email: $email);
		$this->assertSame(expected: $may, actual: $run->isProvisionable());
	}//end testWhetherARunMayProvision()

	/**
	 * The identity fields are written only for a whole identity.
	 *
	 * @return void
	 */
	public function testIdentityFieldsNeedAWholeIdentity(): void {
		$whole = $this->provisionRun(audience: 'resident', organisation: 'org-1', type: 'digid', ref: 'bsn-1', email: '');
		$this->assertTrue(condition: $whole->hasIdentity());
		$this->assertSame(expected: ['identityType' => 'digid', 'identityRef' => 'bsn-1'], actual: $whole->identityFields());
		$half = $this->provisionRun(audience: 'resident', organisation: 'org-1', type: 'digid', ref: '', email: 'a@example.org');
		$this->assertFalse(condition: $half->hasIdentity());
		$this->assertSame(expected: [], actual: $half->identityFields());
	}//end testIdentityFieldsNeedAWholeIdentity()
}//end class
