<?php

/**
 * Contract of MandateParties
 *
 * The public surface of MandateParties that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of MandateParties.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandatePartiesInterface {



	/**
	 * The parties a session carries as a holder: itself, and its company.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d7-who-holds-a-mandate-decided
	 */
	public function holdersOf(array $subject): array;

	/**
	 * The party a session may manage, from the session alone: the company of
	 * an eHerkenning sign-in, the person of any other. An eHerkenning session
	 * that does not carry its company's number manages nothing.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return string|null `kvk:<8 digits>`, `subject:<ref>`, or null.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d1-who-is-the-represented-party
	 */
	public function partyOf(array $subject): ?string;
}//end interface
