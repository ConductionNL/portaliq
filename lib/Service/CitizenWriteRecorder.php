<?php

/**
 * Portaliq Citizen Write Recorder
 *
 * Every citizen write leaves three traces, and this class is the one place
 * that leaves all three, so none of them can be forgotten on a new act:
 *
 * 1. a record ON the case, naming the identity, the mandate, the act, the
 *    fields and both the old and the new answer, so a case worker opening the
 *    case sees what changed and who changed it (REQ-CWOC-002);
 * 2. a row in the append-only portal audit trail, the same fact record every
 *    other portal mutation writes;
 * 3. the `portal.write.client` event, raised once, so a rule can fire on "de
 *    indiener heeft gereageerd" (REQ-CWOC-005).
 *
 * The record is appended, never replaced: an amendment that overwrote the
 * previous one would lose the earlier answer the history scenario asks for.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Builds the record on the case and announces the write.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteRecorder {
	/**
	 * Constructor.
	 *
	 * @param AuditTrailService $auditor The append-only portal audit trail.
	 * @param IEventDispatcher $dispatcher Raises the citizen write event.
	 */
	public function __construct(
		private readonly AuditTrailService $auditor,
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * The identity a write is attributed to.
	 *
	 * @param array<string, mixed> $subject The resolved portal session.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function identity(array $subject): array {
		return [
			'subjectRef' => (string)($subject['subjectRef'] ?? ''),
			'audience' => (string)($subject['audience'] ?? ''),
			'trust' => (string)($subject['trust'] ?? ''),
			'jti' => (string)($subject['jti'] ?? ''),
		];
	}//end identity()

	/**
	 * The mandate a write acted under: the declared action that granted it,
	 * the audience it was granted to, and the assurance level it demanded. The
	 * portal has no delegation model, so this names the grant, not a proxy.
	 *
	 * @param array<string, mixed> $action The matched update action.
	 * @param array<string, mixed> $subject The resolved portal session.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function mandate(array $action, array $subject): array {
		$mandate = [
			'action' => (string)($action['id'] ?? ''),
			'audience' => (string)($subject['audience'] ?? ''),
			'minTrust' => (string)($action['minTrust'] ?? ''),
		];

		// When the write was made for another entity under a mandate, the
		// record names both (portal-visibility-follows-the-party-tree
		// REQ-PTV-006). The controller resolves them; nothing a request sends
		// reaches these two keys unverified.
		$entity = (string)($subject['actingForEntity'] ?? '');
		if ($entity !== '') {
			$mandate['actingFor'] = $entity;
			$mandate['mandate'] = (string)($subject['actingUnderMandate'] ?? '');
		}

		return $mandate;
	}//end mandate()

	/**
	 * Build the record entry to append to the case.
	 *
	 * @param string $act One of the PortalClientWriteEvent ACT_* constants.
	 * @param array<string, mixed> $subject The resolved portal session.
	 * @param array<string, mixed> $action The matched update action.
	 * @param array<string, array<string, mixed>> $changes Per field, `from` and `to`.
	 * @param string $occurredAt The moment of the write, ISO 8601.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function buildRecord(
		string $act,
		array $subject,
		array $action,
		array $changes,
		string $occurredAt,
	): array {
		return [
			'act' => $act,
			'identity' => $this->identity(subject: $subject),
			'mandate' => $this->mandate(action: $action, subject: $subject),
			'changes' => $changes,
			'at' => $occurredAt,
		];
	}//end buildRecord()

	/**
	 * Append one record to whatever the case already carries, dropping
	 * anything that is not a record so a malformed value cannot make the whole
	 * history unreadable.
	 *
	 * @param mixed $existing The case's current record list.
	 * @param array<string, mixed> $record The record to append.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function append(mixed $existing, array $record): array {
		$history = [];
		if (is_array($existing) === true) {
			foreach ($existing as $entry) {
				if (is_array($entry) === true) {
					$history[] = $entry;
				}
			}
		}

		$history[] = $record;
		return $history;
	}//end append()

	/**
	 * Record the fact in the audit trail and raise the citizen write event.
	 *
	 * Dispatch is typed and happens exactly once, so a rule bound to the event
	 * fires once per act. Nothing else in Portaliq dispatches it, and a staff
	 * write never reaches this class, which is what makes "a staff write is not
	 * a portal write" true by construction rather than by a filter.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case the write landed on.
	 * @param string $act One of the PortalClientWriteEvent ACT_* constants.
	 * @param array<int, string> $fields The field names the act touched.
	 * @param array<string, mixed> $subject The resolved portal session.
	 * @param array<string, mixed> $action The matched update action.
	 * @param string $occurredAt The moment of the write, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function announce(
		string $register,
		string $schema,
		string $caseId,
		string $act,
		array $fields,
		array $subject,
		array $action,
		string $occurredAt,
	): void {
		$this->auditor->record(
			verb: 'update',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $register,
			schema: $schema,
			id: $caseId,
			jti: (string)($subject['jti'] ?? '')
		);

		$this->dispatcher->dispatchTyped(
			new PortalClientWriteEvent(
				register: $register,
				schema: $schema,
				caseId: $caseId,
				act: $act,
				fields: $fields,
				identity: $this->identity(subject: $subject),
				mandate: $this->mandate(action: $action, subject: $subject),
				occurredAt: $occurredAt
			)
		);
	}//end announce()

	/**
	 * The moment a write happened, ISO 8601.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function now(): string {
		return (new DateTimeImmutable())->format(DATE_ATOM);
	}//end now()
}//end class
