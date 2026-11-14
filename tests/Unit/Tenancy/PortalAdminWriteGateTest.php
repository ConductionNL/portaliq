<?php

/**
 * PortalAdminWriteGate: a portal's administrator writes content of their own
 * portal only and never moves it to another, and only an instance
 * administrator changes `adminGroups` (operate-portals-per-organisation
 * REQ-OPO-005).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Kernel\Contracts\PageEditorServiceInterface;
use OCA\Portaliq\Service\Tenancy\PortalAdminGrants;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCA\Portaliq\Service\Tenancy\PortalAdminWriteGate;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PortalAdminWriteGate::class)]
#[UsesClass(PortalAdminScope::class)]
#[UsesClass(PortalAdminGrants::class)]
class PortalAdminWriteGateTest extends TestCase {

	private const NOT_YOURS = 'You do not look after this portal.';

	private const KEYS = 'Only an instance administrator can change who looks after a portal.';

	public function testAMemberWritesTheirOwnPortalsPage(): void {
		$gate = $this->gate(administers: ['zeist']);

		$this->assertNull($gate->refusal('page', ['portal' => 'zeist', 'title' => 'Afval']));
		$this->assertNull($gate->refusal('menu', ['portal' => 'zeist'], ['portal' => 'zeist']));
	}//end testAMemberWritesTheirOwnPortalsPage()

	public function testAMemberIsRefusedAnotherPortalsContent(): void {
		$gate = $this->gate(administers: ['zeist']);

		$this->assertSame(self::NOT_YOURS, $gate->refusal('page', ['portal' => 'utrecht']));
		$this->assertSame(self::NOT_YOURS, $gate->refusal('portalNotice', ['portal' => 'utrecht']));
		$this->assertSame(self::NOT_YOURS, $gate->refusal('media', ['portal' => '']));
	}//end testAMemberIsRefusedAnotherPortalsContent()

	public function testAMemberCannotMoveContentToOrFromAnotherPortal(): void {
		$gate = $this->gate(administers: ['zeist']);

		$this->assertSame(self::NOT_YOURS, $gate->refusal('page', ['portal' => 'utrecht'], ['portal' => 'zeist']));
		$this->assertSame(self::NOT_YOURS, $gate->refusal('page', ['portal' => 'zeist'], ['portal' => 'utrecht']));
	}//end testAMemberCannotMoveContentToOrFromAnotherPortal()

	public function testAMemberChangesTheirPortalButNotItsAdminGroups(): void {
		$gate   = $this->gate(administers: ['zeist']);
		$before = ['slug' => 'zeist', 'title' => 'Zeist', 'adminGroups' => ['zeist-beheer', 'zeist-redactie']];

		$this->assertNull($gate->refusal('portal', ['title' => 'Gemeente Zeist', 'adminGroups' => ['zeist-redactie', 'zeist-beheer']] + $before, $before));
		$this->assertSame(self::KEYS, $gate->refusal('portal', ['adminGroups' => ['zeist-beheer', 'zeist-redactie', 'iedereen']] + $before, $before));
		$this->assertSame(self::KEYS, $gate->refusal('portal', ['slug' => 'nieuw', 'adminGroups' => ['zeist-beheer']]));
	}//end testAMemberChangesTheirPortalButNotItsAdminGroups()

	public function testAnInstanceAdministratorAndAnInstanceEditorPass(): void {
		$admin  = $this->gate(administers: [], admin: true);
		$editor = $this->gate(administers: [], editor: true);

		$this->assertNull($admin->refusal('portal', ['adminGroups' => ['iedereen']], ['adminGroups' => []]));
		$this->assertNull($admin->refusal('page', ['portal' => 'utrecht']));
		$this->assertNull($editor->refusal('page', ['portal' => 'utrecht'], ['portal' => 'zeist']));
		$this->assertSame(self::KEYS, $editor->refusal('portal', ['adminGroups' => ['iedereen']], ['adminGroups' => []]));
	}//end testAnInstanceAdministratorAndAnInstanceEditorPass()

	public function testAWriteWithoutAUserOrOnAnotherSchemaIsNotThisGatesConcern(): void {
		$this->assertNull($this->gate(administers: [], user: false)->refusal('page', ['portal' => 'utrecht']));
		$this->assertNull($this->gate(administers: [])->refusal('portalMessage', ['portal' => 'utrecht']));
	}//end testAWriteWithoutAUserOrOnAnotherSchemaIsNotThisGatesConcern()

	/**
	 * The gate for a signed-in user.
	 *
	 * @param array<int, string> $administers The portal slugs the user looks after.
	 * @param bool               $admin       Whether the user is an instance administrator.
	 * @param bool               $editor      Whether the user is in the instance-wide editor groups.
	 * @param bool               $user        Whether anyone is signed in.
	 *
	 * @return PortalAdminWriteGate
	 */
	private function gate(array $administers, bool $admin = false, bool $editor = false, bool $user = true): PortalAdminWriteGate {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);

		$scope = $this->createMock(PortalAdminScope::class);
		$scope->method('isInstanceAdmin')->willReturn($admin);
		$scope->method('mayAdministerSlug')->willReturnCallback(static fn (string $slug): bool => in_array($slug, $administers, true));

		$pages = $this->createMock(PageEditorServiceInterface::class);
		$pages->method('mayEdit')->willReturn($editor);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PortalAdminWriteGate($session, $scope, $pages, $l10n);
	}//end gate()
}//end class
