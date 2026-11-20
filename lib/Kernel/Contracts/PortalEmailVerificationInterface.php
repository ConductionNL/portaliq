<?php

/**
 * Contract of PortalEmailVerification
 *
 * The public surface of PortalEmailVerification that other components call, so they depend
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
 * Contract of PortalEmailVerification.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalEmailVerificationInterface {

	public const SENT = 'sent';

	public const INVALID = 'invalid';

	public const WAIT = 'wait';

	public const THROTTLED = 'throttled';

	/**
	 * How many seconds must pass before a new code can be asked.
	 *
	 * @var int
	 */
	public const RESEND_AFTER = 60;

	/**
	 * Check a code. A right code answers a proof; a wrong one counts as a try.
	 *
	 * @param string $portal The portal's slug.
	 * @param string $route The form's route.
	 * @param string $email The address.
	 * @param string $code What the resident typed.
	 * @param int|null $now The unix second, for tests.
	 *
	 * @return array{ok: bool, error: string, proof: string} The outcome.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function check(string $portal, string $route, string $email, string $code, ?int $now=null): array;

	/**
	 * Send a code to an address.
	 *
	 * @param array<string, mixed> $site The serving portal.
	 * @param string $route The form's route.
	 * @param string $email The address.
	 * @param string $client The client's address, for the throttle.
	 * @param int|null $now The unix second, for tests.
	 *
	 * @return string SENT, INVALID, WAIT, THROTTLED, UNAVAILABLE or FAILED.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function request(array $site, string $route, string $email, string $client, ?int $now=null): string;

	/**
	 * The verified addresses of a submission, to record with it.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The accepted answers, all of them verified.
	 * @param string $verifiedAt The moment, as an ISO 8601 date-time.
	 *
	 * @return array<int, array{address: string, verifiedAt: string}> One line per verify field that was answered.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function record(array $fields, array $answers, string $verifiedAt): array;

	/**
	 * The errors for each `verify` e-mail field whose answer has no valid proof.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The accepted answers.
	 * @param array<string, mixed> $proofs Proofs by address, as the browser sent them.
	 * @param string $portal The portal's slug.
	 * @param string $route The form's route.
	 *
	 * @return array<string, string> The errors by field name.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function unverified(array $fields, array $answers, array $proofs, string $portal, string $route): array;
}//end interface
