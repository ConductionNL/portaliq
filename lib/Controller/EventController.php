<?php

/**
 * Event Controller (staff authoring)
 *
 * Staff-side create/publish for `event` objects (events-and-signups,
 * findings 9.6, 9.8). Requires a Nextcloud session, same posture as
 * `NewsController`/`CmsEditorController`.
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
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Staff authoring for events.
 *
 * @spec openspec/changes/events-and-signups/design.md#api-design
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- `IUserSession` and
 * `ActionAuthService` are the authorization guard every `#[NoAdminRequired]`
 * method calls first (portal.manage-event); a security requirement, not
 * incidental coupling (see NewsController).
 */
class EventController extends Controller {
	use ObjectServiceLookup;
	use StaffActionGuard;
	use ObjectArrayRow;
	use TargetedAudience;
	use StaffRowWrite;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'schoolEvent';

	/**
	 * The ADR-023 action every staff method is gated by (create and publish an event).
	 */
	public const ACTION = 'portal.manage-event';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Confirms an authenticated Nextcloud user reached this endpoint.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param ActionAuthService $actionAuth Decides whether this staff user may author events (ADR-023).
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ActionAuthService $actionAuth,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a draft event. The author is the signed-in staff member, never
	 * a value from the request.
	 *
	 * @param string $title The title.
	 * @param string $start Start date-time (ISO 8601).
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param string $description Optional description.
	 * @param string $end Optional end date-time.
	 * @param bool $rsvpEnabled Whether guardians may RSVP.
	 * @param array<int, array<string, mixed>> $signupRoles Optional volunteer/material roles.
	 *
	 * @return JSONResponse The created object, or 400/500.
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `rsvpEnabled` is a
	 * request-bound field stored verbatim on the created object, not a
	 * behaviour-mode switch on this method.
	 */
	#[NoAdminRequired]
	public function create(
		string $title,
		string $start,
		array $target,
		string $description = '',
		string $end = '',
		bool $rsvpEnabled = false,
		array $signupRoles = [],
	): JSONResponse {
		$authorRef = $this->requireAuthenticatedStaff();

		if ($title === '' || $start === '' || $this->hasAnyTarget(target: $target) === false) {
			return new JSONResponse(['error' => 'invalid_target'], Http::STATUS_BAD_REQUEST);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$saved = $objectService->saveObject(
				object: [
					'title' => $title,
					'description' => $description,
					'start' => $start,
					'end' => $end,
					'target' => $target,
					'status' => 'draft',
					'rsvpEnabled' => $rsvpEnabled,
					'signupRoles' => $signupRoles,
					'authorRef' => $authorRef,
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: event create failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->normalise(row: $saved));
	}//end create()

	/**
	 * Publish an event.
	 *
	 * @param string $id The event id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		if ($id === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return $this->writeRow(id: $id, data: ['status' => 'published']);
	}//end publish()
}//end class
