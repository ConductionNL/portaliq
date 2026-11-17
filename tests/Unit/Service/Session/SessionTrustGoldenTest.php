<?php

/**
 * Golden tests for the session edge's trust ranking.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Session
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

namespace OCA\Portaliq\Tests\Unit\Service\Session;

use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use PHPUnit\Framework\TestCase;

/**
 * Pins the trust ranking of the session edge, allowed and refused outcomes:
 * every subject trust against every declared minimum, plus the values that
 * normalise to `low` and the minimums no subject can meet. The delegates
 * left on PortalSessionService answer the same.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 */
class SessionTrustGoldenTest extends TestCase {

	/**
	 * Subject trust => minimum => satisfied.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const MATRIX = [
		'low' => ['' => true, 'low' => true, 'substantial' => false, 'high' => false, 'ultra' => false],
		'substantial' => ['' => true, 'low' => true, 'substantial' => true, 'high' => false, 'ultra' => false],
		'high' => ['' => true, 'low' => true, 'substantial' => true, 'high' => true, 'ultra' => false],
		'EH3' => ['' => true, 'low' => true, 'substantial' => false, 'high' => false, 'ultra' => false],
		'' => ['' => true, 'low' => true, 'substantial' => false, 'high' => false, 'ultra' => false],
	];

	public function testEverySubjectTrustAgainstEveryMinimum(): void {
		foreach (self::MATRIX as $subject => $row) {
			foreach ($row as $min => $expected) {
				$this->assertSame($expected, SessionTrust::trustSatisfies($subject, $min), "{$subject} vs {$min}");
			}
		}

	}//end testEverySubjectTrustAgainstEveryMinimum()

	public function testAMissingOrMalformedMinimum(): void {
		$this->assertTrue(SessionTrust::trustSatisfies('low', null));
		$this->assertFalse(SessionTrust::trustSatisfies('high', ['high']));
		$this->assertFalse(SessionTrust::trustSatisfies('high', 3));
		$this->assertFalse(SessionTrust::trustSatisfies('high', 'High'));
		$this->assertFalse(SessionTrust::trustSatisfies(['high'], 'substantial'));

	}//end testAMissingOrMalformedMinimum()

	public function testNormalisation(): void {
		foreach (['low', 'substantial', 'high'] as $known) {
			$this->assertSame($known, SessionTrust::normaliseTrust(trust: $known));
		}

		foreach (['', 'dev', 'EH3', 'HIGH', null, 3, ['high']] as $unknown) {
			$this->assertSame('low', SessionTrust::normaliseTrust(trust: $unknown));
		}

	}//end testNormalisation()

	public function testTheSessionServiceDelegatesAnswerTheSame(): void {
		foreach (self::MATRIX as $subject => $row) {
			foreach (array_keys($row) as $min) {
				$this->assertSame(SessionTrust::trustSatisfies($subject, $min), PortalSessionService::trustSatisfies($subject, $min));
			}

			$this->assertSame(SessionTrust::normaliseTrust(trust: $subject), PortalSessionService::normaliseTrust(trust: $subject));
		}

	}//end testTheSessionServiceDelegatesAnswerTheSame()
}//end class
