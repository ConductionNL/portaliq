<?php

/**
 * Portaliq Portal Account Self Controller
 *
 * What the bearer may do to their own account: change its details, confirm a
 * new address, ask for access they do not have, read the answers they were
 * given, and have the account removed.
 *
 * Every surface here is scoped to the bearer's own account. Nothing takes an
 * account identifier from the request: the subject always comes from the
 * bearer, so naming somebody else's account changes nothing.
 *
 * Split out of PortalIdentityController, which keeps the way in. The routes
 * and their URLs are unchanged; only the controller behind them moved.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The bearer's own account.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccountSelfController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalSelfServiceService $selfService The account's own details.
	 * @param PortalAccessRequestService $accessRequests Asking for access.
	 * @param PortalIdentityMailer $mailer Mails the confirmation to the new address.
	 * @param PortalOrganisationConfigService|null $orgConfig Whether the organisation offers the message box.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalSelfServiceService $selfService,
		private readonly PortalAccessRequestService $accessRequests,
		private readonly PortalIdentityMailer $mailer,
		private readonly ?PortalOrganisationConfigService $orgConfig = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Change the details of the bearer's own account.
	 *
	 * @param string $displayName A new name, or ''.
	 * @param string $email A new address, or ''.
	 * @param bool|null $emailNotifications The account's own opt-in/opt-out
	 *                                      for the email channel, or null
	 *                                      to leave it unchanged
	 *                                      (notification-preferences-per-role).
	 * @param string|null $messageLanguage The language school messages are
	 *                                     shown in, '' for as written, or
	 *                                     null to leave it
	 *                                     (translated-message-notice).
	 *
	 * @return JSONResponse Whether the change landed, whether a
	 *                      confirmation is now waiting, and whether its
	 *                      mail left.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T01
	 * @spec openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function updateDetails(
		string $displayName = '',
		string $email = '',
		?bool $emailNotifications = null,
		?string $messageLanguage = null,
	): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$updated = $this->selfService->updateDetails(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			displayName: $displayName,
			email: $email,
			emailNotifications: $emailNotifications,
			messageLanguage: $messageLanguage
		);
		if ($updated === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		$token = (string)($updated['confirmationToken'] ?? '');
		if ($token === '') {
			return new JSONResponse(['updated' => true, 'confirmationPending' => false]);
		}

		// The confirmation secret goes to the NEW address by mail; the answer
		// only says one is waiting and whether the mail left, so the old
		// session cannot read it. A mail that did not leave can be asked for
		// again by saving the address once more.
		$sent = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION,
			email: $email,
			secret: $token,
			organisation: (string)($subject['organisation'] ?? '')
		);

		return new JSONResponse(['updated' => true, 'confirmationPending' => true, 'confirmationSent' => $sent]);
	}//end updateDetails()

	/**
	 * The bearer's own notification choices (REQ-NAP-007, REQ-NAP-008).
	 *
	 * @return JSONResponse `{preferences, pushAvailable, messageBox}`, 401 without a session, 404 without an account.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function notificationPreferences(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$preferences = $this->selfService->notificationPreferences(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($preferences === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($preferences + ['messageBox' => $this->messageBoxOffer(subject: $subject)]);
	}//end notificationPreferences()

	/**
	 * Change the bearer's own notification choices. The account is the
	 * session's, never one the body names.
	 *
	 * @param array<string, mixed> $preferences Kind to `{email, push}` booleans.
	 *
	 * @return JSONResponse The saved choices, 401 without a session, 400 when refused.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function updateNotificationPreferences(array $preferences = []): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$saved = $this->selfService->updateNotificationPreferences(subjectRef: (string)($subject['subjectRef'] ?? ''), asked: $preferences);
		if ($saved === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($saved + ['messageBox' => $this->messageBoxOffer(subject: $subject)]);
	}//end updateNotificationPreferences()

	/**
	 * The government message box the caller's organisation offers, as the
	 * label residents read, or null (inbox-berichtenbox-channel, REQ-MBC-001).
	 * The integriq source it sends over stays on the server.
	 *
	 * @param array<string, mixed> $subject The caller.
	 *
	 * @return array{label: string}|null
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
	 */
	private function messageBoxOffer(array $subject): ?array {
		$offer = $this->orgConfig?->messageBox(orgSlug: (string)($subject['organisation'] ?? ''));
		if ($offer === null) {
			return null;
		}

		return ['label' => $offer['label']];
	}//end messageBoxOffer()

	/**
	 * The bearer's own details: name, address, email channel and message
	 * language. Never another account's; the subject comes from the bearer.
	 *
	 * @return JSONResponse The details, 401 without a subject, 404 without an account.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function details(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$details = $this->selfService->details(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($details === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($details);
	}//end details()

	/**
	 * Confirm a new address through its link.
	 *
	 * The bearer is optional here: without one the address is confirmed and
	 * nothing more happens.
	 *
	 * @param string $token The secret from the confirmation mail.
	 *
	 * @return JSONResponse Whether the address is now in use.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function confirmEmail(string $token): JSONResponse {
		// The link itself needs no session. A session, when the page has
		// one, decides whether a waiting account may join: only the account
		// holder's own (security review H1).
		$confirmed = $this->selfService->confirmEmail(token: $token, session: $this->subject());
		if ($confirmed === null) {
			return new JSONResponse(['error' => 'link_not_valid'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['confirmed' => true]);
	}//end confirmEmail()

	/**
	 * Ask for the bearer's own account to be removed.
	 *
	 * @return JSONResponse Whether the account is gone.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function removeAccount(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$removed = $this->selfService->removeAccount(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($removed === false) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['removed' => true]);
	}//end removeAccount()

	/**
	 * Ask for access the bearer does not have.
	 *
	 * @param string $onBehalfOf The party whose cases are asked for.
	 * @param string $reason What the asker needs it for.
	 *
	 * @return JSONResponse The recorded request, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestAccess(string $onBehalfOf = '', string $reason = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$recorded = $this->accessRequests->request(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			onBehalfOf: $onBehalfOf,
			reason: $reason
		);
		if ($recorded === null) {
			return new JSONResponse(['error' => 'reason_required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['state' => 'pending']);
	}//end requestAccess()

	/**
	 * The requests the bearer has made, with the answers they were given.
	 *
	 * @return JSONResponse The asker's own requests.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function myAccessRequests(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['requests' => $this->accessRequests->madeBy(subjectRef: (string)($subject['subjectRef'] ?? ''))]);
	}//end myAccessRequests()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
