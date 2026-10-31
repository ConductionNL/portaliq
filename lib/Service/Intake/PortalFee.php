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

use OCA\Portaliq\Service\CaseTypeReader;

/**
 * What a request costs, and where the resident may be sent to pay it.
 *
 * The fee is the case type's declaration and nothing else: the portal holds
 * no price and takes no amount from the browser. A declaration that does not
 * read as a positive amount in a three-letter currency is no fee at all, and
 * a checkout address is followed only when it is https on a host the portal
 * names.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
 */
class PortalFee {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $caseTypes Reads the binding's case type.
	 */
	public function __construct(private readonly CaseTypeReader $caseTypes) {
	}//end __construct()

	/**
	 * The fee declared on a binding's case type, or null when the request is free.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array{amount: string, currency: string, description: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
	 */
	public function forBinding(array $binding): ?array {
		$caseType = $this->caseTypes->readCaseType(
			register: (string)($binding['typeRegister'] ?? ''),
			schema: (string)($binding['typeSchema'] ?? ''),
			id: (string)($binding['typeId'] ?? '')
		);
		if ($caseType === null) {
			return null;
		}

		return $this->normalise(declared: ($caseType['portalFee'] ?? null));
	}//end forBinding()

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
