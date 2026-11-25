<?php

/**
 * SessionClaims: the session subject and the reference session read from
 * verified token claims.
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

use OCA\Portaliq\Service\SessionClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The session claims.
 */
class SessionClaimsTest extends TestCase {
	/**
	 * A full set of claims is copied into the subject, in a fixed order.
	 *
	 * @return void
	 */
	public function testTheSubjectCarriesEveryClaim(): void {
		$subject = (new SessionClaims())->subject(
			claims: [
				'sub'              => 'bsn-123',
				'audience'         => 'business',
				'organisation'     => 'org-1',
				'roles'            => ['owner'],
				'jti'              => 'j1',
				'authTime'         => '1700000000',
				'branch'           => '000012345678',
				'branchRestricted' => true,
				'provider'         => 'eherkenning',
				'exp'              => 1700003600,
				'kvk'              => '12345678',
				'extra'            => 'ignored',
			],
			trust: 'substantial'
		);
		$this->assertSame(
			expected: [
				'subjectRef'       => 'bsn-123',
				'audience'         => 'business',
				'organisation'     => 'org-1',
				'trust'            => 'substantial',
				'roles'            => ['owner'],
				'jti'              => 'j1',
				'authTime'         => 1700000000,
				'branch'           => '000012345678',
				'branchRestricted' => true,
				'provider'         => 'eherkenning',
				'expiresAt'        => 1700003600,
				'kvk'              => '12345678',
				'attributes'       => [],
				'portal'           => '',
			],
			actual: $subject
		);
	}//end testTheSubjectCarriesEveryClaim()

	/**
	 * Missing claims read as empty values, never as null.
	 *
	 * @return void
	 */
	public function testMissingClaimsAreEmpty(): void {
		$this->assertSame(
			expected: [
				'subjectRef'       => '',
				'audience'         => '',
				'organisation'     => '',
				'trust'            => 'low',
				'roles'            => [],
				'jti'              => '',
				'authTime'         => 0,
				'branch'           => '',
				'branchRestricted' => false,
				'provider'         => '',
				'expiresAt'        => 0,
				'kvk'              => '',
				'attributes'       => [],
				'portal'           => '',
			],
			actual: (new SessionClaims())->subject(claims: [], trust: 'low')
		);
	}//end testMissingClaimsAreEmpty()

	/**
	 * KvK numbers and whether they are kept.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function kvkNumbers(): array {
		return [
			'eight digits'  => ['12345678', '12345678'],
			'seven digits'  => ['1234567', ''],
			'nine digits'   => ['123456789', ''],
			'an integer'    => [12345678, ''],
			'with a space'  => ['1234 5678', ''],
			'with letters'  => ['1234567a', ''],
			'a newline'     => ["12345678\n", ''],
		];
	}//end kvkNumbers()

	/**
	 * Only an eight-digit string is a KvK number.
	 *
	 * @param mixed $kvk The claimed number.
	 * @param string $expected The kept number.
	 *
	 * @return void
	 */
	#[DataProvider('kvkNumbers')]
	public function testOnlyEightDigitsAreAKvkNumber(mixed $kvk, string $expected): void {
		$this->assertSame(expected: $expected, actual: (new SessionClaims())->subject(claims: ['kvk' => $kvk], trust: 'low')['kvk']);
	}//end testOnlyEightDigitsAreAKvkNumber()

	/**
	 * A branch is restricted only when the claim says true and a branch is named.
	 *
	 * @return void
	 */
	public function testABranchRestrictionNeedsABranch(): void {
		$claims = new SessionClaims();
		$branch = '000012345678';
		$this->assertFalse(condition: $claims->subject(claims: ['branchRestricted' => true], trust: 'low')['branchRestricted']);
		$this->assertFalse(condition: $claims->subject(claims: ['branchRestricted' => 'true', 'branch' => $branch], trust: 'low')['branchRestricted']);
		$this->assertTrue(condition: $claims->subject(claims: ['branchRestricted' => true, 'branch' => $branch], trust: 'low')['branchRestricted']);
	}//end testABranchRestrictionNeedsABranch()

	/**
	 * A malformed branch number reads as no branch, though the restriction still holds.
	 *
	 * @return void
	 */
	public function testAMalformedBranchReadsAsNone(): void {
		$subject = (new SessionClaims())->subject(claims: ['branch' => '1234', 'branchRestricted' => true], trust: 'low');
		$this->assertSame(expected: '', actual: $subject['branch']);
		$this->assertTrue(condition: $subject['branchRestricted']);
	}//end testAMalformedBranchReadsAsNone()

	/**
	 * A reference session carries the case it was issued for and nothing of the subject's roles.
	 *
	 * @return void
	 */
	public function testTheReferenceCarriesTheCase(): void {
		$claims = new SessionClaims();
		$this->assertSame(
			expected: [
				'subjectRef'    => 'ref-1',
				'organisation'  => 'org-1',
				'caseReference' => 'Z-2026-1',
				'register'      => 'cases',
				'schema'        => 'case',
				'jti'           => 'j2',
			],
			actual: $claims->reference(
				claims: [
					'sub'           => 'ref-1',
					'organisation'  => 'org-1',
					'caseReference' => 'Z-2026-1',
					'register'      => 'cases',
					'schema'        => 'case',
					'jti'           => 'j2',
					'roles'         => ['owner'],
				]
			)
		);
		$this->assertSame(
			expected: ['subjectRef' => '', 'organisation' => '', 'caseReference' => '', 'register' => '', 'schema' => '', 'jti' => ''],
			actual: $claims->reference(claims: [])
		);
	}//end testTheReferenceCarriesTheCase()
	/**
	 * A signed portal slug reads back; a malformed one reads as no portal.
	 *
	 * @spec openspec/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
	 *
	 * @return void
	 */
	public function testThePortalClaimReadsBackOnlyAsASlug(): void {
		$claims = new SessionClaims();
		$this->assertSame('portal-a', $claims->subject(claims: ['portal' => 'portal-a'], trust: 'low')['portal']);
		foreach (['Portal-A', '-a', 'a b', ['portal-a'], 7, str_repeat('a', 64)] as $bad) {
			$this->assertSame('', $claims->subject(claims: ['portal' => $bad], trust: 'low')['portal'], var_export($bad, true));
		}
	}//end testThePortalClaimReadsBackOnlyAsASlug()
}//end class
