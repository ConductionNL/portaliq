<?php

/**
 * Portaliq fee of the published form (intake-pay-on-submit T09)
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Closure;
use OCA\Portaliq\Kernel\Contracts\PortalFeeInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;

/**
 * A fee reader that looks up the binding's published form itself, for a
 * caller that only holds the binding (the pay route), so it charges the
 * same fee the form page showed.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
 */
class PublishedFormFee implements PortalFeeInterface {
	/**
	 * Constructor.
	 *
	 * @param PortalFeeInterface                 $fees  Reads the fee of a binding and a form.
	 * @param PortalFormBindingResolverInterface $forms Finds the published form of a binding.
	 */
	public function __construct(
		private readonly PortalFeeInterface $fees,
		private readonly PortalFormBindingResolverInterface $forms,
	) {
	}//end __construct()

	/**
	 * The fee of a binding, reading its published form when the caller gives none.
	 *
	 * @param array<string, mixed>              $binding The binding.
	 * @param array<string, mixed>|Closure|null $form    The published form, or null to look it up.
	 *
	 * @return array{amount: string, currency: string, description: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function forBinding(array $binding, array|Closure|null $form = null): ?array {
		if ($form === null) {
			$forms = $this->forms;
			$form  = static fn (): ?array => $forms->publishedForm(binding: $binding);
		}

		return $this->fees->forBinding(binding: $binding, form: $form);
	}//end forBinding()

	/**
	 * What the resident is told about a payment, from the provider's status.
	 *
	 * @param string $status The `paymentStatus` of the payment intent.
	 *
	 * @return string `paid`, `unpaid`, `failed` or `unknown`.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function stateOf(string $status): string {
		return $this->fees->stateOf(status: $status);
	}//end stateOf()

	/**
	 * Whether the resident may be sent to an address to pay.
	 *
	 * @param mixed    $url   The checkout address the case app returned.
	 * @param string[] $hosts The portal's `paymentHosts`.
	 *
	 * @return bool True for https on a named host, nothing else.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function checkoutAllowed(mixed $url, array $hosts): bool {
		return $this->fees->checkoutAllowed(url: $url, hosts: $hosts);
	}//end checkoutAllowed()
}//end class
