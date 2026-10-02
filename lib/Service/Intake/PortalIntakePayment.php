<?php

/**
 * Portaliq Portal Intake Payment
 *
 * Starts the payment of a request's fee, and reads its state. Portaliq
 * creates no payment and stores no card or bank data: it forwards the fee
 * the case type declares to the case app's own pay action, which takes the
 * payment through integriq, and it reads the payment record integriq keeps.
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

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;

/**
 * Starts and reads the payment of a request's fee.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one collaborator per step
 * of design D3: the submission, the binding, the fee, the manifest, the forward.
 * @SuppressWarnings(PHPMD.StaticAccess)            -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
 */
class PortalIntakePayment {

	/**
	 * Where integriq keeps a payment. Read from integriq's register file at
	 * HEAD; intake-pay-on-submit T01 confirms it on a live instance.
	 *
	 * @var string
	 */
	public const INTENT_REGISTER = 'integriq';

	/**
	 * The schema of integriq's payment record.
	 *
	 * @var string
	 */
	public const INTENT_SCHEMA = 'payment_intent';

	/**
	 * Paid, as far as the resident is concerned.
	 *
	 * @var string
	 */
	public const STATE_PAID = 'paid';

	/**
	 * Started, not paid yet.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * Ended without a payment; the resident may pay again.
	 *
	 * @var string
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * The record is missing or says something the portal does not show.
	 *
	 * @var string
	 */
	public const STATE_UNKNOWN = 'unknown';

	/**
	 * Integriq's paymentStatus values, by the state the resident reads.
	 *
	 * @var array<string, string>
	 */
	private const STATES = [
		'paid' => self::STATE_PAID,
		'authorized' => self::STATE_PAID,
		'open' => self::STATE_OPEN,
		'pending' => self::STATE_OPEN,
		'failed' => self::STATE_FAILED,
		'canceled' => self::STATE_FAILED,
		'expired' => self::STATE_FAILED,
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $records Reads integriq's payment record in system context.
	 * @param PortalIntakeFee $fees The fee the case type declares.
	 * @param PortalIntakeQueue $queue The submission, and where its payment is noted.
	 * @param PortalFormBindingResolver $bindings The binding a submission came through.
	 * @param PortalContributionRegistry $registry The resident's own aggregated manifest.
	 * @param PortalActionForwarder $forwarder The signed forward to the case app.
	 * @param PortalDeepLinkBuilder $links The page the resident comes back to.
	 */
	public function __construct(
		private readonly CaseTypeReader $records,
		private readonly PortalIntakeFee $fees,
		private readonly PortalIntakeQueue $queue,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalActionForwarder $forwarder,
		private readonly PortalDeepLinkBuilder $links,
	) {
	}//end __construct()

	/**
	 * Start paying the fee of the resident's own request (design D3).
	 *
	 * Every refusal forwards nothing. The body the case app receives is built
	 * here, from the case type's declaration: an amount in the browser's
	 * request never reaches it.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $subject The signed-in resident.
	 * @param string $reference The request's reference.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-portal-redirects-only-to-a-declared-payment-host-req-ips-004
	 */
	public function pay(array $site, array $subject, string $reference): array {
		$portal = (string)($site['slug'] ?? '');
		$submission = $this->queue->ownSubmission(
			reference: $reference,
			portal: $portal,
			subjectRef: (string)($subject['subjectRef'] ?? '')
		);
		if ($submission === null) {
			return $this->refusal(status: Http::STATUS_NOT_FOUND, error: 'reference_not_found');
		}

		$binding = $this->bindings->bindingFor(portal: $portal, route: (string)($submission['route'] ?? ''));
		$fee = $this->fees->feeFor(binding: ($binding ?? []));
		if ($fee === null) {
			return $this->refusal(status: Http::STATUS_CONFLICT, error: 'no_fee');
		}

		$intentId = (string)($submission['paymentIntentId'] ?? '');
		if ($intentId !== '' && $this->stateOf(paymentIntentId: $intentId) === self::STATE_PAID) {
			return $this->refusal(status: Http::STATUS_CONFLICT, error: 'already_paid');
		}

		$action = $this->payAction(subject: $subject, fee: $fee);
		if ($action === null) {
			return $this->refusal(status: Http::STATUS_FORBIDDEN, error: 'pay_action_not_offered');
		}

		$checkout = $this->startPayment(action: $action, subject: $subject, fee: $fee, reference: $reference, portal: $portal);
		if ($checkout === null) {
			return $this->refusal(status: Http::STATUS_BAD_GATEWAY, error: 'payment_unavailable');
		}

		// Noted before the host check: the payment exists at the provider
		// either way, and the reference page reads its state from it.
		$this->queue->recordPaymentIntent(submission: $submission, paymentIntentId: $checkout['paymentIntentId']);

		if ($this->isDeclaredHost(site: $site, url: $checkout['checkoutUrl']) === false) {
			return $this->refusal(status: Http::STATUS_BAD_GATEWAY, error: 'payment_unavailable');
		}

		return ['status' => Http::STATUS_OK, 'body' => ['checkoutUrl' => $checkout['checkoutUrl']]];
	}//end pay()

	/**
	 * What the resident reads about a payment, from integriq's record.
	 *
	 * @param string $paymentIntentId The payment record's id.
	 *
	 * @return string One of the STATE_ constants.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
	 */
	public function stateOf(string $paymentIntentId): string {
		$intent = $this->records->readCaseType(
			register: self::INTENT_REGISTER,
			schema: self::INTENT_SCHEMA,
			id: $paymentIntentId
		);

		return (self::STATES[(string)($intent['paymentStatus'] ?? '')] ?? self::STATE_UNKNOWN);
	}//end stateOf()

	/**
	 * The declared pay action, when the resident's own manifest offers it and
	 * it may be forwarded.
	 *
	 * @param array<string, mixed> $subject The resident.
	 * @param array<string, string> $fee The fee declaration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function payAction(array $subject, array $fee): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			if (($contribution['app'] ?? '') !== $fee['payApp']) {
				continue;
			}

			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === true && ($action['id'] ?? '') === $fee['payAction']) {
					return $this->forwardableAction(action: $action, subject: $subject);
				}
			}
		}

		return null;
	}//end payAction()

	/**
	 * The action when it is an instance-local endpoint the resident's session
	 * is strong enough for, else null.
	 *
	 * @param array<string, mixed> $action The matched action.
	 * @param array<string, mixed> $subject The resident.
	 *
	 * @return array<string, mixed>|null
	 */
	private function forwardableAction(array $action, array $subject): ?array {
		if ($this->forwarder->isForwardable(action: $action) === false) {
			return null;
		}

		if (PortalSessionService::trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: ($action['minTrust'] ?? null)) === false) {
			return null;
		}

		return $action;
	}//end forwardableAction()

	/**
	 * Forward the server-built body and read the case app's answer.
	 *
	 * @param array<string, mixed> $action The pay action.
	 * @param array<string, mixed> $subject The resident.
	 * @param array<string, string> $fee The fee declaration.
	 * @param string $reference The request's reference.
	 * @param string $portal The portal's slug.
	 *
	 * @return array{checkoutUrl: string, paymentIntentId: string}|null Null on
	 *         a transport failure, a non-2xx answer, or one without both values.
	 */
	private function startPayment(array $action, array $subject, array $fee, string $reference, string $portal): ?array {
		$response = $this->forwarder->forward(
			action: $action,
			subject: $subject,
			whitelisted: [
				'reference' => $reference,
				'amount' => $fee['amount'],
				'currency' => $fee['currency'],
				'description' => $fee['description'],
				'returnUrl' => $this->links->forSite(portalSlug: $portal).'?reference='.rawurlencode($reference),
			]
		);
		if ($response === null || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
			return null;
		}

		$body = $this->forwarder->decodeBody(response: $response);
		$checkoutUrl = ($body['checkoutUrl'] ?? null);
		$intentId = ($body['paymentIntentId'] ?? null);
		if (is_string($checkoutUrl) === false || $checkoutUrl === '' || is_string($intentId) === false || $intentId === '') {
			return null;
		}

		return ['checkoutUrl' => $checkoutUrl, 'paymentIntentId' => $intentId];
	}//end startPayment()

	/**
	 * Whether a checkout address is https on a host the portal declares.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param string $url The checkout address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-portal-redirects-only-to-a-declared-payment-host-req-ips-004
	 */
	private function isDeclaredHost(array $site, string $url): bool {
		$parts = parse_url($url);
		if (is_array($parts) === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		$hosts = array_map(static fn (mixed $host): string => strtolower((string)$host), (array)($site['paymentHosts'] ?? []));

		return in_array(strtolower((string)($parts['host'] ?? '')), $hosts, true);
	}//end isDeclaredHost()

	/**
	 * A refusal as the controller answers it.
	 *
	 * @param int $status The HTTP status.
	 * @param string $error The error code the page translates.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function refusal(int $status, string $error): array {
		return ['status' => $status, 'body' => ['error' => $error]];
	}//end refusal()
}//end class
