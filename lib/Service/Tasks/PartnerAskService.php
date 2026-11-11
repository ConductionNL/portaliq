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
		// A task with no title and no case is not an ask anybody could
		// answer, so nothing is provisioned and nothing is raised.
		if ($this->isAnswerable(ask: $ask) === false) {
			return null;
		}

		$resolved = $this->resolvePartner(partner: $partner, handler: $handler);
		if ($resolved === null) {
			return null;
		}

		$raised = $this->tasks->createTask(handler: $handler, subjectRef: $resolved['subjectRef'], task: $this->taskOf(ask: $ask, handler: $handler));
		$task   = $this->raisedTask(raised: $raised);
		if ($task === null) {
			return null;
		}

		return ['subjectRef' => $resolved['subjectRef'], 'provisioned' => $resolved['provisioned'], 'task' => $task];
	}//end ask()

	/**
	 * Whether an ask carries a title and a case.
	 *
	 * @param array<string, mixed> $ask The ask.
	 *
	 * @return bool
	 */
	private function isAnswerable(array $ask): bool {
		return trim((string)($ask['title'] ?? '')) !== '' && (string)($ask['caseId'] ?? '') !== '';
	}//end isAnswerable()

	/**
	 * The task to raise: what was asked, frozen on the task, the case it is about, and who raised it.
	 *
	 * @param array<string, mixed> $ask     The ask.
	 * @param array<string, mixed> $handler The handler's subject shape.
	 *
	 * @return array<string, mixed>
	 */
	private function taskOf(array $ask, array $handler): array {
		return $this->askedOf(ask: $ask) + [
			'object' => $this->caseOf(ask: $ask),
			'audience' => self::AUDIENCE,
			'raisedBy' => (string)($handler['subjectRef'] ?? ''),
		];
	}//end taskOf()

	/**
	 * What was asked: the title, description, due date and upload rules.
	 * Frozen on the task: what was asked stays what was asked.
	 *
	 * @param array<string, mixed> $ask The ask.
	 *
	 * @return array{title: string, description: string, dueAt: string, uploadRules: array<mixed>}
	 */
	private function askedOf(array $ask): array {
		return [
			'title' => trim((string)($ask['title'] ?? '')),
			'description' => (string)($ask['description'] ?? ''),
			'dueAt' => (string)($ask['dueAt'] ?? ''),
			'uploadRules' => (array)($ask['uploadRules'] ?? []),
		];
	}//end askedOf()

	/**
	 * The case the ask is about.
	 *
	 * @param array<string, mixed> $ask The ask.
	 *
	 * @return array{register: string, schema: string, id: string}
	 */
	private function caseOf(array $ask): array {
		return [
			'register' => (string)($ask['register'] ?? ''),
			'schema' => (string)($ask['schema'] ?? ''),
			'id' => (string)($ask['caseId'] ?? ''),
		];
	}//end caseOf()

	/**
	 * The raised task's body, or null when the engine refused it or did not answer.
	 *
	 * @param array<string, mixed>|null $raised The engine's answer.
	 *
	 * @return array<mixed>|null
	 */
	private function raisedTask(?array $raised): ?array {
		if ($raised === null || $raised['status'] >= 400) {
			return null;
		}

		return (array)($raised['body'] ?? []);
	}//end raisedTask()

	/**
	 * The account the task will be addressed to: the one named, or a pending
	 * one provisioned from a KvK number and an address. A withdrawn (void)
	 * account is refused as an unknown partner is.
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
			return $this->knownPartner(subjectRef: $subjectRef);
		}

		return $this->provisionedPartner(partner: $partner, handler: $handler);
	}//end resolvePartner()

	/**
	 * A named partner, only when an account that is not withdrawn answers to the reference.
	 *
	 * A subjectRef nobody answers to is not a partner. It is refused rather
	 * than provisioned, because provisioning on a client-supplied reference
	 * would let a caller mint accounts.
	 *
	 * @param string $subjectRef The reference the handler named.
	 *
	 * @return array{subjectRef: string, provisioned: bool}|null
	 */
	private function knownPartner(string $subjectRef): ?array {
		if ($this->isLive(account: $this->accounts->findBySubjectRef(subjectRef: $subjectRef)) === false) {
			return null;
		}

		return ['subjectRef' => $subjectRef, 'provisioned' => false];
	}//end knownPartner()

	/**
	 * A partner provisioned from a KvK number and an address, or null without either
	 * or when the provisioned account is withdrawn.
	 *
	 * @param array<string, mixed> $partner The partner as the handler named them.
	 * @param array<string, mixed> $handler The handler's subject shape.
	 *
	 * @return array{subjectRef: string, provisioned: bool}|null
	 */
	private function provisionedPartner(array $partner, array $handler): ?array {
		$contact = $this->contactOf(partner: $partner);
		if ($contact['kvk'] === '' && $contact['email'] === '') {
			return null;
		}

		$provisioned = $this->provision(contact: $contact, handler: $handler);
		if ($this->isLive(account: $provisioned) === false) {
			return null;
		}

		return ['subjectRef' => $provisioned['subjectRef'], 'provisioned' => $provisioned['isNew']];
	}//end provisionedPartner()

	/**
	 * The partner's KvK number and address, trimmed, and name.
	 *
	 * @param array<string, mixed> $partner The partner as the handler named them.
	 *
	 * @return array{kvk: string, email: string, name: string}
	 */
	private function contactOf(array $partner): array {
		return [
			'kvk' => trim((string)($partner['kvk'] ?? '')),
			'email' => trim((string)($partner['email'] ?? '')),
			'name' => (string)($partner['name'] ?? ''),
		];
	}//end contactOf()

	/**
	 * Provision the partner's pending account.
	 *
	 * @param array{kvk: string, email: string, name: string} $contact The partner's contact.
	 * @param array<string, mixed>                            $handler The handler's subject shape.
	 *
	 * @return array<string, mixed>|null
	 */
	private function provision(array $contact, array $handler): ?array {
		return $this->accounts->provision(
			audience: self::AUDIENCE,
			organisation: (string)($handler['organisation'] ?? ''),
			identityType: $this->identityTypeOf(kvk: $contact['kvk']),
			identityRef: $contact['kvk'],
			email: $contact['email'],
			// The address was typed by a handler, not proven by the partner:
			// it may carry the invitation, and it may not claim an account.
			verifiedEmail: false,
			provisionedBy: (string)($handler['uid'] ?? $handler['subjectRef'] ?? ''),
			displayName: $contact['name']
		);
	}//end provision()

	/**
	 * The identity type a KvK number carries; none without one.
	 *
	 * @param string $kvk The KvK number, or ''.
	 *
	 * @return string
	 */
	private function identityTypeOf(string $kvk): string {
		if ($kvk === '') {
			return '';
		}

		return self::IDENTITY_TYPE;
	}//end identityTypeOf()

	/**
	 * Whether there is an account and it was not withdrawn.
	 *
	 * @param array<string, mixed>|null $account The account, or null.
	 *
	 * @return bool
	 */
	private function isLive(?array $account): bool {
		return $account !== null && $this->isVoid(account: $account) === false;
	}//end isLive()


	/**
	 * Whether an account was withdrawn.
	 *
	 * @param array<string, mixed> $account The account, or the provisioning result.
	 *
	 * @return bool
	 */
	private function isVoid(array $account): bool {
		return ($account['status'] ?? '') === PortalAccountService::STATUS_VOID;
	}//end isVoid()
}//end class
