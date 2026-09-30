<?php

/**
 * Portaliq Portal Contact Address Controller
 *
 * The bearer's own e-mail addresses, phone numbers and contact channel
 * (identity-profile-page). Every route is scoped to the bearer's own account:
 * nothing takes an account identifier from the request, so naming somebody
 * else's account changes nothing.
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
 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\PortalContactAddressService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The bearer's own addresses and contact channel.
 *
 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md
 */
class PortalContactAddressController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalContactAddressService $addresses The address and channel rules.
	 * @param PortalIdentityMailer $mailer Mails the confirmation to a new address.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalContactAddressService $addresses,
		private readonly PortalIdentityMailer $mailer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Add an e-mail address or phone number. A new e-mail address gets its
	 * confirmation mail; the answer says only that it went, never the secret.
	 *
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return JSONResponse `{added, value, confirmationPending, confirmationSent}`,
	 *                      400 with a refusal, 401 without a session.
	 *
	 * @spec openspec/changes/identity-profile-page/tasks.md#T03
	 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md#requirement-a-new-e-mail-address-is-confirmed-before-it-is-used-req-ipp-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function add(string $kind = '', string $value = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$added = $this->addresses->addAddress(subjectRef: (string)($subject['subjectRef'] ?? ''), kind: $kind, value: $value);
		if ($added['refusal'] !== '') {
			return new JSONResponse(['error' => $added['refusal']], Http::STATUS_BAD_REQUEST);
		}

		if ($added['confirmationToken'] === '') {
			return new JSONResponse(['added' => true, 'value' => $added['value'], 'confirmationPending' => false]);
		}

		$sent = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION,
			email: $added['value'],
			secret: $added['confirmationToken'],
			organisation: (string)($subject['organisation'] ?? '')
		);

		return new JSONResponse(['added' => true, 'value' => $added['value'], 'confirmationPending' => true, 'confirmationSent' => $sent]);
	}//end add()

	/**
	 * Mark an address as the preferred one of its kind.
	 *
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return JSONResponse `{preferred: true}`, 400 with a refusal, 401 without a session.
	 *
	 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function prefer(string $kind = '', string $value = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(
			refusal: $this->addresses->preferAddress(subjectRef: (string)($subject['subjectRef'] ?? ''), kind: $kind, value: $value),
			key: 'preferred'
		);
	}//end prefer()

	/**
	 * Remove an address.
	 *
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return JSONResponse `{removed: true}`, 400 with a refusal, 401 without a session.
	 *
	 * @spec openspec/changes/identity-profile-page/tasks.md#T03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function remove(string $kind = '', string $value = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(
			refusal: $this->addresses->removeAddress(subjectRef: (string)($subject['subjectRef'] ?? ''), kind: $kind, value: $value),
			key: 'removed'
		);
	}//end remove()

	/**
	 * Choose how the organisation contacts the bearer.
	 *
	 * @param string $channel `portal`, `email`, `phone` or `post`.
	 *
	 * @return JSONResponse `{channel}`, 400 with a refusal, 401 without a session.
	 *
	 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md#requirement-you-choose-how-the-organisation-contacts-you-req-ipp-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function channel(string $channel = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->addresses->chooseChannel(subjectRef: (string)($subject['subjectRef'] ?? ''), channel: $channel);
		if ($refusal !== '') {
			return new JSONResponse(['error' => $refusal], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['channel' => $channel]);
	}//end channel()

	/**
	 * A plain answer: done, or refused with the reason.
	 *
	 * @param string $refusal '' when done.
	 * @param string $key The key that says it was done.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $refusal, string $key): JSONResponse {
		if ($refusal !== '') {
			return new JSONResponse(['error' => $refusal], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([$key => true]);
	}//end answer()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
