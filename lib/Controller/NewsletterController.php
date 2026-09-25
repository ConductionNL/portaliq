<?php

/**
 * Newsletter Controller (staff authoring)
 *
 * Staff-side compose/preflight/send for `newsletter` objects
 * (news-and-newsletter-authoring, finding 9.2). Requires a Nextcloud
 * session, never a portal bearer.
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
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\NewsletterPreflightService;
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
 * Staff authoring for newsletters.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */
class NewsletterController extends Controller {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'newsletter';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param NewsletterPreflightService $preflight The recipient-count/refusal check.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly NewsletterPreflightService $preflight,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compose a draft newsletter from existing news items.
	 *
	 * @param string $title The title.
	 * @param array<int, string> $itemRefs Existing newsItem ids.
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 *
	 * @return JSONResponse The created draft, or 400/500.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-composes-existing-news-items-with-an-archive
	 */
	#[NoAdminRequired]
	public function create(string $title, array $itemRefs, array $target): JSONResponse {
		if ($title === '' || count($itemRefs) === 0) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$saved = $objectService->saveObject(
				object: [
					'title' => $title,
					'itemRefs' => $itemRefs,
					'target' => $target,
					'sentAt' => null,
					'archived' => false,
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: newsletter create failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->normalise(row: $saved));
	}//end create()

	/**
	 * Preview the exact recipient count for a newsletter's CURRENT target,
	 * without sending.
	 *
	 * @param string $id The newsletter id.
	 *
	 * @return JSONResponse `{recipientCount}`, or 404.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 */
	#[NoAdminRequired]
	public function preflight(string $id): JSONResponse {
		$newsletter = $this->fetch(id: $id);
		if ($newsletter === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$target = [];
		if (is_array($newsletter['target'] ?? null) === true) {
			$target = $newsletter['target'];
		}

		return new JSONResponse(['recipientCount' => $this->preflight->countRecipients(target: $target)]);
	}//end preflight()

	/**
	 * Send a newsletter. Refused when the resolved audience is empty — the
	 * IDENTICAL check the preflight reports, so a send can never surprise
	 * staff with a different count than what they previewed.
	 *
	 * @param string $id The newsletter id.
	 *
	 * @return JSONResponse The sent object, 422 `{"error":"empty-audience"}`, or 404.
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 */
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		$newsletter = $this->fetch(id: $id);
		if ($newsletter === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$target = [];
		if (is_array($newsletter['target'] ?? null) === true) {
			$target = $newsletter['target'];
		}

		if ($this->preflight->sendIsRefused(target: $target) === true) {
			return new JSONResponse(['error' => 'empty-audience'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$writer = new PortalObjectWriter(container: $this->container, logger: $this->logger);
		$updated = $writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['sentAt' => gmdate('c')]
		);

		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($updated);
	}//end send()

	/**
	 * Fetch one newsletter by id, unscoped (staff authoring, no subject).
	 *
	 * @param string $id The newsletter id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: newsletter fetch failed', ['reason' => $e->getMessage()]);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$row = $this->normalise(row: $entity);
		if ($row === []) {
			return null;
		}

		return $row;
	}//end fetch()

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
