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
 * @spec openspec/changes/archive/2026-09-28-events-and-signups/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCA\Portaliq\Service\PagedObjectReads;
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
 * @spec openspec/changes/archive/2026-09-28-events-and-signups/design.md#api-design
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
	use PagedObjectReads;

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
	 * Every event, for staff choosing one: its id, title, start and status,
	 * the latest start first. A news item names the event whose sign-up it
	 * carries (`eventRef`); this is the list staff pick it from.
	 *
	 * @return JSONResponse `{events: [{id, title, start, status}]}`, or 500 when the events cannot be read.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$this->requireAuthenticatedStaff();

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$rows = $this->readEveryPage(objectService: $objectService, register: self::REGISTER, schema: self::SCHEMA);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: event list failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($rows === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['events' => $this->eventChoices(rows: $rows)]);
	}//end index()

	/**
	 * The choices for a list of event rows: those with an id, latest start first.
	 *
	 * @param array<int, mixed> $rows The event rows as read.
	 *
	 * @return list<array{id: string, title: string, start: string, status: string}>
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	private function eventChoices(array $rows): array {
		$choices = [];
		foreach ($rows as $row) {
			$event = $this->normalise(row: $row);
			$id    = (string)($event['id'] ?? ($event['@self']['id'] ?? ($event['uuid'] ?? '')));
			if ($id === '') {
				continue;
			}

			$choices[] = [
				'id' => $id,
				'title' => (string)($event['title'] ?? ''),
				'start' => (string)($event['start'] ?? ''),
				'status' => (string)($event['status'] ?? ''),
			];
		}

		usort($choices, static fn (array $a, array $b): int => strcmp($b['start'], $a['start']));

		return $choices;
	}//end eventChoices()

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
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

		return $this->createRow(
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
			failure: 'Portaliq: event create failed'
		);
	}//end create()

	/**
	 * Publish an event.
	 *
	 * @param string $id The event id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
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
