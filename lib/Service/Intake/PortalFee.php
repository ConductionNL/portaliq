<?php

/**
 * Portaliq Fee (intake-pay-on-submit)
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
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Closure;
use OCA\Portaliq\Kernel\Contracts\PortalFeeInterface;
use OCA\Portaliq\Service\CaseTypeReader;

/**
 * What a request costs, and where the resident may be sent to pay it.
 *
 * The fee is the case type's declaration, with the published form's own fee
 * over its amount when the form serves one: the portal holds no price and
 * takes no amount from the browser. A declaration that does not
 * read as a positive amount in a three-letter currency is no fee at all, and
 * a checkout address is followed only when it is https on a host the portal
 * names.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
 */
class PortalFee implements PortalFeeInterface {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $caseTypes Reads the binding's case type.
	 */
	public function __construct(private readonly CaseTypeReader $caseTypes) {
	}//end __construct()

	/**
	 * The fee of a binding, or null when the request is free: the case type's
	 * declaration, with the amount, currency and description of the published
	 * form's own `fee` when the form serves one. The pay action always comes
	 * from the case type (decision 25 Nov 2026), so a form fee on a case type
	 * without one is no fee.
	 *
	 * @param array<string, mixed>               $binding The binding.
	 * @param array<string, mixed>|Closure|null  $form    The published form, a callable that reads it
	 *                                                    (called only when the case type declares a fee), or null.
	 *
	 * @return array{amount: string, currency: string, description: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function forBinding(array $binding, array|Closure|null $form = null): ?array {
		$caseType = $this->caseTypes->readCaseType(
			register: (string)($binding['typeRegister'] ?? ''),
			schema: (string)($binding['typeSchema'] ?? ''),
			id: (string)($binding['typeId'] ?? '')
		);
		if ($caseType === null) {
			return null;
		}

		$declared = ($caseType['portalFee'] ?? null);
		if (is_array($declared) === false) {
			// No fee and no pay action: the form is not read at all.
			return null;
		}

		return $this->normalise(declared: $this->withFormFee(declared: $declared, form: $form));
	}//end forBinding()

	/**
	 * The case type's declaration with the form's amount, currency and
	 * description over it, or the declaration as it is when the form serves
	 * no fee.
	 *
	 * @param array<string, mixed>              $declared The case type's `portalFee`.
	 * @param array<string, mixed>|Closure|null $form     The published form, or a callable that reads it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	private function withFormFee(array $declared, array|Closure|null $form): array {
		if ($form instanceof Closure) {
			$form = $form();
		}

		$own = null;
		if (is_array($form) === true) {
			$own = ($form['fee'] ?? null);
		}

		if (is_array($own) === false || isset($own['amount']) === false) {
			return $declared;
		}

		// The form says what the request costs; the case type says which of
		// its actions takes the payment.
		return [
			'amount' => $this->amountText(amount: $own['amount']),
			'currency' => (string)($own['currency'] ?? 'EUR'),
			'description' => (string)($own['description'] ?? ''),
			'payAction' => (string)($declared['payAction'] ?? ''),
		];
	}//end withFormFee()

	/**
	 * A served amount as the decimal text normalise() reads: buildiq serves a
	 * number, a case type declares a string.
	 *
	 * @param mixed $amount The served amount.
	 *
	 * @return string The amount as text, or '' when it is not a number.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	private function amountText(mixed $amount): string {
		if (is_int($amount) === true || is_float($amount) === true) {
			return (string)$amount;
		}

		if (is_string($amount) === true) {
			return $amount;
		}

		return '';
	}//end amountText()

	/**
	 * A declaration in its safe shape, or null when it is not a usable fee.
	 *
	 * @param mixed $declared The `portalFee` object.
	 *
	 * @return array{amount: string, currency: string, description: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
	 */
	public function normalise(mixed $declared): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$amount   = trim((string)($declared['amount'] ?? ''));
		$currency = strtoupper(trim((string)($declared['currency'] ?? 'EUR')));
		$action   = trim((string)($declared['payAction'] ?? ''));
		if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount) !== 1 || (float)$amount <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $action === '') {
			return null;
		}

		return [
			'amount' => number_format((float)$amount, 2, '.', ''),
			'currency' => $currency,
			'description' => trim((string)($declared['description'] ?? '')),
			'payAction' => $action,
		];
	}//end normalise()

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
	public function checkoutAllowed(mixed $url, array $hosts): bool {
		$host = $this->secureHost(url: $url);
		if ($host === '') {
			return false;
		}

		foreach ($hosts as $allowed) {
			if (is_string($allowed) === true && strtolower(trim($allowed)) === $host) {
				return true;
			}
		}

		return false;
	}//end checkoutAllowed()

	/**
	 * The lower-case host of an https address without credentials or odd characters, or ''.
	 *
	 * @param mixed $url The address.
	 *
	 * @return string The host, or '' when the address is not one to send a resident to.
	 */
	private function secureHost(mixed $url): string {
		if (is_string($url) === false || preg_match('/[[:cntrl:]\s\\\\]/', $url) === 1) {
			return '';
		}

		$parts = parse_url($url);
		if ($parts === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return '';
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true) {
			return '';
		}

		return strtolower((string)($parts['host'] ?? ''));
	}//end secureHost()

	/**
	 * What the resident is told about a payment, from the provider's status.
	 *
	 * @param string $status The `paymentStatus` of the payment intent.
	 *
	 * @return string `paid`, `unpaid`, `failed` or `unknown`.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function stateOf(string $status): string {
		return match ($status) {
			'paid', 'authorized' => 'paid',
			'open', 'pending' => 'unpaid',
			'failed', 'canceled', 'expired' => 'failed',
			default => 'unknown',
		};
	}//end stateOf()
}//end class
