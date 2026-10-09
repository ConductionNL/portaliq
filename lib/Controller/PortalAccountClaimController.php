<?php

/**
 * Portaliq Portal Account Claim Controller
 *
 * The signed-in person hands back the one-time secret of an invitation, and
 * the waiting account behind it joins the account they signed in with.
 *
 * The account that receives is always the bearer's own. The session must be
 * at trust level substantial or higher: a waiting account can carry an
 * app's claim on a child or a case, and that is not handed to a session
 * that proved less. Wrong, expired and already used are one answer.
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
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redeems an invitation's secret for the bearer.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountClaimController extends Controller implements PortalProtected {
	/**
	 * The trust a session needs before a waiting account may join it.
	 */
	public const MIN_TRUST = 'substantial';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param WaitingAccountInvitation $invitations Redeems the secret.
	 * @param LoggerInterface $logger Records a failure's cause, never the secret.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly WaitingAccountInvitation $invitations,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Redeem the secret of an invitation for the bearer's own account.
	 *
	 * @param string $secret The secret from the invitation.
	 *
	 * @return JSONResponse 200 when the waiting account joined; 401 without
	 *                      a session; 403 `trust_too_low` below substantial;
	 *                      403 `account_cannot_receive` when the bearer's own
	 *                      account cannot take over an account (about the
	 *                      caller, never the invitation); 429 after too many
	 *                      wrong secrets; 409 `invitation_conflict` when the
	 *                      invitation carries a claim the account holds with
	 *                      another value; 503 `try_again` when the request
	 *                      could not finish; and one 403 `invitation_not_valid`
	 *                      for wrong, expired and used.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function redeem(#[\SensitiveParameter] string $secret = ''): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		// About the session, not the secret: it is checked before the secret
		// is looked at, so it tells the caller nothing about any invitation.
		if ($this->session::trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: self::MIN_TRUST) === false) {
			return new JSONResponse(['error' => 'trust_too_low'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->invitations->redeem(subject: $subject, secret: $secret);
		} catch (Throwable $exception) {
			// The class only: a message or a trace could carry the secret.
			$this->logger->error('Portal invitation redeem failed', ['exception' => get_class($exception)]);
			$result = WaitingAccountInvitation::BUSY;
		}

		if ($result === WaitingAccountInvitation::CLAIMED) {
			return new JSONResponse($this->claimed(subject: $subject));
		}

		return $this->answer(result: $result);
	}//end redeem()

	/**
	 * The body of a successful redeem. When the redeem moved the account into
	 * the invitation's audience, the session is reissued for it and the new
	 * bearer goes back with the answer, so the pages of that audience open
	 * without a second sign-in. A reissue that fails costs nothing: the next
	 * sign-in carries the account's audience.
	 *
	 * @param array<string, mixed> $subject The session.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	private function claimed(array $subject): array {
		$body = ['claimed' => true];
		try {
			$audience = $this->invitations->audienceOf(subject: $subject);
			if ($audience === '' || $audience === (string)($subject['audience'] ?? '')) {
				return $body;
			}

			$issued = $this->session->reissueForAudience(authorizationHeader: $this->request->getHeader('Authorization'), audience: $audience);
		} catch (Throwable $exception) {
			$this->logger->warning('Portal session reissue after a claim failed', ['exception' => get_class($exception)]);
			return $body;
		}

		if ($issued === null) {
			return $body;
		}

		return $body + ['audience' => $audience, 'token' => $issued['token'], 'expiresAt' => $issued['expiresAt']];
	}//end claimed()

	/**
	 * The answer for what the redeem came to.
	 *
	 * @param string $result One of the WaitingAccountInvitation answers.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $result): JSONResponse {
		return match ($result) {
			WaitingAccountInvitation::CLAIMED => new JSONResponse(['claimed' => true]),
			WaitingAccountInvitation::LOCKED => new JSONResponse(['error' => 'too_many_attempts'], Http::STATUS_TOO_MANY_REQUESTS),
			WaitingAccountInvitation::CANNOT_RECEIVE => new JSONResponse(['error' => 'account_cannot_receive'], Http::STATUS_FORBIDDEN),
			WaitingAccountInvitation::CONFLICT => new JSONResponse(['error' => 'invitation_conflict'], Http::STATUS_CONFLICT),
			WaitingAccountInvitation::BUSY => new JSONResponse(['error' => 'try_again'], Http::STATUS_SERVICE_UNAVAILABLE),
			default => new JSONResponse(['error' => 'invitation_not_valid'], Http::STATUS_FORBIDDEN),
		};
	}//end answer()
}//end class
