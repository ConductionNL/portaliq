<?php

/**
 * CaseQuestions: the open questions the case's own app asks about one case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-open-question-is-answered-on-the-case-page-req-wdm-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\Contracts\PortalBranchScopeInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Contracts\RowActionResolverInterface;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the questions of one case from the collections its contributing app
 * declares for them: a collection on schema `aanvullingsverzoek`, read under
 * that collection's own scope, so a resident only ever sees her own rows.
 * Only rows on this case whose `state` is `open` count, and each carries the
 * endpoint row action the app declared on the collection, so the case page
 * sends the answer through the existing row forward. Portaliq keeps no term
 * state: it passes `hersteltermijn` as the app wrote it.
 *
 * Every failure gives no questions, never someone else's.
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-open-question-is-answered-on-the-case-page-req-wdm-001
 */
class CaseQuestions {

	/**
	 * The schema an app declares its questions to a resident on.
	 */
	public const QUESTION_SCHEMA = 'aanvullingsverzoek';

	/**
	 * The state a question is open in.
	 */
	public const OPEN = 'open';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReaderInterface $reader    Reads a collection under its declared scope.
	 * @param RowActionResolverInterface  $rowAction Decides whether a row action applies to a row.
	 * @param PortalBranchScopeInterface  $branches  Keeps a branch session to its branch's rows.
	 * @param LoggerInterface             $logger    Records why a read gave nothing.
	 *
	 * @spec openspec/changes/woo-dossier-in-my-cases/tasks.md#task-1.1
	 */
	public function __construct(
		private readonly PortalObjectReaderInterface $reader,
		private readonly RowActionResolverInterface $rowAction,
		private readonly PortalBranchScopeInterface $branches,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The open questions on one case, from the contributing app's collections.
	 *
	 * @param array<string, mixed>             $subject       The signed-in subject.
	 * @param array<int, array<string, mixed>> $contributions The subject's aggregated contributions.
	 * @param string                           $app           The case's contributing app.
	 * @param array<string, mixed>             $case          The case the resident opened (her own).
	 *
	 * @return list<array<string, mixed>> One entry per open question: `id`, `collection`, `summary`, `hersteltermijn` when set, `action`.
	 *
	 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-open-question-is-answered-on-the-case-page-req-wdm-001
	 */
	public function forCase(array $subject, array $contributions, string $app, array $case): array {
		$caseIds = $this->caseIds(case: $case);
		if ($app === '' || $caseIds === []) {
			return [];
		}

		$questions = [];
		foreach ($contributions as $contribution) {
			if (($contribution['app'] ?? null) !== $app) {
				continue;
			}

			foreach (($contribution['collections'] ?? []) as $collection) {
				if (is_array($collection) === false || $this->isQuestionCollection(subject: $subject, collection: $collection) === false) {
					continue;
				}

				$action = $this->answerAction(contribution: $contribution, collection: $collection);
				if ($action === null) {
					continue;
				}

				foreach ($this->openRows(subject: $subject, app: $app, collection: $collection, caseIds: $caseIds) as $row) {
					$question = $this->question(collection: $collection, action: $action, row: $row);
					if ($question !== null) {
						$questions[] = $question;
					}
				}
			}
		}

		return $questions;
	}//end forCase()

	/**
	 * Whether a collection holds questions this subject may read.
	 *
	 * @param array<string, mixed> $subject    The subject.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return bool
	 */
	private function isQuestionCollection(array $subject, array $collection): bool {
		return ($collection['schema'] ?? null) === self::QUESTION_SCHEMA
			&& is_string($collection['register'] ?? null) === true
			&& SessionTrust::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === true;
	}//end isQuestionCollection()

	/**
	 * The first endpoint row action the app declared on the collection, or null.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param array<string, mixed> $collection   The collection.
	 *
	 * @return array<string, mixed>|null
	 */
	private function answerAction(array $contribution, array $collection): ?array {
		$ids = (array)($collection['rowActions'] ?? []);
		foreach (($contribution['actions'] ?? []) as $action) {
			if (is_array($action) === true
				&& in_array(($action['id'] ?? null), $ids, true) === true
				&& $this->rowAction->isEndpointRowAction(action: $action) === true
			) {
				return $action;
			}
		}

		return null;
	}//end answerAction()

	/**
	 * The subject's open rows of the collection that belong to this case.
	 *
	 * @param array<string, mixed> $subject    The subject.
	 * @param string               $app        The contributing app.
	 * @param array<string, mixed> $collection The collection.
	 * @param list<string>         $caseIds    The ids the case is known by.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function openRows(array $subject, string $app, array $collection, array $caseIds): array {
		try {
			$rows = $this->reader->readCollection(
				register: (string)$collection['register'],
				schema: self::QUESTION_SCHEMA,
				scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
				subjectRef: (string)($subject['subjectRef'] ?? ''),
				organisation: (string)($subject['organisation'] ?? ''),
				limit: 200,
				scopeClaim: (string)($collection['scopeClaim'] ?? ''),
				contributingApp: $app,
				via: ($collection['via'] ?? null),
				audience: (string)($subject['audience'] ?? ''),
				fields: null,
				filter: (array)($collection['filter'] ?? [])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[CaseQuestions] questions could not be read, none shown',
				['app' => $app, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$open = [];
		foreach ($this->branches->rows(subject: $subject, collection: $collection, rows: $rows) as $row) {
			if (is_array($row) === true
				&& ($row['state'] ?? null) === self::OPEN
				&& in_array((string)($row['case'] ?? ''), $caseIds, true) === true
			) {
				$open[] = $row;
			}
		}

		return $open;
	}//end openRows()

	/**
	 * One question as the case page shows it, or null when its action does not apply to the row.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param array<string, mixed> $action     The answer action.
	 * @param array<string, mixed> $row        The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function question(array $collection, array $action, array $row): ?array {
		$rowId = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($rowId === '' || $this->rowAction->rowMatches(action: $action, row: $row) === false) {
			return null;
		}

		$question = [
			'id' => $rowId,
			'collection' => [
				'id' => (string)($collection['id'] ?? ''),
				'register' => (string)$collection['register'],
				'schema' => self::QUESTION_SCHEMA,
			],
			'summary' => (string)($row['summary'] ?? ''),
			'action' => $action,
		];
		if (is_string($row['hersteltermijn'] ?? null) === true && $row['hersteltermijn'] !== '') {
			$question['hersteltermijn'] = $row['hersteltermijn'];
		}

		return $question;
	}//end question()

	/**
	 * The ids a case is known by: its id and uuid.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return list<string>
	 */
	private function caseIds(array $case): array {
		$ids = [];
		foreach ([$case['id'] ?? null, $case['uuid'] ?? null, $case['@self']['id'] ?? null] as $id) {
			if ((is_string($id) === true || is_int($id) === true) && (string)$id !== '') {
				$ids[] = (string)$id;
			}
		}

		return array_values(array_unique($ids));
	}//end caseIds()
}//end class
