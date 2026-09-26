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
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Staff authoring for events.
 *
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */
class EventController extends Controller {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'event';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a draft event.
	 *
	 * @param string $title The title.
	 * @param string $start Start date-time (ISO 8601).
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param string $description Optional description.
	 * @param string $end Optional end date-time.
	 * @param bool $rsvpEnabled Whether guardians may RSVP.
	 * @param array<int, array<string, mixed>> $signupRoles Optional volunteer/material roles.
	 * @param string $authorRef The authoring staff subjectRef.
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
		string $authorRef = '',
	): JSONResponse {
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
		if ($id === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$writer = new PortalObjectWriter(container: $this->container, logger: $this->logger);
		$updated = $writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['status' => 'published']
		);

		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($updated);
	}//end publish()

	/**
	 * Whether a target names at least one dimension.
	 *
	 * @param array<string, mixed> $target The target.
	 *
	 * @return bool
	 */
	private function hasAnyTarget(array $target): bool {
		if (is_string($target['schoolRef'] ?? null) === true && $target['schoolRef'] !== '') {
			return true;
		}

		if (is_array($target['groupRefs'] ?? null) === true && count($target['groupRefs']) > 0) {
			return true;
		}

		if (is_array($target['childRefs'] ?? null) === true && count($target['childRefs']) > 0) {
			return true;
		}

		return false;
	}//end hasAnyTarget()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
