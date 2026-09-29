<?php

/**
 * The data half of the change-proposal queue, as an OpenRegister leaf.
 *
 * OpenRegister routes `GET` and `POST` on
 * `/api/objects/{register}/{schema}/{id}/integrations/portaliq-change-proposals`
 * here, after its own check that the caller may read that record. `list`
 * answers with the queued proposals on the record, but only to someone who may
 * review them there, the same gate the review routes use. `create` records a
 * colleague's proposal. Nothing else is offered: a proposal is decided on the
 * review routes and never edited or deleted through a host app.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Proposals
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Lists and appends change proposals for one record.
 */
class ChangeProposalsProvider implements IntegrationProvider {

	/**
	 * The leaf id, shared by the descriptor and this provider.
	 */
	public const ID = 'portaliq-change-proposals';

	/**
	 * The icon both leaves show.
	 */
	public const ICON = 'FileDocumentEditOutline';

	/**
	 * The admin group both leaves sit in.
	 */
	public const GROUP = 'workflow';

	/**
	 * Constructor.
	 *
	 * @param ProposalService       $proposals   The queue.
	 * @param PortalCaseAccessGuard $guard       Decides who may review and who may read.
	 * @param IUserSession          $userSession The calling user.
	 * @param IL10N                 $l10n        Translates the label.
	 */
	public function __construct(
		private readonly ProposalService $proposals,
		private readonly PortalCaseAccessGuard $guard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label shown to people.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Change proposals');
	}//end getLabel()

	/**
	 * The icon.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getIcon(): string {
		return self::ICON;
	}//end getIcon()

	/**
	 * The admin group.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getGroup(): ?string {
		return self::GROUP;
	}//end getGroup()

	/**
	 * The app the leaf needs.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getRequiredApp(): ?string {
		return Application::APP_ID;
	}//end getRequiredApp()

	/**
	 * The proposals live in portaliq's own register.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source: the data is local.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * Usable whenever portaliq itself runs, which is when this is constructed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * No extra permission: `list` and `create` apply their own gates.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * No credentials: the leaf runs as the signed-in user.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function authRequirements(): array {
		return ['type' => 'none'];
	}//end authRequirements()

	/**
	 * The queued proposals on the record, for someone who may review them.
	 *
	 * Anyone else gets an empty list rather than a refusal: the host renders
	 * an empty queue, and a person without the review action learns nothing
	 * about what others proposed.
	 *
	 * @param string               $register The record's register.
	 * @param string               $schema   The record's schema.
	 * @param string               $objectId The record.
	 * @param array<string, mixed> $filters  Ignored.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$mayReview = $this->guard->mayAct(
			user: $user,
			register: $register,
			schema: $schema,
			id: $objectId,
			action: PortalCaseAccessGuard::ACTION_REVIEW_PROPOSAL
		);
		if ($mayReview === false) {
			return [];
		}

		return $this->proposals->forSubject(subject: ['register' => $register, 'schema' => $schema, 'id' => $objectId]);
	}//end list()

	/**
	 * One proposal is read on the review routes, not through a host.
	 *
	 * @param string $register The record's register.
	 * @param string $schema   The record's schema.
	 * @param string $objectId The record.
	 * @param string $entityId The proposal.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NotImplementedException Always.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		throw new NotImplementedException('A change proposal is read on the review routes.');
	}//end get()

	/**
	 * Record a colleague's proposal on the record.
	 *
	 * The payload carries `changes` (each `property` and `proposedValue`),
	 * `proposable` (the properties the host app lets people propose) and an
	 * optional `note`. The proposer is the signed-in user, never the payload.
	 *
	 * @param string               $register The record's register.
	 * @param string               $schema   The record's schema.
	 * @param string               $objectId The record.
	 * @param array<string, mixed> $payload  The proposal.
	 *
	 * @return array<string, mixed> The queued proposal.
	 *
	 * @throws InvalidArgumentException When the proposal is refused; OpenRegister answers 400.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new InvalidArgumentException('not_authenticated');
		}

		if ($this->guard->mayRead(user: $user, register: $register, schema: $schema, id: $objectId) === false) {
			throw new InvalidArgumentException('not_readable');
		}

		$result = $this->proposals->propose(
			subject: ['register' => $register, 'schema' => $schema, 'id' => $objectId],
			changes: $this->listOf(value: ($payload['changes'] ?? [])),
			proposable: array_map('strval', $this->listOf(value: ($payload['proposable'] ?? []))),
			subjectRow: [],
			proposedBy: $user->getUID(),
			channel: 'staff',
			note: (string)($payload['note'] ?? '')
		);
		if (isset($result['proposal']) === false) {
			throw new InvalidArgumentException((string)($result['error'] ?? 'refused'));
		}

		return $result['proposal'];
	}//end create()

	/**
	 * A proposal is not edited through a host.
	 *
	 * @param string               $register The record's register.
	 * @param string               $schema   The record's schema.
	 * @param string               $objectId The record.
	 * @param string               $entityId The proposal.
	 * @param array<string, mixed> $payload  Ignored.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NotImplementedException Always.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new NotImplementedException('A change proposal is decided on the review routes.');
	}//end update()

	/**
	 * A proposal is not deleted: it is rejected or withdrawn, and kept.
	 *
	 * @param string $register The record's register.
	 * @param string $schema   The record's schema.
	 * @param string $objectId The record.
	 * @param string $entityId The proposal.
	 *
	 * @return void
	 *
	 * @throws NotImplementedException Always.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new NotImplementedException('A change proposal is rejected or withdrawn, never deleted.');
	}//end delete()

	/**
	 * Always available while portaliq runs.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function health(): array {
		return ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
	}//end health()

	/**
	 * A list value from the payload, or an empty list.
	 *
	 * @param mixed $value The payload value.
	 *
	 * @return array<int, mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end listOf()
}//end class
