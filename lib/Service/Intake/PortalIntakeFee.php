<?php

/**
 * Portaliq Portal Intake Fee
 *
 * The fee a case type declares for a request.
 * Portaliq creates no payment and stores no card or bank data: the case app
 * takes the payment through integriq, and portaliq reads the fee it declared
 * and the payment record integriq keeps.
 *
 * @category Service
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
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\CaseTypeReader;

/**
 * Reads the fee a case type declares.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
 */
class PortalIntakeFee {

	/**
	 * The property on the case app's case type that declares the fee.
	 *
	 * @var string
	 */
	public const FEE_PROPERTY = 'portalFee';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $records Reads the case app's case type.
	 */
	public function __construct(
		private readonly CaseTypeReader $records,
	) {
	}//end __construct()

	/**
	 * The fee the binding's case type declares, or null when it asks none.
	 *
	 * A declaration that is not complete is no fee: an amount nobody can read
	 * must not become a payment of some other amount.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return array{amount: string, currency: string, description: string, payApp: string, payAction: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
	 */
	public function feeFor(array $binding): ?array {
		$caseType = $this->records->readCaseType(
			register: (string)($binding['typeRegister'] ?? ''),
			schema: (string)($binding['typeSchema'] ?? ''),
			id: (string)($binding['typeId'] ?? '')
		);
		$fee = ($caseType[self::FEE_PROPERTY] ?? null);
		if (is_array($fee) === false) {
			return null;
		}

		$amount = $this->amountOf(declared: ($fee['amount'] ?? null));
		$currency = (string)($fee['currency'] ?? 'EUR');
		$payApp = (string)($fee['payApp'] ?? '');
		$payAction = (string)($fee['payAction'] ?? '');
		if ($amount === null || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $payApp === '' || $payAction === '') {
			return null;
		}

		return [
			'amount' => $amount,
			'currency' => $currency,
			'description' => (string)($fee['description'] ?? ''),
			'payApp' => $payApp,
			'payAction' => $payAction,
		];
	}//end feeFor()

	/**
	 * The fee the resident is told about, without the action that takes it,
	 * or null when the request is free.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return array{amount: string, currency: string, description: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
	 */
	public function publicFeeFor(array $binding): ?array {
		$fee = $this->feeFor(binding: $binding);
		if ($fee === null) {
			return null;
		}

		return ['amount' => $fee['amount'], 'currency' => $fee['currency'], 'description' => $fee['description']];
	}//end publicFeeFor()

	/**
	 * A declared amount as a two-decimal text, or null when it is none.
	 *
	 * @param mixed $declared The declared amount: "12.50", "12" or 12.5.
	 *
	 * @return string|null
	 */
	private function amountOf(mixed $declared): ?string {
		if (is_int($declared) === true || is_float($declared) === true) {
			$declared = number_format((float)$declared, 2, '.', '');
		}

		if (is_string($declared) === false || preg_match('/^\d{1,7}(\.\d{1,2})?$/', $declared) !== 1) {
			return null;
		}

		if ((float)$declared <= 0.0) {
			return null;
		}

		return number_format((float)$declared, 2, '.', '');
	}//end amountOf()
}//end class
