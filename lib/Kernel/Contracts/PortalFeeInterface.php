<?php

/**
 * Contract of PortalFee
 *
 * The public surface of PortalFee that other components call, so they depend
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
 * Contract of PortalFee.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFeeInterface {

	/**
	 * The fee of a binding, or null when the request is free: the case type's
	 * declaration, with the amount, currency and description of the published
	 * form's own `fee` when the form serves one.
	 *
	 * @param array<string, mixed>                    $binding The binding.
	 * @param array<string, mixed>|\Closure|null      $form    The published form, a callable that reads it
	 *                                                         (called only when the case type declares a fee), or null.
	 *
	 * @return array{amount: string, currency: string, description: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function forBinding(array $binding, array|\Closure|null $form = null): ?array;

	/**
	 * What the resident is told about a payment, from the provider's status.
	 *
	 * @param string $status The `paymentStatus` of the payment intent.
	 *
	 * @return string `paid`, `unpaid`, `failed` or `unknown`.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function stateOf(string $status): string;

	/**
	 * Whether the resident may be sent to an address to pay.
	 *
	 * @param mixed    $url   The checkout address the case app returned.
	 * @param string[] $hosts The portal's `paymentHosts`.
	 *
	 * @return bool True for https on a named host, nothing else.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function checkoutAllowed(mixed $url, array $hosts): bool;
}//end interface
