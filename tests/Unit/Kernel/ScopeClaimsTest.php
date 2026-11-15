<?php

/**
 * ScopeClaims: which scope claims an app may not use, because the session
 * assertion already owns them.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Kernel
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

namespace OCA\Portaliq\Tests\Unit\Kernel;

use OCA\Portaliq\Kernel\ScopeClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reserved scope claims.
 */
class ScopeClaimsTest extends TestCase {
	/**
	 * Claims and whether they are reserved.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function claims(): array {
		return [
			'sub'                    => ['sub', true],
			'organisation'           => ['organisation', true],
			'expiry'                 => ['exp', true],
			'issuer'                 => ['iss', true],
			'the branch'             => ['branch', true],
			'an app-prefixed sub'    => ['dossiq.sub', true],
			'an app-prefixed branch' => ['pipelinq.branch', true],
			'an app claim'           => ['dossiq.caseRef', false],
			'a bare app claim'       => ['studentNumber', false],
			'case differs'           => ['Sub', false],
			'a claim ending in sub'  => ['dossiq.subject', false],
			'two dots'               => ['a.b.sub', false],
			'empty'                  => ['', false],
			'not a string'           => [['sub'], false],
			'null'                   => [null, false],
		];
	}//end claims()

	/**
	 * A claim is reserved when its name, after an optional app prefix, is an assertion claim.
	 *
	 * @param mixed $claim The scope claim.
	 * @param bool $reserved Whether it is reserved.
	 *
	 * @return void
	 */
	#[DataProvider('claims')]
	public function testReservedClaims(mixed $claim, bool $reserved): void {
		$this->assertSame(expected: $reserved, actual: ScopeClaims::isReserved(scopeClaim: $claim));
	}//end testReservedClaims()

	/**
	 * Every assertion claim is reserved, bare and prefixed.
	 *
	 * @return void
	 */
	public function testEveryAssertionClaimIsReserved(): void {
		foreach (ScopeClaims::RESERVED_ASSERTION_CLAIMS as $claim) {
			$this->assertTrue(condition: ScopeClaims::isReserved(scopeClaim: $claim), message: $claim);
			$this->assertTrue(condition: ScopeClaims::isReserved(scopeClaim: 'app.'.$claim), message: $claim);
		}
	}//end testEveryAssertionClaimIsReserved()
}//end class
