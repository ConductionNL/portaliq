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
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
class EmergencyPushController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Resolves the calling staff member's Nextcloud user id.
	 * @param GuardianAudienceFixtureReader $audienceReader Resolves guardians matching the target.
	 * @param PushDeliveryService $delivery Delivers the emergency push, bypassing quiet hours.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly PushDeliveryService $delivery,
		private readonly LoggerInterface $logger,
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
	 * @return JSONResponse `{recipientCount}`.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
	 */
	#[NoAdminRequired]
	public function send(array $target, string $title, string $body): JSONResponse {
		$staffRef = (string)($this->userSession->getUser()?->getUID() ?? '');
		$recipients = $this->audienceReader->guardiansMatching(target: $target);

		foreach ($recipients as $guardianRef) {
			$this->delivery->deliver(subjectRef: $guardianRef, title: $title, body: $body, emergency: true);
		}

		$this->logger->info('Portaliq: emergency push sent', [
			'sentBy' => $staffRef,
			'recipientCount' => count($recipients),
		]);

		return new JSONResponse(['recipientCount' => count($recipients)]);
	}//end send()
}//end class
