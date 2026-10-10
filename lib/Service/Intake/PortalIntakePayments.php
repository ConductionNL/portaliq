<?php

/**
 * Portaliq Intake Payments (intake-pay-on-submit)
 *
 * Takes the payment for a submission and reads the state of it.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Forwards the case app's pay action for a submission and reports the state of the payment.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
 */
class PortalIntakePayments {
	/**
	 * Constructor.
	 *
	 * @param PortalIntakeQueue $queue Records and reports on submissions.
	 * @param PortalFormBindingResolver $bindings Resolves the binding.
	 * @param PortalFee|null $fees Checks a fee and the address a resident may be sent to pay at.
	 * @param PortalPaymentIntents|null $intents Reads the state of a payment.
	 * @param PortalContributionRegistry|null $registry Finds the case app's pay action in the subject's own manifest.
	 * @param PortalActionForwarder|null $forwarder Forwards the pay action with a server-built body.
	 * @param PortalDeepLinkBuilder|null $deepLinks Builds the address the resident returns to.
	 */
	public function __construct(
		private readonly PortalIntakeQueue $queue,
		private readonly PortalFormBindingResolver $bindings,
		private readonly ?PortalFee $fees = null,
		private readonly ?PortalPaymentIntents $intents = null,
		private readonly ?PortalContributionRegistry $registry = null,
		private readonly ?PortalActionForwarder $forwarder = null,
		private readonly ?PortalDeepLinkBuilder $deepLinks = null,
	) {
	}//end __construct()

	/**
	 * Take the payment for a submission of the signed-in subject.
	 *
	 * @param array<string, mixed>      $subject   The signed-in subject.
	 * @param array<string, mixed>|null $site      The resolved portal, or null.
	 * @param string                    $reference The submission's reference.
	 *
	 * @return JSONResponse `{checkoutUrl}` or the refusal.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function start(array $subject, ?array $site, string $reference): JSONResponse {
		if ($site === null || $this->fees === null || $this->forwarder === null || $this->registry === null) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$slug       = (string)($site['slug'] ?? '');
		$submission = $this->ownSubmission(subject: $subject, slug: $slug, reference: $reference);
		if ($submission === null) {
			return new JSONResponse(['error' => 'reference_not_found'], Http::STATUS_NOT_FOUND);
		}

		$fee = $this->feeToPay(fees: $this->fees, submission: $submission, slug: $slug, reference: $reference);
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
			context: ['action' => $action, 'subject' => $subject, 'site' => $site, 'submission' => $submission, 'fee' => $fee, 'reference' => $reference]
		);
	}//end start()

	/**
	 * The submission, when it is the subject's own.
	 *
	 * @param array<string, mixed> $subject   The signed-in subject.
	 * @param string               $slug      The portal's slug.
	 * @param string               $reference The submission's reference.
	 *
	 * @return array<string, mixed>|null The submission, or null when it is unknown or someone else's.
	 */
	private function ownSubmission(array $subject, string $slug, string $reference): ?array {
		$submission = $this->findSubmission(reference: $reference, portal: $slug);
		$owner      = (string)($submission['subjectRef'] ?? '');
		if ($submission === null || $owner === '' || $owner !== (string)($subject['subjectRef'] ?? '')) {
			return null;
		}

		return $submission;
	}//end ownSubmission()

	/**
	 * The fee the submission's case type declares, or null when there is none or it is paid.
	 *
	 * @param PortalFee            $fees       Reads the fee of a binding.
	 * @param array<string, mixed> $submission The submission.
	 * @param string               $slug       The portal's slug.
	 * @param string               $reference  The submission's reference.
	 *
	 * @return array<string, mixed>|null The fee to pay.
	 */
	private function feeToPay(PortalFee $fees, array $submission, string $slug, string $reference): ?array {
		$binding = $this->bindings->bindingFor(portal: $slug, route: (string)($submission['route'] ?? ''));
		$fee     = null;
		if ($binding !== null) {
			$fee = $fees->forBinding(binding: $binding);
		}

		$paid = (($this->paymentOf(reference: $reference, portal: $slug)['state'] ?? '') === 'paid');
		if ($paid === true) {
			return null;
		}

		return $fee;
	}//end feeToPay()

	/**
	 * Forward the pay action with a body the portal built, and answer the checkout address.
	 *
	 * @param PortalActionForwarder $forwarder Forwards the pay action.
	 * @param PortalFee             $fees      Checks the checkout address.
	 * @param array<string, mixed>  $context   The action, subject, site, submission, fee and reference.
	 *
	 * @return JSONResponse `{checkoutUrl}` or a 502.
	 */
	private function forward(PortalActionForwarder $forwarder, PortalFee $fees, array $context): JSONResponse {
		$fee        = $context['fee'];
		$submission = $context['submission'];
		$response   = $forwarder->forward(
			action: $context['action'],
			subject: $context['subject'],
			whitelisted: [
				'reference' => $context['reference'],
				'amount' => $fee['amount'],
				'currency' => $fee['currency'],
				'description' => $fee['description'],
				'returnUrl' => $this->returnUrl(
					slug: (string)($context['site']['slug'] ?? ''),
					route: (string)($submission['route'] ?? ''),
					reference: $context['reference']
				),
			]
		);
		if ($response === null || $response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$answer   = $forwarder->decodeBody($response);
		$checkout = ($answer['checkoutUrl'] ?? null);
		$intentId = trim((string)($answer['paymentIntentId'] ?? ''));
		if ($intentId === '' || $fees->checkoutAllowed(url: $checkout, hosts: (array)($context['site']['paymentHosts'] ?? [])) === false) {
			return new JSONResponse(['error' => 'payment_unavailable'], Http::STATUS_BAD_GATEWAY);
		}

		$this->queue->markPaymentIntent(submission: $submission, paymentIntentId: $intentId);

		return new JSONResponse(['checkoutUrl' => $checkout]);
	}//end forward()

	/**
	 * The payment state of a submission, or null when none was started.
	 *
	 * @param string $reference The submission's reference.
	 * @param string $portal    The portal's slug.
	 *
	 * @return array{state: string}|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function paymentOf(string $reference, string $portal): ?array {
		$submission = $this->findSubmission(reference: $reference, portal: $portal);
		$intentId   = trim((string)($submission['paymentIntentId'] ?? ''));
		if ($intentId === '' || $this->intents === null || $this->fees === null) {
			return null;
		}

		$status = $this->intents->status(id: $intentId);
		if ($status === null) {
			return ['state' => 'unknown'];
		}

		return ['state' => $this->fees->stateOf(status: $status)];
	}//end paymentOf()

	/**
	 * A submission by its reference, or null when it is not there or cannot be read.
	 *
	 * @param string $reference The submission's reference.
	 * @param string $portal    The portal's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	private function findSubmission(string $reference, string $portal): ?array {
		try {
			return $this->queue->find(reference: $reference, portal: $portal);
		} catch (Throwable) {
			return null;
		}
	}//end findSubmission()

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
