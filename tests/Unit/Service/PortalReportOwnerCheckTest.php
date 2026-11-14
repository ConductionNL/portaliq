<?php

/**
 * Unit tests for the owner a portal's error reports need.
 *
 * @category Test
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
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#1-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalReportOwnerCheck;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * REQ-PER-001 and REQ-PER-003: both settings are off on a new portal, and
 * error reports cannot be switched on without an existing user or group.
 *
 * @covers \OCA\Portaliq\Service\PortalReportOwnerCheck
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
class PortalReportOwnerCheckTest extends TestCase {

	/**
	 * The check over the given users and groups.
	 *
	 * @param array<int, string> $users  The users that exist.
	 * @param array<int, string> $groups The groups that exist.
	 *
	 * @return PortalReportOwnerCheck The check.
	 */
	private function check(array $users = [], array $groups = []): PortalReportOwnerCheck {
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $users, true));
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $groups, true));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new PortalReportOwnerCheck(users: $userManager, groups: $groupManager, l10n: $l10n);
	}//end check()

	/**
	 * Switching error reports on without an owner is refused, naming the missing owner; so is an owner that does not exist.
	 *
	 * @return void
	 */
	public function testErrorReportsNeedAnOwner(): void {
		$check = $this->check(users: ['alice'], groups: ['woo-redactie']);

		$this->assertSame(
			expected: 'Error reports need an owner: name the user or group that receives them.',
			actual: $check->refusal(portal: ['slug' => 'gemeente', 'publicationErrorReports' => true])
		);
		$this->assertSame(
			expected: 'Error reports need an owner: name the user or group that receives them.',
			actual: $check->refusal(portal: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => '   '])
		);
		$this->assertSame(
			expected: 'Error reports need an owner: name the user or group that receives them.',
			actual: $check->refusal(portal: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => ['woo-redactie']])
		);
		$this->assertSame(
			expected: 'Error reports need an owner, and "woo-redaktie" is not a user or group.',
			actual: $check->refusal(portal: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => 'woo-redaktie'])
		);
	}//end testErrorReportsNeedAnOwner()

	/**
	 * An existing user or group as owner lets error reports be switched on.
	 *
	 * @return void
	 */
	public function testAnExistingUserOrGroupOwnsTheReports(): void {
		$check = $this->check(users: ['alice'], groups: ['woo-redactie']);

		$this->assertNull(actual: $check->refusal(portal: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => 'alice']));
		$this->assertNull(actual: $check->refusal(portal: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => ' woo-redactie ']));
	}//end testAnExistingUserOrGroupOwnsTheReports()

	/**
	 * With reports off, or anything but a literal true, the owner is not asked for.
	 *
	 * @return void
	 */
	public function testReportsOffNeedNoOwner(): void {
		$check = $this->check();

		$this->assertNull(actual: $check->refusal(portal: []));
		$this->assertNull(actual: $check->refusal(portal: ['publicationErrorReports' => false, 'publicationErrorReportOwner' => 'nobody']));
		$this->assertNull(actual: $check->refusal(portal: ['publicationErrorReports' => 'true']));
	}//end testReportsOffNeedNoOwner()

	/**
	 * A new portal has error reports and withheld notices off and no owner, as the shipped schema declares.
	 *
	 * @return void
	 */
	public function testBothAreOffOnANewPortal(): void {
		$register = json_decode(
			json: (string) file_get_contents(filename: dirname(__DIR__, 3).'/lib/Settings/portaliq_register.json'),
			associative: true
		);
		$properties = $register['components']['schemas']['portal']['properties'];

		$shape = static fn (array $property): array => array_intersect_key($property, ['type' => 1, 'default' => 1]);
		$this->assertSame(expected: ['type' => 'boolean', 'default' => false], actual: $shape($properties['publicationErrorReports']));
		$this->assertSame(expected: ['type' => 'boolean', 'default' => false], actual: $shape($properties['showWithheldNotices']));
		$this->assertSame(expected: ['type' => 'string', 'default' => ''], actual: $shape($properties['publicationErrorReportOwner']));
		$this->assertNotContains(needle: 'publicationErrorReports', haystack: ($register['components']['schemas']['portal']['required'] ?? []));

		$defaults = array_map(static fn (array $property): mixed => ($property['default'] ?? null), $properties);
		$this->assertNull(actual: $this->check()->refusal(portal: $defaults), message: 'a portal with the shipped defaults saves');
	}//end testBothAreOffOnANewPortal()
}//end class
