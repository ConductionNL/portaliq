<?php

/**
 * PortalFormTrustLevel: the strictest sign-in level a form demands, from the
 * portal, a fee, the binding and the form itself.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormTrustLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The trust level a form requires.
 */
class PortalFormTrustLevelTest extends TestCase {
	/**
	 * A portal that requires identified intake.
	 */
	private const IDENTIFIED = ['authentication' => ['requiresIdentifiedIntake' => true]];

	/**
	 * Without any requirement a form is anonymous.
	 *
	 * @return void
	 */
	public function testNoRequirementMeansAnonymous(): void {
		$this->assertNull(actual: (new PortalFormTrustLevel())->required(site: [], binding: [], render: []));
		$this->assertNull(
			actual: (new PortalFormTrustLevel())->required(
				site: ['authentication' => ['requiresIdentifiedIntake' => 'true']],
				binding: ['minTrust' => 'anonymous'],
				render: ['minTrust' => '0', 'fee' => 'EUR 5']
			)
		);
	}//end testNoRequirementMeansAnonymous()

	/**
	 * Declared levels that mean nothing is required.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function anonymousLevels(): array {
		return [
			'null'        => [null],
			'zero'        => [0],
			'false'       => [false],
			'empty'       => [''],
			'string zero' => ['0'],
			'anonymous'   => ['anonymous'],
		];
	}//end anonymousLevels()

	/**
	 * Each spelling of "no requirement" adds nothing.
	 *
	 * @param mixed $declared The declared level.
	 *
	 * @return void
	 */
	#[DataProvider('anonymousLevels')]
	public function testAnonymousSpellingsAddNothing(mixed $declared): void {
		$level = new PortalFormTrustLevel();
		$this->assertNull(actual: $level->required(site: [], binding: ['minTrust' => $declared], render: []));
		$this->assertSame(expected: 'low', actual: $level->required(site: self::IDENTIFIED, binding: [], render: ['minTrust' => $declared]));
	}//end testAnonymousSpellingsAddNothing()

	/**
	 * Declared levels that are not understood.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unrecognisedLevels(): array {
		return [
			'a typo'        => ['hihg'],
			'capitals'      => ['High'],
			'a number'      => [2],
			'true'          => [true],
			'a list'        => [['high']],
			'eidas spelled' => ['eIDAS-high'],
		];
	}//end unrecognisedLevels()

	/**
	 * A level that is not understood is the strictest of all: the form fails closed.
	 *
	 * @param mixed $declared The declared level.
	 *
	 * @return void
	 */
	#[DataProvider('unrecognisedLevels')]
	public function testAnUnrecognisedLevelFailsClosed(mixed $declared): void {
		$level = new PortalFormTrustLevel();
		$this->assertSame(
			expected: PortalFormTrustLevel::UNRECOGNISED,
			actual: $level->required(site: self::IDENTIFIED, binding: ['minTrust' => 'high'], render: ['minTrust' => $declared])
		);
		$this->assertSame(
			expected: PortalFormTrustLevel::UNRECOGNISED,
			actual: $level->required(site: [], binding: ['minTrust' => $declared], render: ['minTrust' => 'high'])
		);
	}//end testAnUnrecognisedLevelFailsClosed()

	/**
	 * Combinations of sources and the strictest level among them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
	 */
	public static function combinations(): array {
		return [
			'identified intake is low'           => [self::IDENTIFIED, [], [], 'low'],
			'a fee is substantial'               => [[], [], ['fee' => ['amount' => 500]], 'substantial'],
			'fee beats identified intake'        => [self::IDENTIFIED, [], ['fee' => []], 'substantial'],
			'binding high beats a fee'           => [[], ['minTrust' => 'high'], ['fee' => []], 'high'],
			'form low does not lower a fee'      => [[], [], ['fee' => [], 'minTrust' => 'low'], 'substantial'],
			'binding low, form substantial'      => [[], ['minTrust' => 'low'], ['minTrust' => 'substantial'], 'substantial'],
			'binding high, form low'             => [[], ['minTrust' => 'high'], ['minTrust' => 'low'], 'high'],
			'binding alone'                      => [[], ['minTrust' => 'substantial'], [], 'substantial'],
			'form alone'                         => [[], [], ['minTrust' => 'low'], 'low'],
		];
	}//end combinations()

	/**
	 * The required level is the strictest of every source; none can lower another.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The form binding.
	 * @param array<string, mixed> $render The rendered form.
	 * @param string $expected The required level.
	 *
	 * @return void
	 */
	#[DataProvider('combinations')]
	public function testTheStrictestLevelWins(array $site, array $binding, array $render, string $expected): void {
		$this->assertSame(expected: $expected, actual: (new PortalFormTrustLevel())->required(site: $site, binding: $binding, render: $render));
	}//end testTheStrictestLevelWins()
}//end class
