<?php

/**
 * Portaliq Reveal Service
 *
 * Who filed a report is answered by one person only: the custodian the
 * organisation named, on a request that says why, and never without a record.
 *
 * The rules are all refusals. A request with no motivation is refused before
 * anybody sees it, because an unmotivated reveal cannot be reviewed afterwards.
 * An instance administrator who is not in the declared custodian group is
 * refused exactly like anybody else: being able to do everything on the server
 * is not the same as being the person the organisation trusted with this. And
 * a refusal is written down as carefully as a reveal, because a refusal nobody
 * recorded cannot be told from a request nobody made.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Reports
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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

use DateTimeImmutable;
use OCA\Portaliq\Event\PortalReportRevealedEvent;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Requests, refusals and reveals of a reporter's identity.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
 */
class RevealService {
	/**
	 * The register the request lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a reveal request.
	 */
	private const SCHEMA = 'portalRevealRequest';

	/**
	 * The schema holding what the reporter gave about themselves.
	 */
	private const CONTACT_SCHEMA = 'portalReporterContact';

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Reads the request and the contact.
	 * @param PortalObjectWriter $writer Records the request and its answer.
	 * @param IGroupManager $groupManager Decides who is a custodian.
	 * @param IEventDispatcher $dispatcher Raises the reveal event.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriter $writer,
		private readonly IGroupManager $groupManager,
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Ask to learn who filed a report.
	 *
	 * @param string $reportId The report.
	 * @param string $requestedBy Who asks.
	 * @param string $motivation Why they need to know.
	 *
	 * @return array<string, mixed>|null The recorded request, or null when it
	 *         carries no motivation and is therefore refused outright.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function request(string $reportId, string $requestedBy, string $motivation): ?array {
		if ($reportId === '' || $requestedBy === '' || trim($motivation) === '') {
			return null;
		}

		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'reportRef' => $reportId,
				'requestedBy' => $requestedBy,
				'motivation' => trim($motivation),
				'state' => 'pending',
				'requestedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end request()

	/**
	 * Whether this user is a custodian for this case type's reports.
	 *
	 * @param IUser $user The user.
	 * @param array<string, mixed> $caseType The case type carrying the declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function isCustodian(IUser $user, array $caseType): bool {
		$declaration = ($caseType[ReportTermsService::DECLARATION] ?? null);
		if (is_array($declaration) === false) {
			return false;
		}

		$group = (string)($declaration['custodianGroup'] ?? '');
		if ($group === '') {
			// A case type that named no custodian has nobody who may reveal.
			return false;
		}

		return $this->groupManager->isInGroup($user->getUID(), $group);
	}//end isCustodian()

	/**
	 * Whether this user may handle this case type's reports: read one, write
	 * on its thread and ask for a reveal. A member of the declared
	 * `handlerGroup` may, and so may a custodian. When no handler group is
	 * declared only the custodian group may. Being signed in, or being an
	 * instance administrator, is not enough (intake-report-pages D5,
	 * portaliq#799).
	 *
	 * @param IUser $user The user.
	 * @param array<string, mixed> $caseType The case type carrying the declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md
	 */
	public function mayHandle(IUser $user, array $caseType): bool {
		if ($this->isCustodian(user: $user, caseType: $caseType) === true) {
			return true;
		}

		$declaration = ($caseType[ReportTermsService::DECLARATION] ?? null);
		if (is_array($declaration) === false) {
			return false;
		}

		$group = (string)($declaration['handlerGroup'] ?? '');
		if ($group === '') {
			return false;
		}

		return $this->groupManager->isInGroup($user->getUID(), $group);
	}//end mayHandle()

	/**
	 * Answer a request: allow it and show what the reporter gave, or refuse it.
	 *
	 * @param array<string, mixed> $request The pending request.
	 * @param array<string, mixed> $report The report it is about.
	 * @param IUser $custodian The user answering.
	 * @param array<string, mixed> $caseType The case type, for the declaration.
	 * @param bool $allow Whether it is allowed.
	 * @param string $reason What the custodian says about their answer.
	 *
	 * @return array{state: string, contact?: array<string, mixed>}|array{error: string}
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each parameter is part
	 * of the record this decision writes.
	 */
	public function decide(array $request, array $report, IUser $custodian, array $caseType, bool $allow, string $reason = ''): array {
		return $this->decideRequest(
			decision: new RevealDecision(request: $request, report: $report, custodian: $custodian, caseType: $caseType, allow: $allow, reason: $reason)
		);
	}//end decide()

	/**
	 * Answer a request: allow it and show what the reporter gave, or refuse it.
	 *
	 * @param RevealDecision $decision The request, the report, who answers and how.
	 *
	 * @return array{state: string, contact?: array<string, mixed>}|array{error: string}
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function decideRequest(RevealDecision $decision): array {
		$refusal = $this->refusal(decision: $decision);
		if ($refusal !== null) {
			return ['error' => $refusal];
		}

		$occurredAt = (new DateTimeImmutable())->format(DATE_ATOM);
		if ($decision->allow === false) {
			$this->close(decision: $decision, outcome: new RevealOutcome(state: 'refused', fields: [], occurredAt: $occurredAt));
			return ['state' => 'refused'];
		}

		$contact = $this->contactFor(report: $decision->report);
		$outcome = new RevealOutcome(state: 'allowed', fields: array_keys($contact), occurredAt: $occurredAt);

		$this->close(decision: $decision, outcome: $outcome);
		$this->announceReveal(decision: $decision, outcome: $outcome);

		return ['state' => 'allowed', 'contact' => $contact];
	}//end decideRequest()

	/**
	 * Why a decision may not be taken at all, or null when it may.
	 *
	 * @param RevealDecision $decision The request, the report, who answers and how.
	 *
	 * @return string|null `not_pending` or `not_custodian`.
	 */
	private function refusal(RevealDecision $decision): ?string {
		if ((string)($decision->request['state'] ?? '') !== 'pending') {
			return 'not_pending';
		}

		if ($this->isCustodian(user: $decision->custodian, caseType: $decision->caseType) === false) {
			// Refused before anything is read: an administrator who is not the
			// custodian learns nothing, not even whether there was anything
			// to learn.
			return 'not_custodian';
		}

		return null;
	}//end refusal()

	/**
	 * Raise the reveal event for an allowed request.
	 *
	 * @param RevealDecision $decision The request, the report, who answers and how.
	 * @param RevealOutcome  $outcome  The fields shown and when.
	 *
	 * @return void
	 */
	private function announceReveal(RevealDecision $decision, RevealOutcome $outcome): void {
		$request = $decision->request;
		$this->dispatcher->dispatchTyped(
			new PortalReportRevealedEvent(
				reportId: (string)($request['reportRef'] ?? ''),
				requestedBy: (string)($request['requestedBy'] ?? ''),
				custodian: $decision->custodian->getUID(),
				motivation: (string)($request['motivation'] ?? ''),
				revealedFields: $outcome->fields,
				occurredAt: $outcome->occurredAt
			)
		);
	}//end announceReveal()

	/**
	 * What the reporter gave, read only on an allowed reveal.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<string, mixed> Empty when the reporter gave nothing.
	 */
	private function contactFor(array $report): array {
		$contactRef = (string)($report['contactRef'] ?? '');
		if ($contactRef === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::CONTACT_SCHEMA,
			scopeField: 'reportRef',
			subjectRef: ReportRowId::of(row: $report),
			organisation: '',
			limit: 5
		);

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$contact = [];
			foreach (['name', 'email', 'phone'] as $field) {
				$value = (string)($row[$field] ?? '');
				if ($value !== '') {
					$contact[$field] = $value;
				}
			}

			return $contact;
		}

		return [];
	}//end contactFor()

	/**
	 * Write the custodian's answer onto the request.
	 *
	 * @param RevealDecision $decision The request, who answered and what they said.
	 * @param RevealOutcome  $outcome  Allowed or refused, the fields shown and when.
	 *
	 * @return void
	 */
	private function close(RevealDecision $decision, RevealOutcome $outcome): void {
		$id = ReportRowId::of(row: $decision->request);
		if ($id === '') {
			return;
		}

		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: [
				'state' => $outcome->state,
				'decidedBy' => $decision->custodian->getUID(),
				'decidedAt' => $outcome->occurredAt,
				'decisionReason' => $decision->reason,
				// The names of what was shown, never the values.
				'revealedFields' => $outcome->fields,
			]
		);
	}//end close()
}//end class
