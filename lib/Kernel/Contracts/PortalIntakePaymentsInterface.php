<?php

/**
 * Contract of PortalIntakePayments
 *
 * The public surface of PortalIntakePayments that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of PortalIntakePayments.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalIntakePaymentsInterface {

	/**
	 * Take the payment for a request of the signed-in subject.
	 *
	 * @param array<string, mixed>      $subject   The signed-in subject.
	 * @param array<string, mixed>|null $site      The resolved portal, or null.
	 * @param string                    $reference The request's reference.
	 *
	 * @return JSONResponse `{checkoutUrl}` or the refusal.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function start(array $subject, ?array $site, string $reference): JSONResponse;

	/**
	 * What the reference page says about the object behind a reference, with
	 * the payment state the object holds (decision 181), or null when no
	 * object answers to it. The state is never read from the address the
	 * resident returned on.
	 *
	 * @param string $reference  The reference.
	 * @param string $portal     The portal's slug.
	 * @param string $subjectRef The signed-in subject, or ''.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#3.3
	 */
	public function statusWithPayment(string $reference, string $portal, string $subjectRef = ''): ?array;
}//end interface
