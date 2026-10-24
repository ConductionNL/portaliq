<?php

/**
 * Staff ask whether a citizen has a portal account; a citizen may not ask.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
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
 * @spec openspec/changes/portal-account-presence/specs/portal-identity-space/spec.md#requirement-staff-can-ask-whether-a-citizen-has-a-portal-account
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccountPresence;
use OCA\Portaliq\Service\PortalNameSetting;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Identity\PortalAccountPresence
 * @covers \OCA\Portaliq\Service\Identity\PortalAccountLookup
 */
class PortalAccountPresenceTest extends TestCase {

	private const BSN = '999990627';

	/**
	 * The service over these account rows, for this caller.
	 *
	 * @param array<int, array<string, mixed>> $rows The portal accounts.
	 * @param string|null                      $uid  The caller, null when signed out.
	 *
	 * @return PortalAccountPresence The service.
	 */
	private function presence(array $rows, ?string $uid): PortalAccountPresence {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef): array => array_values(
				array_filter($rows, static fn (array $row): bool => (($row[$scopeField] ?? '') === $subjectRef))
			)
		);
		$name = $this->createMock(PortalNameSetting::class);
		$name->method('name')->willReturn('Mijn Zuiddrecht');
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		return new PortalAccountPresence($reader, $name, $session);
	}//end presence()

	/**
	 * Staff learn that the citizen has an active DigiD account, and the portal's name.
	 *
	 * @return void
	 */
	public function testStaffSeeAnActiveAccount(): void {
		$rows = [['identityType' => 'digid', 'identityRef' => self::BSN, 'status' => 'active', 'subjectRef' => 'sanne']];

		$this->assertSame(
			['allowed' => true, 'hasAccount' => true, 'portalName' => 'Mijn Zuiddrecht'],
			$this->presence(rows: $rows, uid: 'pieter')->forCitizen(bsn: self::BSN)
		);
	}//end testStaffSeeAnActiveAccount()

	/**
	 * A pending or void account, or another identity type, is no mailbox.
	 *
	 * @return void
	 */
	public function testOnlyAnActiveCitizenAccountCounts(): void {
		foreach ([
			['identityType' => 'digid', 'identityRef' => self::BSN, 'status' => 'pending', 'subjectRef' => 'a'],
			['identityType' => 'eherkenning', 'identityRef' => self::BSN, 'status' => 'active', 'subjectRef' => 'b'],
		] as $row) {
			$this->assertFalse($this->presence(rows: [$row], uid: 'pieter')->forCitizen(bsn: self::BSN)['hasAccount']);
		}
	}//end testOnlyAnActiveCitizenAccountCounts()

	/**
	 * A citizen's own session, or nobody, may not ask.
	 *
	 * @return void
	 */
	public function testACitizenMayNotAsk(): void {
		$rows = [['identityType' => 'digid', 'identityRef' => self::BSN, 'status' => 'active', 'subjectRef' => 'sanne']];

		$this->assertSame(['allowed' => false, 'hasAccount' => false, 'portalName' => ''], $this->presence(rows: $rows, uid: 'sanne')->forCitizen(bsn: '111111110'));
		$this->assertFalse($this->presence(rows: $rows, uid: null)->forCitizen(bsn: self::BSN)['allowed']);
	}//end testACitizenMayNotAsk()
}//end class
