<?php

/**
 * PortalScopeMatch: whether a row's stored scope, one value or a list,
 * names the scope being read.
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

use OCA\Portaliq\Service\PortalScopeMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scope match.
 */
class PortalScopeMatchTest extends TestCase {
	/**
	 * A class that uses the trait and opens its two checks.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use PortalScopeMatch;

			/**
			 * Whether the stored scope names the value.
			 *
			 * @param mixed $stored The stored scope.
			 * @param string $value The scope being read.
			 *
			 * @return bool
			 */
			public function matches(mixed $stored, string $value): bool {
				return $this->scopeMatches(stored: $stored, scopeValue: $value);
			}//end matches()

			/**
			 * Whether the stored scope is a list.
			 *
			 * @param mixed $stored The stored scope.
			 *
			 * @return bool
			 */
			public function isList(mixed $stored): bool {
				return $this->isScopeList(stored: $stored);
			}//end isList()
		};
	}//end host()

	/**
	 * Stored scopes, the scope read and whether they match.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: bool}>
	 */
	public static function scopes(): array {
		return [
			'the same value'          => ['org-1', 'org-1', true],
			'another value'           => ['org-2', 'org-1', false],
			'an integer'              => [7, '7', true],
			'in a list'               => [['org-2', 'org-1'], 'org-1', true],
			'an integer in a list'    => [[3, 7], '7', true],
			'not in the list'         => [['org-2'], 'org-1', false],
			'an empty list'           => [[], 'org-1', false],
			'a map is not a list'     => [['a' => 'org-1'], 'org-1', false],
			'nested lists do not open' => [[['org-1']], 'org-1', false],
			'an empty read never'     => ['', '', false],
			'null stored'             => [null, 'org-1', false],
			'a float is no reference' => [7.0, '7', false],
			'a bool is no reference'  => [true, '1', false],
			'case matters'            => ['ORG-1', 'org-1', false],
		];
	}//end scopes()

	/**
	 * A scope matches when the value, or a member of the list, is the scope read.
	 *
	 * @param mixed $stored The stored scope.
	 * @param string $value The scope being read.
	 * @param bool $expected Whether they match.
	 *
	 * @return void
	 */
	#[DataProvider('scopes')]
	public function testTheScopeMatches(mixed $stored, string $value, bool $expected): void {
		$this->assertSame(expected: $expected, actual: $this->host()->matches(stored: $stored, value: $value));
	}//end testTheScopeMatches()

	/**
	 * Only a list counts as a scope list.
	 *
	 * @return void
	 */
	public function testOnlyAListIsAScopeList(): void {
		$host = $this->host();
		$this->assertTrue(condition: $host->isList(stored: []));
		$this->assertTrue(condition: $host->isList(stored: ['a', 'b']));
		$this->assertFalse(condition: $host->isList(stored: ['a' => 'b']));
		$this->assertFalse(condition: $host->isList(stored: 'a'));
		$this->assertFalse(condition: $host->isList(stored: null));
	}//end testOnlyAListIsAScopeList()
}//end class
