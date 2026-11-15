<?php

/**
 * OwnAccountRows: the signed-in resident's own account row, read and
 * written without a portal scope.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\OwnAccountRows;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * The own account rows.
 */
class OwnAccountRowsTest extends TestCase {
	/**
	 * An account that is not removed is found by its subject.
	 *
	 * @return void
	 */
	public function testALiveAccountIsFound(): void {
		$lookup = $this->createMock(originalClassName: PortalAccountLookup::class);
		$lookup->expects($this->once())->method('bySubjectRef')->with('bsn-1')->willReturn(['id' => 'a1', 'status' => 'active']);
		$rows = new OwnAccountRows(accounts: $lookup, writer: $this->createMock(originalClassName: PortalObjectWriter::class));
		$this->assertSame(expected: ['id' => 'a1', 'status' => 'active'], actual: $rows->find(subjectRef: 'bsn-1'));
	}//end testALiveAccountIsFound()

	/**
	 * A removed account, no account, or no subject finds nothing.
	 *
	 * @return void
	 */
	public function testARemovedOrMissingAccountIsNotFound(): void {
		$lookup = $this->createMock(originalClassName: PortalAccountLookup::class);
		$lookup->method('bySubjectRef')->willReturnMap([['removed', ['id' => 'a1', 'status' => 'removed']], ['none', null]]);
		$rows = new OwnAccountRows(accounts: $lookup, writer: $this->createMock(originalClassName: PortalObjectWriter::class));
		$this->assertNull(actual: $rows->find(subjectRef: 'removed'));
		$this->assertNull(actual: $rows->find(subjectRef: 'none'));
	}//end testARemovedOrMissingAccountIsNotFound()

	/**
	 * An empty subject never reaches the lookup.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectIsNeverLookedUp(): void {
		$lookup = $this->createMock(originalClassName: PortalAccountLookup::class);
		$lookup->expects($this->never())->method('bySubjectRef');
		$rows = new OwnAccountRows(accounts: $lookup, writer: $this->createMock(originalClassName: PortalObjectWriter::class));
		$this->assertNull(actual: $rows->find(subjectRef: ''));
	}//end testAnEmptySubjectIsNeverLookedUp()

	/**
	 * An update writes the account schema by id, and reports whether a row was written.
	 *
	 * @return void
	 */
	public function testAnUpdateWritesTheAccountRow(): void {
		$writer = $this->createMock(originalClassName: PortalObjectWriter::class);
		$writer->expects($this->exactly(count: 2))->method('updateObject')
			->with('portaliq', 'portalAccount', '', '', '', 'a1', ['email' => 'x@example.org'])
			->willReturnOnConsecutiveCalls(['id' => 'a1'], null);
		$rows = new OwnAccountRows(accounts: $this->createMock(originalClassName: PortalAccountLookup::class), writer: $writer);
		$this->assertTrue(condition: $rows->update(id: 'a1', data: ['email' => 'x@example.org']));
		$this->assertFalse(condition: $rows->update(id: 'a1', data: ['email' => 'x@example.org']));
	}//end testAnUpdateWritesTheAccountRow()

	/**
	 * An update without an id writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnIdWritesNothing(): void {
		$writer = $this->createMock(originalClassName: PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');
		$rows = new OwnAccountRows(accounts: $this->createMock(originalClassName: PortalAccountLookup::class), writer: $writer);
		$this->assertFalse(condition: $rows->update(id: '', data: ['email' => 'x@example.org']));
	}//end testAnUpdateWithoutAnIdWritesNothing()
}//end class
