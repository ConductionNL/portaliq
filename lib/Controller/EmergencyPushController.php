<?php

/**
 * Emergency Push Controller
 *
 * Staff-only broadcast that bypasses every recipient's quiet hours
 * (noodmelding, PA-new-1). Every send is logged with the sending staff
 * member's own subjectRef and the resolved recipient count, mirroring this
 * app's `AuditTrailService` convention — an emergency broadcast is
 * attributable, not anonymous.
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
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
class EmergencyPushController extends Controller {
	/**
	 * The ADR-023 action a staff member needs to send a noodmelding.
	 */
	public const ACTION = 'portal.send-emergency-push';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Resolves the calling staff member's Nextcloud user id.
	 * @param GuardianAudienceFixtureReader $audienceReader Resolves guardians matching the target.
	 * @param PushDeliveryService $delivery Delivers the emergency push, bypassing quiet hours.
	 * @param LoggerInterface $logger The logger.
	 * @param ActionAuthService $actionAuth Decides whether this staff user may send an emergency push (ADR-023).
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly PushDeliveryService $delivery,
		private readonly LoggerInterface $logger,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Broadcast an emergency push to every guardian matching a target.
	 *
	 * @param array<string, mixed> $target `{schoolRef?, groupRefs?, childRefs?}`.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 *
	 * @return JSONResponse `{recipientCount, deliveredCount}`: whom the target
	 *                      reaches, and how many of those a push really reached
	 *                      (0 while only the interim logging transport is bound),
	 *                      or 401 / 403 before anything is sent.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
	 */
	#[NoAdminRequired]
	public function send(array $target, string $title, string $body): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$staffRef = $user->getUID();
		$recipients = $this->audienceReader->guardiansMatching(target: $target);

		$delivered = 0;
		foreach ($recipients as $guardianRef) {
			// One failing delivery must not stop the noodmelding to the rest.
			try {
				$sent = $this->delivery->deliver(subjectRef: $guardianRef, title: $title, body: $body, emergency: true);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: emergency push delivery failed', ['reason' => $e->getMessage()]);
				$sent = false;
			}

			if ($sent === true) {
				$delivered++;
			}
		}

		$context = [
			'sentBy' => $staffRef,
			'recipientCount' => count($recipients),
			'deliveredCount' => $delivered,
		];
		$answer = new JSONResponse(['recipientCount' => count($recipients), 'deliveredCount' => $delivered]);
		if ($delivered < count($recipients)) {
			// Never "sent" for a push that did not arrive: with the interim
			// logging transport nothing does.
			$this->logger->warning('Portaliq: emergency push not delivered to every recipient', $context);
			return $answer;
		}

		$this->logger->info('Portaliq: emergency push sent', $context);
		return $answer;
	}//end send()
}//end class
