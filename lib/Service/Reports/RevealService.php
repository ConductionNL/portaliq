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
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

use DateTimeImmutable;
use OCA\Portaliq\Event\PortalReportRevealedEvent;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Requests, refusals and reveals of a reporter's identity.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
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
	 * @param PortalObjectReader $reader Reads the request and the contact.
	 * @param PortalObjectWriter $writer Records the request and its answer.
	 * @param IGroupManager $groupManager Decides who is a custodian.
	 * @param IEventDispatcher $dispatcher Raises the reveal event.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
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
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
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
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
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
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each parameter is part
	 * of the record this decision writes.
	 */
	public function decide(array $request, array $report, IUser $custodian, array $caseType, bool $allow, string $reason = ''): array {
		if ((string)($request['state'] ?? '') !== 'pending') {
			return ['error' => 'not_pending'];
		}

		if ($this->isCustodian(user: $custodian, caseType: $caseType) === false) {
			// Refused before anything is read: an administrator who is not the
			// custodian learns nothing, not even whether there was anything
			// to learn.
			return ['error' => 'not_custodian'];
		}

		$occurredAt = (new DateTimeImmutable())->format(DATE_ATOM);
		if ($allow === false) {
			$this->close(request: $request, state: 'refused', custodian: $custodian->getUID(), reason: $reason, fields: [], occurredAt: $occurredAt);
			return ['state' => 'refused'];
		}

		$contact = $this->contactFor(report: $report);
		$fields = array_keys($contact);

		$this->close(request: $request, state: 'allowed', custodian: $custodian->getUID(), reason: $reason, fields: $fields, occurredAt: $occurredAt);

		$this->dispatcher->dispatchTyped(
			new PortalReportRevealedEvent(
				reportId: (string)($request['reportRef'] ?? ''),
				requestedBy: (string)($request['requestedBy'] ?? ''),
				custodian: $custodian->getUID(),
				motivation: (string)($request['motivation'] ?? ''),
				revealedFields: $fields,
				occurredAt: $occurredAt
			)
		);

		return ['state' => 'allowed', 'contact' => $contact];
	}//end decide()

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
			subjectRef: $this->idOf(row: $report),
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
	 * @param array<string, mixed> $request The request row.
	 * @param string $state `allowed` or `refused`.
	 * @param string $custodian Who answered.
	 * @param string $reason What they said.
	 * @param array<int, string> $fields Which fields were shown.
	 * @param string $occurredAt When, ISO 8601.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- see decide().
	 */
	private function close(array $request, string $state, string $custodian, string $reason, array $fields, string $occurredAt): void {
		$id = $this->idOf(row: $request);
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
				'state' => $state,
				'decidedBy' => $custodian,
				'decidedAt' => $occurredAt,
				'decisionReason' => $reason,
				// The names of what was shown, never the values.
				'revealedFields' => $fields,
			]
		);
	}//end close()

	/**
	 * A row's own identifier.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
