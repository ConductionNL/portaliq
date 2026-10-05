<?php

/**
 * Portaliq Portal Woo Request Delivery
 *
 * Hands a Woo request a citizen sent through a portal form to opencatalogi,
 * which mints the request's reference and arms its statutory term. A plain
 * create in OpenRegister does neither, so a Woo-request form delivered that way
 * looked received while no legal deadline ran.
 *
 * opencatalogi is reached the way the portal already reaches it: through the
 * contribution provider it ships (`receiveWooRequest()`), located by
 * PortalProviderLocator. portaliq keeps working without opencatalogi; the
 * outcome is then `unavailable` and the submission is marked failed.
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
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers one Woo request to opencatalogi and reports what became of it.
 *
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
 */
class PortalWooRequestDelivery {
	/**
	 * The binding's `deliverTo` that routes a form here.
	 */
	public const DELIVER_TO = 'wooRequest';

	/**
	 * The request is stored and its term runs.
	 */
	public const OUTCOME_ARMED = 'armed';

	/**
	 * The request is stored but no term runs.
	 */
	public const OUTCOME_NOT_ARMED = 'not-armed';

	/**
	 * opencatalogi refused the request; nothing is stored.
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * opencatalogi, or its intake, cannot be reached.
	 */
	public const OUTCOME_UNAVAILABLE = 'unavailable';

	/**
	 * The app that owns Woo requests.
	 */
	private const APP = 'opencatalogi';

	/**
	 * The method opencatalogi's provider receives a request with.
	 */
	private const METHOD = 'receiveWooRequest';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Says whether opencatalogi is installed.
	 * @param PortalProviderLocator $locator Finds opencatalogi's provider.
	 * @param LoggerInterface $logger Records a call that failed.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Deliver one request.
	 *
	 * @param array<string, mixed> $answers The citizen's answers.
	 * @param string $submittedAt When the citizen sent it; the term counts from then.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *         `armed` only with a due date. Anything else carries no date.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function deliver(array $answers, string $submittedAt): array {
		if ($this->appManager->isInstalled(self::APP) === false) {
			return $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, message: 'opencatalogi is not installed, so no Woo request can be received.');
		}

		$provider = $this->locator->locate(appId: self::APP);
		if ($provider === null || method_exists($provider, self::METHOD) === false) {
			return $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, message: 'This opencatalogi version cannot receive a Woo request from the portal.');
		}

		try {
			$answer = $provider->{self::METHOD}($answers, $submittedAt);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: Woo request delivery failed', ['exception' => $e]);
			return $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, message: 'opencatalogi could not receive the Woo request.');
		}

		return $this->read(answer: $answer);
	}//end deliver()

	/**
	 * Read opencatalogi's answer, trusting a due date only on `armed`.
	 *
	 * @param mixed $answer What the provider returned.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	private function read(mixed $answer): array {
		if (is_array($answer) === false) {
			return $this->outcome(outcome: self::OUTCOME_UNAVAILABLE, message: 'opencatalogi gave no answer for the Woo request.');
		}

		$outcome = (string)($answer['outcome'] ?? '');
		$known = [self::OUTCOME_ARMED, self::OUTCOME_NOT_ARMED, self::OUTCOME_REFUSED, self::OUTCOME_UNAVAILABLE];
		if (in_array($outcome, $known, true) === false) {
			$outcome = self::OUTCOME_UNAVAILABLE;
		}

		$dueAt = (string)($answer['dueAt'] ?? '');
		if ($outcome === self::OUTCOME_ARMED && $dueAt === '') {
			// An armed term without a date is not a term the citizen can be told about.
			$outcome = self::OUTCOME_NOT_ARMED;
		}

		if ($outcome !== self::OUTCOME_ARMED) {
			$dueAt = '';
		}

		return $this->outcome(
			outcome: $outcome,
			requestId: (string)($answer['requestId'] ?? ''),
			reference: (string)($answer['reference'] ?? ''),
			dueAt: $dueAt,
			message: (string)($answer['message'] ?? '')
		);
	}//end read()

	/**
	 * One outcome, every key present.
	 *
	 * @param string $outcome The outcome.
	 * @param string $requestId opencatalogi's request id, or ''.
	 * @param string $reference The minted Woo reference, or ''.
	 * @param string $dueAt The due date, only when armed.
	 * @param string $message Why, when it is not armed.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	private function outcome(string $outcome, string $requestId = '', string $reference = '', string $dueAt = '', string $message = ''): array {
		return [
			'outcome' => $outcome,
			'requestId' => $requestId,
			'reference' => $reference,
			'dueAt' => $dueAt,
			'message' => $message,
		];
	}//end outcome()
}//end class
