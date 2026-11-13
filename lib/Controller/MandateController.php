<?php

/**
 * Portaliq Mandate Controller (site-mandates-the-represented-manage)
 *
 * The routes of the represented party and of a mandate's holder. The party
 * is read from the session and nowhere else; a row of another party answers
 * as a missing one.
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
 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d5-routes-and-checks
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalCaseTypeChoicesInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIdentityMailerInterface;
use OCA\Portaliq\Kernel\Contracts\PortalMandateAdminServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Manage who may act for the session's party, and stop a mandate held.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d5-routes-and-checks
 */
class MandateController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  The request.
	 * @param PortalSessionService        $session  Resolves the bearer.
	 * @param PortalMandateAdminServiceInterface   $mandates The mandate service.
	 * @param PortalIdentityMailerInterface        $mailer   Mails the invitation link to the invitee, never to the inviter.
	 * @param PortalAccountLookupInterface         $accounts The name and address of who holds a mandate, and of the inviter.
	 * @param PortalCaseTypeChoicesInterface       $choices  The case types a mandate may be narrowed to.
	 * @param PortalResolverInterface              $portals  The portal being visited.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each collaborator is one screen element of the Machtigingen board.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalMandateAdminServiceInterface $mandates,
		private readonly PortalIdentityMailerInterface $mailer,
		private readonly PortalAccountLookupInterface $accounts,
		private readonly PortalCaseTypeChoicesInterface $choices,
		private readonly PortalResolverInterface $portals,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every mandate and open invitation given on the session's party's behalf.
	 *
	 * @return JSONResponse `{party, items[]}`, 401 without a session, 403 when the session has no party of its own.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-represented-party-must-see-who-may-act-for-it-req-smr-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function given(): JSONResponse {
		[$subject, $party, $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		$items = $this->mandates->given(party: $party, organisation: (string)$subject['organisation']);

		return new JSONResponse(['party' => $party, 'items' => $items]);
	}//end given()

	/**
	 * The case types the visited portal serves, for "Alleen deze soorten
	 * zaken". Only a portal of the session's own tenant answers.
	 *
	 * @param string $portal The portal slug the site is served as.
	 *
	 * @return JSONResponse `{items: [{id, title}]}`, 401 or 403.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function caseTypes(string $portal = ''): JSONResponse {
		[$subject, , $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: trim($portal));
		if ($site === null || (string)($site['organisation'] ?? '') !== (string)$subject['organisation']) {
			return new JSONResponse(['items' => []]);
		}

		return new JSONResponse(['items' => $this->choices->forPortal(portal: (string)($site['slug'] ?? ''))]);
	}//end caseTypes()

	/**
	 * Invite an address to act for the party.
	 *
	 * @param string        $email     The address.
	 * @param array<string> $caseTypes The case types in scope; empty for all.
	 * @param string        $label     A short text about what they may do.
	 * @param string        $expiresAt A future day, or ''.
	 *
	 * @return JSONResponse `{sent: true}` once the link is mailed to the address, 400 naming the field, or 502 when the mail did not leave.
	 *                      The secret goes to the invitee's mailbox only, never back to the inviter's browser.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-party-must-invite-never-look-up-the-person-it-authorises-req-smr-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function invite(string $email = '', array $caseTypes = [], string $label = '', string $expiresAt = ''): JSONResponse {
		[$subject, $party, $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->mandates->invite(
			party: $party,
			organisation: (string)$subject['organisation'],
			invitedBy: (string)$subject['subjectRef'],
			terms: ['email' => $email, 'caseTypes' => $caseTypes, 'label' => $label, 'expiresAt' => $expiresAt]
		);
		if (is_string($result) === true) {
			return new JSONResponse(['error' => $result], Http::STATUS_BAD_REQUEST);
		}

		$inviter = $this->accounts->bySubjectRef(subjectRef: (string)$subject['subjectRef']);
		$sent    = $this->mailer->send(
			template: PortalIdentityMailerInterface::TEMPLATE_MANDATE_INVITATION,
			email: trim($email),
			secret: $result['token'],
			organisation: (string)$subject['organisation'],
			portal: null,
			details: ['inviter' => (string)($inviter['displayName'] ?? '')]
		);
		if ($sent === false) {
			return new JSONResponse(['error' => 'not_sent'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['sent' => true]);
	}//end invite()

	/**
	 * Accept an invitation as the signed-in account.
	 *
	 * @param string $token The secret from the mail.
	 *
	 * @return JSONResponse `{id, holder}`, 404 when the invitation admits nobody, 409 for the inviter.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-party-must-invite-never-look-up-the-person-it-authorises-req-smr-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function accept(string $token = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->mandates->accept(token: $token, subject: $subject);
		if ($result === PortalMandateAdminServiceInterface::OWN_INVITATION) {
			return new JSONResponse(['error' => $result], Http::STATUS_CONFLICT);
		}

		if (is_string($result) === true) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end accept()

	/**
	 * Withdraw an open invitation.
	 *
	 * @param string $id The invitation.
	 *
	 * @return JSONResponse 204, or 404.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function revokeInvitation(string $id): JSONResponse {
		[$subject, $party, $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->noContentOrMissing(result: $this->mandates->revokeInvitation(party: $party, organisation: (string)$subject['organisation'], id: $id));
	}//end revokeInvitation()

	/**
	 * Revoke a mandate given on the party's behalf.
	 *
	 * @param string $id The mandate.
	 *
	 * @return JSONResponse 204, or 404.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function revoke(string $id): JSONResponse {
		[$subject, $party, $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->noContentOrMissing(
			result: $this->mandates->revoke(party: $party, organisation: (string)$subject['organisation'], id: $id, actor: (string)$subject['subjectRef'])
		);
	}//end revoke()

	/**
	 * Set or move a mandate's end date.
	 *
	 * @param string $id        The mandate.
	 * @param string $expiresAt The new end day, in the future.
	 *
	 * @return JSONResponse 204, 400 for a day that is not in the future, 404, or 409 for a mandate that has ended.
	 *
	 * @contract exclude the refusals are pinned by PortalMandateAdminServiceTest::testAnEndedMandateCannotBeExtendedAndAPastDateIsRefused
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function expiry(string $id, string $expiresAt = ''): JSONResponse {
		[$subject, $party, $refusal] = $this->identify();
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->mandates->setExpiry(party: $party, organisation: (string)$subject['organisation'], id: $id, day: $expiresAt);
		if ($result === PortalMandateAdminServiceInterface::BAD_END_DATE) {
			return new JSONResponse(['error' => $result], Http::STATUS_BAD_REQUEST);
		}

		if ($result === PortalMandateAdminServiceInterface::GONE) {
			return new JSONResponse(['error' => $result], Http::STATUS_CONFLICT);
		}

		return $this->noContentOrMissing(result: $result);
	}//end expiry()

	/**
	 * The mandates the session holds.
	 *
	 * @return JSONResponse `{items[]}`, or 401.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function held(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$holders = (new MandateParties())->holdersOf(subject: $subject);

		return new JSONResponse(['items' => $this->mandates->held(holders: $holders, organisation: (string)($subject['organisation'] ?? ''))]);
	}//end held()

	/**
	 * Stop a mandate the session holds.
	 *
	 * @param string $id The mandate.
	 *
	 * @return JSONResponse 204, or 404.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function stop(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->noContentOrMissing(
			result: $this->mandates->stop(
				holders: (new MandateParties())->holdersOf(subject: $subject),
				organisation: (string)($subject['organisation'] ?? ''),
				id: $id,
				actor: (string)($subject['subjectRef'] ?? '')
			)
		);
	}//end stop()

	/**
	 * The session, its party, and the refusal when there is none.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string, 2: JSONResponse|null}
	 */
	private function identify(): array {
		$subject = $this->subject();
		if ($subject === null) {
			return [null, '', new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED)];
		}

		// A session acting under a mandate carries `mandate`: it manages nothing
		// on the represented party's behalf (design D1).
		$party = (new MandateParties())->partyOf(subject: $subject);
		if ($party === null || (string)($subject['organisation'] ?? '') === '' || ($subject['actingUnderMandate'] ?? false) === true) {
			return [$subject, '', new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN)];
		}

		return [$subject, $party, null];
	}//end identify()

	/**
	 * @param string $result '' for done, else a refusal code.
	 *
	 * @return JSONResponse
	 */
	private function noContentOrMissing(string $result): JSONResponse {
		if ($result === '') {
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		}

		return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
	}//end noContentOrMissing()

	/**
	 * @return array<string, mixed>|null The session subject.
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
