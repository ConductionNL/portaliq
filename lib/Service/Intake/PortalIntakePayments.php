<?php

/**
 * Portaliq Intake Payments (intake-pay-on-submit)
 *
 * Takes the payment for a request and reads its state from the object the
 * request created (decision 181: the case first, payment status open).
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
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Forwards the case app's pay action for a created object and reports the state of the payment.
 *
 * The object, not a portal record, holds the payment: the case app's pay
 * action keeps the payment intent, and the binding's
 * `destination.paymentField` names the property its state is read from.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#3.3
 */
class PortalIntakePayments {
	/**
	 * Constructor.
	 *
	 * @param DestinationLookup|null          $lookup    Finds the object behind a reference.
	 * @param PortalFee|null                  $fees      Checks a fee and the address a resident may be sent to pay at.
	 * @param PortalContributionRegistry|null $registry  Finds the case app's pay action in the subject's own manifest.
	 * @param PortalActionForwarder|null      $forwarder Forwards the pay action with a server-built body.
	 * @param PortalDeepLinkBuilder|null      $deepLinks Builds the address the resident returns to.
	 */
	public function __construct(
		private readonly ?DestinationLookup $lookup = null,
		private readonly ?PortalFee $fees = null,
		private readonly ?PortalContributionRegistry $registry = null,
		private readonly ?PortalActionForwarder $forwarder = null,
		private readonly ?PortalDeepLinkBuilder $deepLinks = null,
	) {
	}//end __construct()

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
	public function start(array $subject, ?array $site, string $reference): JSONResponse {
		if ($site === null || $this->fees === null || $this->forwarder === null || $this->lookup === null || $this->registry === null) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$found = $this->ownObject(lookup: $this->lookup, subject: $subject, slug: (string)($site['slug'] ?? ''), reference: $reference);
		if ($found === null) {
			return new JSONResponse(['error' => 'reference_not_found'], Http::STATUS_NOT_FOUND);
		}

		$fee = $this->feeToPay(fees: $this->fees, found: $found);
		if ($fee === null) {
			return new JSONResponse(['error' => 'nothing_to_pay'], Http::STATUS_CONFLICT);
		}

		$action = $this->payAction(subject: $subject, actionId: $fee['payAction']);
		if ($action === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->forward(
			forwarder: $this->forwarder,
			fees: $this->fees,
			context: ['action' => $action, 'subject' => $subject, 'site' => $site, 'binding' => $found['binding'], 'fee' => $fee, 'reference' => $reference]
		);
	}//end start()

	/**
	 * The fee the object's case type declares, or null when there is none or it is paid.
	 *
	 * @param PortalFee                                                                                       $fees  Reads the fee of a binding.
	 * @param array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>} $found The object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function feeToPay(PortalFee $fees, array $found): ?array {
		if (($this->paymentOf(found: $found)['state'] ?? '') === 'paid') {
			return null;
		}

		return $fees->forBinding(binding: $found['binding']);
	}//end feeToPay()

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
	public function statusWithPayment(string $reference, string $portal, string $subjectRef = ''): ?array {
		$found = $this->lookup?->find(portal: $portal, reference: $reference, subjectRef: $subjectRef);
		if ($found === null || $this->lookup === null) {
			return null;
		}

		$status  = $this->lookup->statusOf(found: $found, reference: $reference);
		$payment = $this->paymentOf(found: $found);
		if ($payment !== null) {
			$status['payment'] = $payment;
		}

		return $status;
	}//end statusWithPayment()

	/**
	 * The payment state of a found object, or null when its binding names no
	 * payment field or the object holds no state yet.
	 *
	 * @param array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>} $found What DestinationLookup found.
	 *
	 * @return array{state: string}|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#3.3
	 */
	public function paymentOf(array $found): ?array {
		$field = trim((string)($found['binding']['destination']['paymentField'] ?? ''));
		$value = $found['object'][$field] ?? null;
		if ($field === '' || is_string($value) === false || trim($value) === '' || $this->fees === null) {
			return null;
		}

		return ['state' => $this->fees->stateOf(status: $value)];
	}//end paymentOf()

	/**
	 * The object behind a reference, when the binding stamps the subject on it and it is this subject's.
	 *
	 * @param DestinationLookup    $lookup    Finds the object.
	 * @param array<string, mixed> $subject   The signed-in subject.
	 * @param string               $slug      The portal's slug.
	 * @param string               $reference The request's reference.
	 *
	 * @return array{object: array<string, mixed>, binding: array<string, mixed>, schema: array<string, mixed>}|null
	 */
	private function ownObject(DestinationLookup $lookup, array $subject, string $slug, string $reference): ?array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$found      = $lookup->find(portal: $slug, reference: $reference, subjectRef: $subjectRef);
		$field      = trim((string)($found['binding']['destination']['subjectField'] ?? ''));
		if ($found === null || $subjectRef === '' || $field === '' || (string)($found['object'][$field] ?? '') !== $subjectRef) {
			return null;
		}

		return $found;
	}//end ownObject()

	/**
	 * Forward the pay action with a body the portal built, and answer the checkout address.
	 *
	 * The case app's pay action keeps the payment intent on its own object;
	 * the portal stores nothing.
	 *
	 * @param PortalActionForwarder $forwarder Forwards the pay action.
	 * @param PortalFee             $fees      Checks the checkout address.
	 * @param array<string, mixed>  $context   The action, subject, site, binding, fee and reference.
	 *
	 * @return JSONResponse `{checkoutUrl}` or a 502.
	 */
	private function forward(PortalActionForwarder $forwarder, PortalFee $fees, array $context): JSONResponse {
		$fee      = $context['fee'];
		$response = $forwarder->forward(
			action: $context['action'],
			subject: $context['subject'],
			whitelisted: [
				'reference' => $context['reference'],
				'amount' => $fee['amount'],
				'currency' => $fee['currency'],
				'description' => $fee['description'],
				'returnUrl' => $this->returnUrl(
					slug: (string)($context['site']['slug'] ?? ''),
					route: (string)($context['binding']['route'] ?? ''),
					reference: $context['reference']
				),
			]
		);
		if ($response === null || $response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$checkout = ($forwarder->decodeBody($response)['checkoutUrl'] ?? null);
		if ($fees->checkoutAllowed(url: $checkout, hosts: (array)($context['site']['paymentHosts'] ?? [])) === false) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['checkoutUrl' => $checkout]);
	}//end forward()

	/**
	 * The case app's pay action in the subject's own manifest, or null.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $actionId The `payAction` the case type declares.
	 *
	 * @return array<string, mixed>|null
	 */
	private function payAction(array $subject, string $actionId): ?array {
		$aggregate = $this->registry?->aggregateFor($subject);
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === true && ($action['id'] ?? '') === $actionId && $this->forwarder?->isForwardable($action) === true) {
					return $action;
				}
			}
		}

		return null;
	}//end payAction()

	/**
	 * The page the resident comes back to from the payment page.
	 *
	 * @param string $slug      The portal.
	 * @param string $route     The form's route.
	 * @param string $reference The submission's reference.
	 *
	 * @return string
	 */
	private function returnUrl(string $slug, string $route, string $reference): string {
		$base = '';
		if ($this->deepLinks !== null) {
			$base = $this->deepLinks->forSite(portalSlug: $slug);
		}

		return $base . '&route=' . rawurlencode('/' . ltrim($route, '/')) . '&reference=' . rawurlencode($reference);
	}//end returnUrl()
}//end class
