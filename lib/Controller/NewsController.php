<?php

/**
 * News Controller (staff authoring)
 *
 * Staff-side create/update/publish for `newsItem` objects
 * (news-and-newsletter-authoring, finding 9.1). Requires a Nextcloud session
 * — the same posture `CmsEditorController`/`portal-cms-admin-ui` already use
 * for staff content authoring — never a portal bearer.
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Staff authoring for news items.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */
class NewsController extends Controller {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'newsItem';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Confirms an authenticated Nextcloud user reached this endpoint.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The staff authorization guard every `#[NoAdminRequired]` method calls
	 * FIRST, before any read or write: `#[NoAdminRequired]` already opens
	 * this endpoint to every authenticated Nextcloud user, so this makes the
	 * requirement explicit at the call site (ADR-005) rather than relying
	 * only on the framework attribute, and gives future role-narrowing (e.g.
	 * a dedicated staff group) exactly one place to land.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When no Nextcloud user is authenticated.
	 */
	private function requireAuthenticatedStaff(): void {
		if ($this->userSession->getUser() === null) {
			throw new OCSForbiddenException('Authentication required');
		}
	}//end requireAuthenticatedStaff()

	/**
	 * Create a draft news item.
	 *
	 * @param string $title The title.
	 * @param string $body The body.
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param string $authorRef The authoring staff subjectRef.
	 * @param array<int, string> $photoRefs Attached photo references.
	 *
	 * @return JSONResponse The created object, or a 400/500.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	#[NoAdminRequired]
	public function create(string $title, string $body, array $target, string $authorRef, array $photoRefs = []): JSONResponse {
		$this->requireAuthenticatedStaff();

		if ($title === '' || $body === '' || $this->hasAnyTarget(target: $target) === false) {
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
					'body' => $body,
					'target' => $target,
					'authorRef' => $authorRef,
					'status' => 'draft',
					'photoRefs' => $photoRefs,
					'readReceipts' => [],
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: news item create failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->normalise(row: $saved));
	}//end create()

	/**
	 * Publish a news item.
	 *
	 * @param string $id The news item id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->setStatus(id: $id, status: 'published');
	}//end publish()

	/**
	 * Revert a news item to draft.
	 *
	 * @param string $id The news item id.
	 *
	 * @return JSONResponse The updated object, or 404.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	#[NoAdminRequired]
	public function unpublish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->setStatus(id: $id, status: 'draft');
	}//end unpublish()

	/**
	 * Set a news item's status, preserving everything else.
	 *
	 * @param string $id The news item id.
	 * @param string $status The new status.
	 *
	 * @return JSONResponse
	 */
	private function setStatus(string $id, string $status): JSONResponse {
		if ($id === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$writer = new PortalObjectWriter(container: $this->container, logger: $this->logger);
		$updated = $writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['status' => $status]
		);

		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($updated);
	}//end setStatus()

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
