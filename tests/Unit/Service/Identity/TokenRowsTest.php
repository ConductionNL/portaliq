<?php

/**
 * TokenRows: finding the stored row whose token hash matches, and reading a
 * row's identifier.
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

use OCA\Portaliq\Service\Identity\TokenRows;
use PHPUnit\Framework\TestCase;

/**
 * The token rows.
 */
class TokenRowsTest extends TestCase {
	/**
	 * The first row with the exact hash is returned.
	 *
	 * @return void
	 */
	public function testTheFirstRowWithTheHashIsReturned(): void {
		$hash = hash('sha256', 'secret');
		$rows = ['junk', ['id' => 'a', 'tokenHash' => hash('sha256', 'other')], ['id' => 'b', 'tokenHash' => $hash], ['id' => 'c', 'tokenHash' => $hash]];
		$this->assertSame(expected: ['id' => 'b', 'tokenHash' => $hash], actual: TokenRows::matching(rows: $rows, hash: $hash));
	}//end testTheFirstRowWithTheHashIsReturned()

	/**
	 * No row, a row without a hash, or a hash that differs only in case matches nothing.
	 *
	 * @return void
	 */
	public function testNothingMatchesWithoutTheExactHash(): void {
		$hash = hash('sha256', 'secret');
		$this->assertNull(actual: TokenRows::matching(rows: [], hash: $hash));
		$this->assertNull(actual: TokenRows::matching(rows: [['id' => 'a']], hash: $hash));
		$this->assertNull(actual: TokenRows::matching(rows: [['tokenHash' => strtoupper($hash)]], hash: $hash));
		$this->assertNull(actual: TokenRows::matching(rows: [['tokenHash' => substr($hash, 0, 32)]], hash: $hash));
	}//end testNothingMatchesWithoutTheExactHash()

	/**
	 * The identifier is the uuid, then the id, then the object metadata's.
	 *
	 * @return void
	 */
	public function testTheIdentifierIsReadInOrder(): void {
		$this->assertSame(expected: 'u1', actual: TokenRows::identifierOf(row: ['uuid' => 'u1', 'id' => 'i1']));
		$this->assertSame(expected: 'i1', actual: TokenRows::identifierOf(row: ['id' => 'i1', '@self' => ['uuid' => 's1']]));
		$this->assertSame(expected: '42', actual: TokenRows::identifierOf(row: ['id' => 42]));
		$this->assertSame(expected: 's1', actual: TokenRows::identifierOf(row: ['@self' => ['uuid' => 's1', 'id' => 's2']]));
		$this->assertSame(expected: 's2', actual: TokenRows::identifierOf(row: ['uuid' => '', '@self' => ['id' => 's2']]));
		$this->assertSame(expected: '', actual: TokenRows::identifierOf(row: ['title' => 'x']));
	}//end testTheIdentifierIsReadInOrder()
}//end class
