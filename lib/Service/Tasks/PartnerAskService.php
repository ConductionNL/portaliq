<?php

/**
 * Portaliq Partner Ask Service
 *
 * A handler asks an outside partner for something from the case: an opinion, a
 * document, an answer. The partner may have no account yet, which is the whole
 * difficulty: this resolves them to a portal account, pre-provisioning one when
 * there is none, and raises the task against that account.
 *
 * Two things are frozen onto the task at the moment it is raised, the due date
 * and the upload rules, so that editing the case type afterwards never changes
 * what somebody was already asked for.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tasks
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
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tasks;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalTaskGateway;

/**
 * Resolves the partner and raises the task addressed to them.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PartnerAskService {
	/**
	 * The audience a partner account carries.
	 */
	public const AUDIENCE = 'partner';

	/**
	 * The identity type a KvK number is stored under.
	 */
	private const IDENTITY_TYPE = 'eherkenning';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Finds or pre-provisions the partner.
	 * @param PortalTaskGateway $tasks Raises the task on the engine.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalTaskGateway $tasks,
	) {
	}//end __construct()

	/**
	 * Ask a partner for something from a case.
	 *
	 * @param array<string, mixed> $handler The handler's subject shape, used
	 *                                      for the server-to-server assertion.
	 * @param array<string, mixed> $partner `subjectRef`, or `kvk` plus `email`
	 *                                      and optionally `name`.
	 * @param array<string, mixed> $ask `title`, `description`, `dueAt`,
	 *                                  `uploadRules`, and the case tuple
	 *                                  `register`, `schema`, `caseId`.
	 *
	 * @return array{subjectRef: string, provisioned: bool, task: array<string, mixed>}|null
	 *         Null when the ask carries no title, no partner that can be
	 *         resolved, or when the engine refused the task.
	 *
	 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
	 */
	public function ask(array $handler, array $partner, array $ask): ?array {
		$title = trim((string)($ask['title'] ?? ''));
		if ($title === '' || (string)($ask['caseId'] ?? '') === '') {
			// A task with no title and no case is not an ask anybody could
			// answer, so nothing is provisioned and nothing is raised.
			return null;
		}

		$resolved = $this->resolvePartner(partner: $partner, handler: $handler);
		if ($resolved === null) {
			return null;
		}

		$raised = $this->tasks->createTask(
			handler: $handler,
			subjectRef: $resolved['subjectRef'],
			task: [
				'title' => $title,
				'description' => (string)($ask['description'] ?? ''),
				// Frozen on the task: what was asked stays what was asked.
				'dueAt' => (string)($ask['dueAt'] ?? ''),
				'uploadRules' => (array)($ask['uploadRules'] ?? []),
				'object' => [
					'register' => (string)($ask['register'] ?? ''),
					'schema' => (string)($ask['schema'] ?? ''),
					'id' => (string)($ask['caseId'] ?? ''),
				],
				'audience' => self::AUDIENCE,
				'raisedBy' => (string)($handler['subjectRef'] ?? ''),
			]
		);
		if ($raised === null || $raised['status'] >= 400) {
			return null;
		}

		return [
			'subjectRef' => $resolved['subjectRef'],
			'provisioned' => $resolved['provisioned'],
			'task' => (array)($raised['body'] ?? []),
		];
	}//end ask()

	/**
	 * The account the task will be addressed to: the one named, or a pending
	 * one provisioned from a KvK number and an address.
	 *
	 * @param array<string, mixed> $partner The partner as the handler named them.
	 * @param array<string, mixed> $handler The handler's subject shape.
	 *
	 * @return array{subjectRef: string, provisioned: bool}|null
	 *
	 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
	 */
	private function resolvePartner(array $partner, array $handler): ?array {
		$subjectRef = (string)($partner['subjectRef'] ?? '');
		if ($subjectRef !== '') {
			$existing = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
			if ($existing === null) {
				// A subjectRef nobody answers to is not a partner. It is
				// refused rather than provisioned, because provisioning on a
				// client-supplied reference would let a caller mint accounts.
				return null;
			}

			return ['subjectRef' => $subjectRef, 'provisioned' => false];
		}

		$kvk = trim((string)($partner['kvk'] ?? ''));
		$email = trim((string)($partner['email'] ?? ''));
		if ($kvk === '' && $email === '') {
			return null;
		}

		$identityType = '';
		if ($kvk !== '') {
			$identityType = self::IDENTITY_TYPE;
		}

		$provisioned = $this->accounts->provision(
			audience: self::AUDIENCE,
			organisation: (string)($handler['organisation'] ?? ''),
			identityType: $identityType,
			identityRef: $kvk,
			email: $email,
			// The address was typed by a handler, not proven by the partner:
			// it may carry the invitation, and it may not claim an account.
			verifiedEmail: false,
			provisionedBy: (string)($handler['uid'] ?? $handler['subjectRef'] ?? ''),
			displayName: (string)($partner['name'] ?? '')
		);
		if ($provisioned === null) {
			return null;
		}

		return ['subjectRef' => $provisioned['subjectRef'], 'provisioned' => $provisioned['isNew']];
	}//end resolvePartner()
}//end class
