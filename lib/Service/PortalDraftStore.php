<?php

/**
 * Portaliq Portal Draft Store (site-multi-step-forms, REQ-SMF-021)
 *
 * A resident filling in a long form may save what they have and carry on
 * later. Those answers are a draft: portaliq's own, never the contributing
 * app's, and never a record of anything.
 *
 * What a draft holds: the text the resident typed, the step they were on,
 * when they saved and when the answers go. What it does NOT hold: a file (a
 * file is uploaded after a record exists, so the step that asks for one asks
 * again), and anything the app would be sent.
 *
 * Who may read it: only the session that wrote it. A read goes through
 * PortalObjectReader's scoped collection read, which filters on the
 * subject's own `subjectRef` inside OpenRegister, exactly as every other
 * portal read proves ownership. The draft's id is never the proof and never
 * travels to the browser as a way in.
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, writes, discards and purges a resident's own form drafts.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */
class PortalDraftStore {
	/**
	 * The register the draft lives in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The schema the draft lives in.
	 */
	public const SCHEMA = 'portalDraft';

	/**
	 * The one field a read is scoped by.
	 */
	public const SCOPE_FIELD = 'subjectRef';

	/**
	 * OpenRegister's object service, resolved from the container.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * How many expired drafts one purge run removes at most.
	 */
	private const PURGE_PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Scoped reads, the ownership proof.
	 * @param PortalObjectWriter $writer Scoped writes.
	 * @param ContainerInterface $container Resolves OpenRegister for a delete.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The subject's own draft for one action, or null. An expired draft reads
	 * as none, whatever the purge job has got round to.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 * @param string $actionId The action.
	 * @param string $now The moment to measure expiry against, ISO 8601.
	 *
	 * @return array<string, mixed>|null The draft.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	public function mine(array $subject, string $app, string $actionId, string $now): ?array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($subjectRef === '' || $app === '' || $actionId === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: self::SCOPE_FIELD,
			subjectRef: $subjectRef,
			organisation: (string)($subject['organisation'] ?? ''),
			limit: 20,
			filter: ['contributionApp' => $app, 'actionId' => $actionId]
		);

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			// The scoped read already proved whose row this is. These two
			// checks are the filter's own, repeated: a platform that ignores a
			// property filter must not hand somebody another action's answers.
			if ((string)($row['contributionApp'] ?? '') !== $app || (string)($row['actionId'] ?? '') !== $actionId) {
				continue;
			}

			if ($this->hasExpired(row: $row, now: $now) === false) {
				return $row;
			}
		}

		return null;
	}//end mine()

	/**
	 * Save the resident's answers, replacing their earlier draft for this
	 * action. Returns the stored draft, or null when it could not be stored.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 * @param string $actionId The action.
	 * @param array<string, mixed> $answers What the resident typed, text only.
	 * @param int $step The step on screen, from zero.
	 * @param string $expiresAt When these answers go, ISO 8601.
	 * @param string $now The moment of saving, ISO 8601.
	 *
	 * @return array<string, mixed>|null The stored draft.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	public function save(
		array $subject,
		string $app,
		string $actionId,
		array $answers,
		int $step,
		string $expiresAt,
		string $now,
	): ?array {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		if ($subjectRef === '' || $app === '' || $actionId === '') {
			return null;
		}

		$data = [
			'contributionApp' => $app,
			'actionId'        => $actionId,
			'answers'         => $answers,
			'step'            => max(0, $step),
			'savedAt'         => $now,
			'expiresAt'       => $expiresAt,
		];

		$existing = $this->mine(subject: $subject, app: $app, actionId: $actionId, now: $now);
		if ($existing !== null) {
			return $this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: self::SCOPE_FIELD,
				subjectRef: $subjectRef,
				organisation: (string)($subject['organisation'] ?? ''),
				id: $this->idOf(row: $existing),
				data: $data
			);
		}

		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: self::SCOPE_FIELD,
			subjectRef: $subjectRef,
			organisation: (string)($subject['organisation'] ?? ''),
			data: $data
		);
	}//end save()

	/**
	 * Delete the subject's own draft for one action: when they send the
	 * action, and when they say to throw it away.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 * @param string $actionId The action.
	 * @param string $now The moment, ISO 8601.
	 *
	 * @return bool Whether a draft was deleted.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	public function discard(array $subject, string $app, string $actionId, string $now): bool {
		$draft = $this->mine(subject: $subject, app: $app, actionId: $actionId, now: $now);
		if ($draft === null) {
			return false;
		}

		return $this->delete(id: $this->idOf(row: $draft));
	}//end discard()

	/**
	 * Delete every draft whose `expiresAt` has passed, across every subject.
	 *
	 * Each candidate's own `expiresAt` is read again before it is deleted: the
	 * query asks for the expired ones, and this makes sure that is what comes
	 * back. A store that answered with everything would otherwise empty the
	 * schema.
	 *
	 * @param string $now The moment to measure against, ISO 8601.
	 *
	 * @return int How many drafts were deleted.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	public function purgeExpired(string $now): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			$answer = $objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema'   => self::SCHEMA,
						'expiresAt' => ['lt' => $now],
					],
					'limit'  => self::PURGE_PAGE,
					'offset' => 0,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: draft purge could not read drafts', ['reason' => $e->getMessage()]);
			return 0;
		}

		$removed = 0;
		foreach ($this->rowsOf(answer: $answer) as $row) {
			if (is_array($row) === false || $this->hasExpired(row: $row, now: $now) === false) {
				continue;
			}

			if ($this->delete(id: $this->idOf(row: $row)) === true) {
				$removed++;
			}
		}

		return $removed;
	}//end purgeExpired()

	/**
	 * Whether a draft's own `expiresAt` has passed. A draft without a readable
	 * one counts as expired: answers nobody can date are answers nobody
	 * promised to keep.
	 *
	 * @param array<string, mixed> $row The draft.
	 * @param string $now The moment, ISO 8601.
	 *
	 * @return bool True when it has expired.
	 */
	private function hasExpired(array $row, string $now): bool {
		$expires = strtotime((string)($row['expiresAt'] ?? ''));
		$moment  = strtotime($now);
		if ($expires === false || $moment === false) {
			return true;
		}

		return ($expires <= $moment);
	}//end hasExpired()

	/**
	 * Delete one draft by its stored id.
	 *
	 * @param string $id The draft's id.
	 *
	 * @return bool Whether it was deleted.
	 */
	private function delete(string $id): bool {
		$objectService = $this->objectService();
		if ($id === '' || $objectService === null) {
			return false;
		}

		try {
			$objectService->deleteObject(
				uuid: $id,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: a draft could not be deleted', ['reason' => $e->getMessage()]);
			return false;
		}
	}//end delete()

	/**
	 * The rows of a findAll answer, which may be a list or a paged envelope.
	 *
	 * @param mixed $answer What the store answered.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rowsOf(mixed $answer): array {
		if (is_array($answer) === false) {
			return [];
		}

		if (is_array($answer['results'] ?? null) === true) {
			return array_values($answer['results']);
		}

		return array_values($answer);
	}//end rowsOf()

	/**
	 * A stored row's id, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($self['uuid'] ?? null), ($self['id'] ?? null), ($row['uuid'] ?? null), ($row['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
