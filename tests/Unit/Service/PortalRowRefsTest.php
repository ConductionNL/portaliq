<?php

/**
 * Unit tests for PortalRowRefs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalRowRefs;
use OCA\Portaliq\Service\Tenancy\SchemaTenancy;
use OCA\Portaliq\Service\ViaJoinRowFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * How a portal row is read, identified and matched against a scope.
 */
#[CoversClass(PortalRowRefs::class)]
#[UsesClass(SchemaTenancy::class)]
#[UsesClass(ViaJoinRowFilter::class)]
class PortalRowRefsTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var PortalRowRefs
	 */
	private PortalRowRefs $refs;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->refs = new PortalRowRefs();
	}//end setUp()


	/**
	 * A dotted path walks nested arrays; a missing segment is null, a present null stays null.
	 *
	 * @return void
	 */
	public function testDotGetWalksNestedArrays(): void {
		$row = ['case' => ['owner' => ['id' => 'u-1'], 'tags' => ['a']], 'empty' => null];

		$this->assertSame('u-1', $this->refs->dotGet($row, 'case.owner.id'));
		$this->assertSame(['a'], $this->refs->dotGet($row, 'case.tags'));
		$this->assertNull($this->refs->dotGet($row, 'case.owner.name'));
		$this->assertNull($this->refs->dotGet($row, 'case.owner.id.deeper'));
		$this->assertNull($this->refs->dotGet($row, 'empty'));
	}//end testDotGetWalksNestedArrays()


	/**
	 * A row's ids are its id and uuid, then those of its @self, as strings, without blanks.
	 *
	 * @return void
	 */
	public function testRowIdsCollectsEveryNonBlankId(): void {
		$this->assertSame(
			['7', 'u-1', 's-1', 's-2'],
			$this->refs->rowIds(['id' => 7, 'uuid' => 'u-1', '@self' => ['id' => 's-1', 'uuid' => 's-2']])
		);
		$this->assertSame([], $this->refs->rowIds(['id' => '', 'uuid' => 1.5, '@self' => 'x']));
	}//end testRowIdsCollectsEveryNonBlankId()


	/**
	 * A reference is one non-blank string or a list of them.
	 *
	 * @return void
	 */
	public function testTargetRefsAcceptsAStringOrAList(): void {
		$this->assertSame(['a'], $this->refs->targetRefs('a'));
		$this->assertSame(['a', 'b'], $this->refs->targetRefs(['a', '', 3, 'b']));
		$this->assertSame([], $this->refs->targetRefs(''));
		$this->assertSame([], $this->refs->targetRefs(42));
	}//end testTargetRefsAcceptsAStringOrAList()


	/**
	 * By id, a row is in the set when any of its ids is; by scope field, when any value of that field is.
	 *
	 * @return void
	 */
	public function testRowInTargetSetMatchesByIdOrScopeField(): void {
		$targets = ['u-1' => true, 'org-9' => true];

		$this->assertTrue($this->refs->rowInTargetSet(['@self' => ['id' => 'u-1']], $targets, 'id', 'org'));
		$this->assertFalse($this->refs->rowInTargetSet(['id' => 'u-2'], $targets, 'id', 'org'));
		$this->assertTrue($this->refs->rowInTargetSet(['meta' => ['org' => ['x', 'org-9']]], $targets, 'scopeField', 'meta.org'));
		$this->assertFalse($this->refs->rowInTargetSet(['id' => 'u-1', 'meta' => ['org' => 'x']], $targets, 'scopeField', 'meta.org'));
	}//end testRowInTargetSetMatchesByIdOrScopeField()


	/**
	 * A join row matches the scope value exactly, or by membership of a list.
	 *
	 * @return void
	 */
	public function testJoinRowMatchesByValueOrMembership(): void {
		$this->assertTrue($this->refs->joinRowMatches(['pupil' => 'p-1'], 'pupil', 'p-1'));
		$this->assertTrue($this->refs->joinRowMatches(['pupils' => ['p-0', 'p-1']], 'pupils', 'p-1'));
		$this->assertFalse($this->refs->joinRowMatches(['pupil' => 'p-2'], 'pupil', 'p-1'));
		$this->assertFalse($this->refs->joinRowMatches(['pupil' => 1], 'pupil', '1'));
	}//end testJoinRowMatchesByValueOrMembership()


	/**
	 * A via declaration needs four non-blank members, a known match, no nested via, and a valid join filter.
	 *
	 * @return void
	 */
	public function testIsValidViaChecksItsShape(): void {
		$via = ['register' => 'school', 'schema' => 'enrolment', 'scopeField' => 'parent', 'targetField' => 'pupil'];

		$this->assertTrue($this->refs->isValidVia($via));
		$this->assertTrue($this->refs->isValidVia($via + ['match' => 'scopeField']));
		$this->assertFalse($this->refs->isValidVia('via'));
		$this->assertFalse($this->refs->isValidVia(['schema' => ''] + $via));
		$this->assertFalse($this->refs->isValidVia(array_diff_key($via, ['targetField' => true])));
		$this->assertFalse($this->refs->isValidVia($via + ['match' => 'name']));
		$this->assertFalse($this->refs->isValidVia($via + ['via' => $via]));
		$this->assertFalse($this->refs->isValidVia($via + ['validUntilField' => '']));
	}//end testIsValidViaChecksItsShape()


	/**
	 * The scope pins its field over the caller's filter, and keys that are not names are dropped.
	 *
	 * @return void
	 */
	public function testScopedFiltersPinTheScopeField(): void {
		$this->assertSame(
			['status' => 'open', 'owner' => 'u-1'],
			$this->refs->scopedFilters(['status' => 'open', 'owner' => 'mallory', 0 => 'x', '' => 'y'], 'owner', 'u-1')
		);
		$this->assertSame(['status' => 'open'], $this->refs->scopedFilters(['status' => 'open'], 'owner', ''));
		$this->assertSame(['status' => 'open'], $this->refs->scopedFilters(['status' => 'open'], '', 'u-1'));
	}//end testScopedFiltersPinTheScopeField()


	/**
	 * On an organisation-scoped schema only the same organisation matches; elsewhere an unowned row does too.
	 *
	 * @return void
	 */
	public function testOrganisationMatchesFollowsTheSchemasTenancy(): void {
		$unowned = ['title' => 'x'];
		$theirs  = ['organisation' => 'org-b'];
		$ours    = ['organisation' => 'org-a'];

		$this->assertTrue($this->refs->organisationMatches($theirs, ''));
		$this->assertTrue($this->refs->organisationMatches($ours, 'org-a', 'portalCase'));
		$this->assertFalse($this->refs->organisationMatches($unowned, 'org-a', 'portalCase'));
		$this->assertTrue($this->refs->organisationMatches($unowned, 'org-a', 'page'));
		$this->assertTrue($this->refs->organisationMatches($unowned, 'org-a'));
		$this->assertFalse($this->refs->organisationMatches($theirs, 'org-a'));
	}//end testOrganisationMatchesFollowsTheSchemasTenancy()


	/**
	 * Tenancy is strict: an unowned row is not the tenant's.
	 *
	 * @return void
	 */
	public function testTenantMatchesIsStrict(): void {
		$this->assertTrue($this->refs->tenantMatches(['organisation' => 'org-a'], 'org-a'));
		$this->assertFalse($this->refs->tenantMatches([], 'org-a'));
		$this->assertTrue($this->refs->tenantMatches([], ''));
	}//end testTenantMatchesIsStrict()
}//end class
