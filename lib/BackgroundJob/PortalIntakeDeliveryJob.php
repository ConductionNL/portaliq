<?php

/**
 * Portaliq Portal Intake Delivery Job
 *
 * Turns queued intake submissions into cases, after the citizen has already
 * been given their reference. Everything about this job is arranged so that a
 * failure is visible: a create that does not land marks the submission
 * `failed` with its reason, so the reference page can say the request has not
 * been registered yet and an administrator can see why.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A form bound with `deliverTo: wooRequest` does not become a case here. It
 * goes to opencatalogi's own Woo intake, which mints the reference and arms
 * the statutory term; a plain create would arm nothing.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\Intake\PortalWooRequestDelivery;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the case behind each queued submission.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalIntakeDeliveryJob extends TimedJob {
	/**
	 * How often the queue is drained.
	 */
	private const INTERVAL = 60;

	/**
	 * How many submissions one run takes.
	 */
	private const BATCH = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The scheduler's clock.
	 * @param PortalIntakeQueue $queue The submissions waiting for a case.
	 * @param PortalFormBindingResolver $bindings Says where the case goes.
	 * @param PortalObjectWriter $writer Creates the case.
	 * @param LoggerInterface $logger Records a failure's cause.
	 * @param PortalWooRequestDelivery $wooRequests Hands a Woo request to opencatalogi.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalIntakeQueue $queue,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
		private readonly PortalWooRequestDelivery $wooRequests,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Drain one batch.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		foreach ($this->queue->queued(limit: self::BATCH) as $submission) {
			$this->deliver(submission: $submission);
		}
	}//end run()

	/**
	 * Create the case behind one submission, or record why not.
	 *
	 * @param array<string, mixed> $submission The queued submission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function deliver(array $submission): void {
		$binding = $this->bindings->bindingFor(
			portal: (string)($submission['portal'] ?? ''),
			route: (string)($submission['route'] ?? '')
		);
		if ($binding === null) {
			$this->queue->markFailed(submission: $submission, reason: 'The form this request came from is no longer published.');
			return;
		}

		if ((string)($binding['deliverTo'] ?? '') === PortalWooRequestDelivery::DELIVER_TO) {
			$this->deliverWooRequest(submission: $submission);
			return;
		}

		$register = (string)($binding['caseRegister'] ?? '');
		$schema = (string)($binding['caseSchema'] ?? '');
		if ($register === '' || $schema === '') {
			$this->queue->markFailed(submission: $submission, reason: 'The form does not say which register the case belongs in.');
			return;
		}

		try {
			$created = $this->writer->createAnonymousObject(
				register: $register,
				schema: $schema,
				data: $this->payloadOf(submission: $submission)
			);
		} catch (Throwable $exception) {
			$this->logger->error('Portal intake delivery failed', ['exception' => $exception]);
			$this->queue->markFailed(submission: $submission, reason: 'The case could not be created.');
			return;
		}

		if ($created === null) {
			$this->queue->markFailed(submission: $submission, reason: 'The case could not be created.');
			return;
		}

		$this->queue->markRegistered(
			submission: $submission,
			caseId: (string)($created['id'] ?? $created['uuid'] ?? '')
		);
	}//end deliver()

	/**
	 * Hand a Woo request to opencatalogi, and register it only when its term runs.
	 *
	 * A request whose term did not start is marked failed, so the reference
	 * page never quotes a deadline nobody armed. opencatalogi did store it, so
	 * the reason names its reference for the administrator who follows up.
	 *
	 * @param array<string, mixed> $submission The queued submission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	private function deliverWooRequest(array $submission): void {
		$result = $this->wooRequests->deliver(
			answers: (array)($submission['answers'] ?? []),
			submittedAt: (string)($submission['submittedAt'] ?? '')
		);

		if ($result['outcome'] === PortalWooRequestDelivery::OUTCOME_ARMED) {
			$this->queue->markRegistered(
				submission: $submission,
				caseId: $result['requestId'],
				externalReference: $result['reference'],
				dueAt: $result['dueAt']
			);
			return;
		}

		if ($result['outcome'] === PortalWooRequestDelivery::OUTCOME_NOT_ARMED) {
			$this->queue->markFailed(
				submission: $submission,
				reason: 'Woo request ' . $result['reference'] . ' was stored, but its statutory term did not start: ' . $result['message']
			);
			return;
		}

		$this->queue->markFailed(submission: $submission, reason: 'The Woo request could not be received: ' . $result['message']);
	}//end deliverWooRequest()

	/**
	 * What the case is created with: the answers, and when the portal worked
	 * some out or a decision filled them, `fieldMeta` marking each `computed`
	 * so a case app can tell them from typed answers.
	 *
	 * @param array<string, mixed> $submission The queued submission.
	 *
	 * @return array<string, mixed> The case data.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t07
	 */
	public function payloadOf(array $submission): array {
		$data     = (array)($submission['answers'] ?? []);
		$computed = array_filter((array)($submission['computed'] ?? []), 'is_string');
		if ($computed === []) {
			return $data;
		}

		$meta = [];
		foreach ($computed as $name) {
			$meta[$name] = ['computed' => true];
		}

		$data['fieldMeta'] = $meta;

		return $data;
	}//end payloadOf()
}//end class
