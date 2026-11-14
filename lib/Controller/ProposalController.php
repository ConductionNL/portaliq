<?php

/**
 * Portaliq Proposal Controller
 *
 * The two ways a change proposal gets made, and the two ways it gets decided.
 *
 * A portal subject proposes through the bearer-guarded portal route, against
 * the properties their contribution lists as proposable. A colleague with read
 * but not write on the record proposes through the staff route. Both write a
 * queued proposal and nothing else: the record itself is only ever touched by
 * a reviewer accepting one, with that reviewer's own rights.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalCaseAccessGuardInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Support\BearerSessionResolver;
use OCA\Portaliq\Kernel\Contracts\ProposalServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Takes proposals and decides them.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per step:
 * the session, the contribution allow-list, the record, the queue, the guard.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class ProposalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProposalServiceInterface $proposals The queue itself.
	 * @param PortalContributionRegistryInterface $registry The contribution aggregator.
	 * @param PortalObjectReaderInterface $reader Reads the record being proposed about.
	 * @param BearerSessionResolver $session Resolves a portal subject.
	 * @param PortalCaseAccessGuardInterface $guard Decides who may review.
	 * @param IUserSession $userSession The staff user, when there is one.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProposalServiceInterface $proposals,
		private readonly PortalContributionRegistryInterface $registry,
		private readonly PortalObjectReaderInterface $reader,
		private readonly BearerSessionResolver $session,
		private readonly PortalCaseAccessGuardInterface $guard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose a change from the portal, as the bearer's subject.
	 *
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param array<int, array<string, mixed>> $changes Each `property` and `proposedValue`.
	 * @param string $note What the proposer says about it.
	 *
	 * @return JSONResponse The queued proposal, or a refusal.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function proposeFromPortal(string $register, string $schema, string $id, array $changes = [], string $note = ''): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->proposableFor(subject: $subject, register: $register, schema: $schema);
		if ($match === null) {
			return new JSONResponse(['error' => 'not_proposable'], Http::STATUS_FORBIDDEN);
		}

		$proposal = ['record' => ['register' => $register, 'schema' => $schema, 'id' => $id], 'match' => $match];
		$row      = $this->ownRecord(subject: $subject, proposal: $proposal);
		if ($row === null) {
			return new JSONResponse(['error' => 'not_yours'], Http::STATUS_FORBIDDEN);
		}

		$edit = ['changes' => $changes, 'note' => $note, 'row' => $row, 'proposedBy' => (string)($subject['subjectRef'] ?? '')];
		return $this->answer(result: $this->proposed(proposal: $proposal, edit: $edit));
	}//end proposeFromPortal()

	/**
	 * Propose a change as a colleague who may read the record but not write it.
	 *
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param array<int, array<string, mixed>> $changes Each `property` and `proposedValue`.
	 * @param array<int, string> $proposable The properties the host app allows.
	 * @param string $note What the proposer says about it.
	 *
	 * @return JSONResponse The queued proposal, or a refusal.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[NoAdminRequired]
	public function proposeAsColleague(
		string $register,
		string $schema,
		string $id,
		array $changes = [],
		array $proposable = [],
		string $note = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// A colleague proposes on a record they can see. Without this any
		// account could queue a proposal on whatever id it named.
		if ($this->guard->mayRead(register: $register, schema: $schema, id: $id) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(
			result: $this->proposals->propose(
				subject: ['register' => $register, 'schema' => $schema, 'id' => $id],
				changes: $changes,
				proposable: $proposable,
				// A colleague proposes on what they can see; the snapshot is
				// taken from the record itself when the reviewer accepts,
				// which is where the drift check lives.
				subjectRow: [],
				proposedBy: $user->getUID(),
				channel: 'staff',
				note: $note
			)
		);
	}//end proposeAsColleague()

	/**
	 * The queued proposals on one record.
	 *
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param string $state The state to list.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id, string $state = ProposalServiceInterface::STATE_QUEUED): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The queue is a reviewer's view of somebody's record, so it is
		// gated on the same action and the same per-object read as
		// accepting one. Being logged in settles nothing here: the
		// register, schema and id all come from the caller, so without
		// this any account on the instance could read the proposals,
		// their notes and their proposed values on any record.
		$mayReview = $this->guard->mayAct(
			user: $user,
			register: $register,
			schema: $schema,
			id: $id,
			action: PortalCaseAccessGuardInterface::ACTION_REVIEW_PROPOSAL
		);
		if ($mayReview === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$proposals = $this->proposals->forSubject(
			subject: ['register' => $register, 'schema' => $schema, 'id' => $id],
			state: $state
		);

		return new JSONResponse(['proposals' => $proposals]);
	}//end index()

	/**
	 * The bearer's own proposals, any state.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-a-proposer-can-list-their-own-proposals-req-cpq-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function mine(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['proposals' => $this->proposals->mine(proposedBy: (string)($subject['subjectRef'] ?? ''))]);
	}//end mine()

	/**
	 * Accept a proposal, writing the record as the reviewer.
	 *
	 * Refuses with 409 when the record moved since the proposal was made. The
	 * reviewer then sees both values and decides again, on the other route.
	 *
	 * @param string $id The proposal id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		$decision = $this->decidable(id: $id);
		if ($decision instanceof JSONResponse) {
			return $decision;
		}

		return $this->acceptAnswer(result: $this->proposals->accept(
			proposal: $decision['proposal'],
			subjectRow: $decision['subjectRow'],
			reviewer: $decision['reviewer']
		));
	}//end accept()

	/**
	 * Accept a proposal the reviewer has been shown drift on and confirmed.
	 *
	 * Its own route, not a flag on the one above. Overwriting a change nobody
	 * reviewed is a decision somebody takes, and it should read like one at
	 * every layer.
	 *
	 * @param string $id The proposal id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[NoAdminRequired]
	public function acceptConfirmingDrift(string $id): JSONResponse {
		$decision = $this->decidable(id: $id);
		if ($decision instanceof JSONResponse) {
			return $decision;
		}

		return $this->acceptAnswer(result: $this->proposals->acceptConfirmingDrift(
			proposal: $decision['proposal'],
			reviewer: $decision['reviewer']
		));
	}//end acceptConfirmingDrift()

	/**
	 * One accept result as a response, so both routes answer alike.
	 *
	 * @param array<string, mixed> $result What the service decided.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	private function acceptAnswer(array $result): JSONResponse {
		if (isset($result['error']) === false) {
			return new JSONResponse($result);
		}

		$status = Http::STATUS_BAD_REQUEST;
		if ($result['error'] === 'drifted') {
			$status = Http::STATUS_CONFLICT;
		}

		return new JSONResponse($result, $status);
	}//end acceptAnswer()

	/**
	 * Reject a proposal, with a reason.
	 *
	 * @param string $id The proposal id.
	 * @param string $reason Why it is rejected.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[NoAdminRequired]
	public function reject(string $id, string $reason = ''): JSONResponse {
		$decision = $this->decidable(id: $id);
		if ($decision instanceof JSONResponse) {
			return $decision;
		}

		$result = $this->proposals->reject(proposal: $decision['proposal'], reviewer: $decision['reviewer'], reason: $reason);
		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end reject()

	/**
	 * Withdraw your own proposal from the portal.
	 *
	 * @param string $id The proposal id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function withdraw(string $id): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$proposal = $this->proposalById(id: $id);
		if ($proposal === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->proposals->withdraw(proposal: $proposal, proposedBy: (string)($subject['subjectRef'] ?? ''));
		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($result);
	}//end withdraw()

	/**
	 * The proposal, the record and the reviewer for a decision, or the refusal
	 * that stops it.
	 *
	 * @param string $id The proposal id.
	 *
	 * @return array{proposal: array<string, mixed>, subjectRow: array<string, mixed>, reviewer: string}|JSONResponse
	 */
	private function decidable(string $id): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$proposal = $this->proposalById(id: $id);
		if ($proposal === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		// Deciding is a write on somebody's record, so it is gated before
		// anything is read or written: a reviewer without write rights gets
		// the same refusal whether the proposal exists or not.
		if ($this->mayReview(user: $user, proposal: $proposal) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return ['proposal' => $proposal, 'subjectRow' => $this->subjectRowOf(proposal: $proposal), 'reviewer' => $user->getUID()];
	}//end decidable()

	/**
	 * Whether this user may review a proposal on its record.
	 *
	 * @param IUser                $user     The signed-in user.
	 * @param array<string, mixed> $proposal The proposal.
	 *
	 * @return bool
	 */
	private function mayReview(IUser $user, array $proposal): bool {
		return $this->guard->mayAct(
			user: $user,
			register: (string)($proposal['subjectRegister'] ?? ''),
			schema: (string)($proposal['subjectSchema'] ?? ''),
			id: (string)($proposal['subjectId'] ?? ''),
			action: PortalCaseAccessGuardInterface::ACTION_REVIEW_PROPOSAL
		);
	}//end mayReview()

	/**
	 * The record a proposal is on, or an empty record when it is gone.
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 *
	 * @return array<string, mixed>
	 */
	private function subjectRowOf(array $proposal): array {
		$subjectRow = $this->reader->readObject(
			register: (string)($proposal['subjectRegister'] ?? ''),
			schema: (string)($proposal['subjectSchema'] ?? ''),
			scopeField: '',
			subjectRef: '',
			id: (string)($proposal['subjectId'] ?? ''),
			organisation: '',
			scopeClaim: '',
			contributingApp: '',
			audience: ''
		);

		return (array)($subjectRow ?? []);
	}//end subjectRowOf()

	/**
	 * One proposal by id, or null.
	 *
	 * @param string $id The proposal id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function proposalById(string $id): ?array {
		if ($id === '') {
			return null;
		}

		return $this->reader->readObject(
			register: 'portaliq',
			schema: 'changeProposal',
			scopeField: '',
			subjectRef: '',
			id: $id,
			organisation: '',
			scopeClaim: '',
			contributingApp: '',
			audience: ''
		);
	}//end proposalById()

	/**
	 * The proposable properties this subject's contribution declares for a
	 * register and schema, and the action that carries them.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array{action: array<string, mixed>, app: string, proposable: array<int, string>}|null
	 */
	private function proposableFor(array $subject, string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === false || ($action['type'] ?? '') !== 'propose-change') {
					continue;
				}

				if (($action['register'] ?? '') !== $register || ($action['schema'] ?? '') !== $schema) {
					continue;
				}

				$proposable = ($action['proposable'] ?? []);
				if (is_array($proposable) === false || $proposable === []) {
					// An action with an empty allow-list proposes nothing:
					// silence is not permission.
					continue;
				}

				return [
					'action' => $action,
					'app' => (string)($contribution['app'] ?? ''),
					'proposable' => array_values(array_filter($proposable, static fn (mixed $entry): bool => is_string($entry) === true)),
				];
			}
		}

		return null;
	}//end proposableFor()

	/**
	 * The record a portal proposal is about, read through the subject's own
	 * scoping: a record that is not theirs and one that does not exist
	 * answer identically, with null.
	 *
	 * @param array<string, mixed> $subject  The bearer's subject.
	 * @param array<string, mixed> $proposal The record asked for and the matched propose action.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownRecord(array $subject, array $proposal): ?array {
		$record = $proposal['record'];
		$action = $proposal['match']['action'];
		return $this->reader->readObject(
			register: $record['register'],
			schema: $record['schema'],
			scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $record['id'],
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($action['scopeClaim'] ?? ''),
			contributingApp: $proposal['match']['app'],
			audience: (string)($subject['audience'] ?? '')
		);
	}//end ownRecord()

	/**
	 * Queue a portal proposal on the record that was read.
	 *
	 * @param array<string, mixed> $proposal The record and the matched propose action.
	 * @param array<string, mixed> $edit     The changes, the note, the read row and the proposer.
	 *
	 * @return array<string, mixed> What the service answered.
	 */
	private function proposed(array $proposal, array $edit): array {
		return $this->proposals->propose(
			subject: $proposal['record'],
			changes: $edit['changes'],
			proposable: $proposal['match']['proposable'],
			subjectRow: $edit['row'],
			proposedBy: $edit['proposedBy'],
			channel: 'portal',
			note: $edit['note']
		);
	}//end proposed()

	/**
	 * Turn a service answer into a response.
	 *
	 * @param array<string, mixed> $result What the service answered.
	 *
	 * @return JSONResponse
	 */
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === false) {
			return new JSONResponse($result);
		}

		$status = Http::STATUS_BAD_REQUEST;
		if ($result['error'] === 'property_not_proposable') {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		return new JSONResponse($result, $status);
	}//end answer()
}//end class
