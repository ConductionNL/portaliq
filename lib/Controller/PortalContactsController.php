<?php

/**
 * Portaliq Portal Contacts Controller
 *
 * A resident's own contacts: the list, invitations, answers and removal.
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The bearer's own contacts. Every answer is scoped to the bearer's own rows.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class PortalContactsController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalContactService $contacts The contact rules.
	 * @param PortalAccountLookup $accounts Reads the bearer's name.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalContactService $contacts,
		private readonly PortalAccountLookup $accounts,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The bearer's contacts, what waits for an answer and the counts per role.
	 *
	 * @return JSONResponse The overview, or 401 without a session.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t06
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse($this->contacts->overview(owner: (string)$subject['subjectRef'], organisation: (string)($subject['organisation'] ?? '')));
	}//end index()

	/**
	 * Invite someone by e-mail address.
	 *
	 * @param string $email The address.
	 * @param string $message An optional short message.
	 *
	 * @return JSONResponse `{sent: true}`, or 400, 409 or 429 with the reason.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function invite(string $email='', string $message=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->contacts->invite(subject: $subject, email: $email, message: $message));
	}//end invite()

	/**
	 * Accept or decline a request from another account.
	 *
	 * @param string $id The request row.
	 *
	 * The request carries `accept` (accept when true, decline otherwise); it is read from the
	 * request, not bound, so the method takes no flag argument.
	 *
	 * @return JSONResponse `{sent: true}`, 404 or 401.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function respond(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		// Cast as the framework casts a bound bool: the text "false" and anything falsy decline.
		$raw    = $this->request->getParam('accept', false);
		$accept = ($raw !== 'false' && (bool)$raw === true);

		return $this->answer(result: $this->contacts->respond(subject: $subject, id: $id, accept: $accept));
	}//end respond()

	/**
	 * Send an invitation again.
	 *
	 * @param string $id The invitation row.
	 *
	 * @return JSONResponse `{sent: true}`, 404 or 401.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function resend(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->contacts->resend(subject: $subject, id: $id));
	}//end resend()

	/**
	 * Take an invitation or request back.
	 *
	 * @param string $id The row.
	 *
	 * @return JSONResponse `{sent: true}`, 404 or 401.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function withdraw(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->contacts->withdraw(subject: $subject, id: $id));
	}//end withdraw()

	/**
	 * Remove an approved contact.
	 *
	 * @param string $id The row.
	 *
	 * @return JSONResponse `{sent: true}`, 404 or 401.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function remove(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->contacts->remove(subject: $subject, id: $id));
	}//end remove()

	/**
	 * A new account hands back the secret of the invitation it followed.
	 *
	 * @param string $token The secret from the mail.
	 *
	 * @return JSONResponse `{sent: true}` when joined, 404 for a link that is unknown, expired or used.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function acceptInvitation(string $token=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(
			result: $this->contacts->acceptInvitation(subject: $subject, token: $token, displayName: (string)($subject['displayName'] ?? ''))
		);
	}//end acceptInvitation()

	/**
	 * The answer for a service result.
	 *
	 * @param string $result One of the PortalContactService outcomes.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $result): JSONResponse {
		$status = match ($result) {
			PortalContactService::SENT => Http::STATUS_OK,
			PortalContactService::INVALID => Http::STATUS_BAD_REQUEST,
			PortalContactService::DUPLICATE => Http::STATUS_CONFLICT,
			PortalContactService::LIMIT => Http::STATUS_TOO_MANY_REQUESTS,
			PortalContactService::NOT_FOUND => Http::STATUS_NOT_FOUND,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
		if ($status === Http::STATUS_OK) {
			return new JSONResponse(['sent' => true]);
		}

		return new JSONResponse(['error' => $result], $status);
	}//end answer()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * The subject behind the bearer with the account's name, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null || (string)($subject['subjectRef'] ?? '') === '') {
			return null;
		}

		$account = $this->accounts->bySubjectRef(subjectRef: (string)$subject['subjectRef']);
		return ($subject + ['displayName' => (string)($account['displayName'] ?? '')]);
	}//end subject()
}//end class
