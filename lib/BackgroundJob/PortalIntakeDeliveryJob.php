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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
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
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalIntakeQueue $queue,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
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
				data: (array)($submission['answers'] ?? [])
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
}//end class
