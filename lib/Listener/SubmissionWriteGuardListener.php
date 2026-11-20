<?php

/**
 * Portaliq Submission Write Guard Listener
 *
 * Keeps a proof-of-receipt record's evidence as it was written. The
 * Submissions list offers Edit and Link a form, and register-again marks a
 * record delivered, so `portalSubmission` is updatable; this guard refuses an
 * update that changes any proof field (submission-row-actions).
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stops a submission update that changes its evidence.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
 */
class SubmissionWriteGuardListener implements IEventListener {

	use SchemaWriteGuard;

	/**
	 * The fields that are the proof of receipt and never change after the write.
	 *
	 * @var array<int, string>
	 */
	public const PROOF_FIELDS = ['subjectRef', 'organisation', 'appId', 'actionId', 'actionTitle', 'payloadCopy', 'submittedAt'];

	/**
	 * The slug of the schema this guard checks.
	 */
	private const GUARDED_SCHEMA = 'portalSubmission';

	/**
	 * How this guard names itself in the log.
	 */
	private const GUARD_NAME = 'submission write guard';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Records a schema lookup that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Refuse an update that changes a proof field. A create is the write that
	 * sets the evidence, so it passes.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
	 */
	protected function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$previous = $event->getOldObject();
		if ($previous === null || $this->guardsSchema(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$changed = self::changedProofFields(before: (array)($previous->getObject() ?? []), after: (array)($entity->getObject() ?? []));
		if ($changed !== []) {
			$event->stopPropagation();
			$event->setErrors(['message' => $this->l10n->t('The proof of a submission cannot be changed.'), 'fields' => $changed]);
		}
	}//end check()


	/**
	 * The proof fields whose value differs between two versions of a record.
	 *
	 * A field that is removed counts as changed. Object key order does not.
	 *
	 * @param array<string, mixed> $before The stored record.
	 * @param array<string, mixed> $after  The record being written.
	 *
	 * @return array<int, string> The changed proof fields.
	 *
	 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
	 */
	public static function changedProofFields(array $before, array $after): array {
		$changed = [];
		foreach (self::PROOF_FIELDS as $field) {
			if (self::canonical(value: ($before[$field] ?? null)) !== self::canonical(value: ($after[$field] ?? null))) {
				$changed[] = $field;
			}
		}

		return $changed;
	}//end changedProofFields()


	/**
	 * A value with every object's keys sorted, so two equal records compare equal.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function canonical(mixed $value): mixed {
		if (is_object($value) === true) {
			$value = (array)$value;
		}

		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(static fn (mixed $item): mixed => self::canonical(value: $item), $value);
	}//end canonical()
}//end class
