<?php

/**
 * Contract of PortalChallengeService
 *
 * The public surface of PortalChallengeService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PortalChallengeService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalChallengeServiceInterface {

	/**
	 * Whether a submission may proceed.
	 *
	 * The honeypot is checked first and on its own: it costs nothing, and a
	 * filled honeypot is a bot whatever else it sent.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $surface The surface the submission came from.
	 * @param array<string, mixed> $submission The submitted fields, honeypot
	 *                                         included.
	 * @param string $nonce The nonce this visitor was issued.
	 * @param string $solution The visitor's solution.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return bool True when the submission may proceed.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function accepts(
		array $site,
		string $surface,
		array $submission,
		string $nonce,
		string $solution,
		int $expiresAt = 0,
		string $signature = '',
		?DateTimeImmutable $now = null,
	): bool;

	/**
	 * Issue a challenge for a surface.
	 *
	 * The visitor gets the nonce, the moment it stops counting, and this
	 * instance's signature over both plus the surface and the portal. All
	 * three come back on the submission, and all three are checked there.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $surface The surface asking, e.g. `form` or `registration`.
	 * @param DateTimeImmutable|null $now The moment to date the nonce from.
	 *
	 * @return array{nonce: string, expiresAt: int, signature: string, difficulty: int, algorithm: string}
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function issue(array $site, string $surface, ?DateTimeImmutable $now = null): array;
}//end interface
