<?php

/**
 * Push Subscription Controller
 *
 * Guardian self-service: subscribe/unsubscribe a Web Push endpoint
 * (push-notifications-quiet-hours). Guarded by `PortalAuthMiddleware` via
 * the `PortalProtected` marker; the subject is read from the validated
 * bearer, never from a client parameter.
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */
class PushSubscriptionController extends Controller implements PortalProtected {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'pushSubscription';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Subscribe the calling guardian's push endpoint.
	 *
	 * @param string $endpoint The push endpoint URL.
	 * @param array<string, mixed> $keys `{p256dh, auth}`.
	 * @param string $deviceRef Optional device reference.
	 *
	 * @return JSONResponse 204 on success, 401 without a resolved subject.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function subscribe(string $endpoint, array $keys = [], string $deviceRef = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($endpoint === '') {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$existingId = $this->findExistingId(objectService: $objectService, subjectRef: $subjectRef, endpoint: $endpoint);

		try {
			$objectService->saveObject(
				object: [
					'subjectRef' => $subjectRef,
					'endpoint' => $endpoint,
					'keys' => $keys,
					'deviceRef' => $deviceRef,
					'createdAt' => gmdate('c'),
					'active' => true,
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: push subscription save failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end subscribe()

	/**
	 * Deactivate the calling guardian's own subscription for an endpoint.
	 * The row is kept (not deleted) so its history is auditable — only
	 * `active` flips to false.
	 *
	 * @param string $endpoint The push endpoint URL.
	 *
	 * @return JSONResponse 204 on success (including "was not subscribed"), 401 without a resolved subject.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function unsubscribe(string $endpoint): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$existingId = $this->findExistingId(objectService: $objectService, subjectRef: $subjectRef, endpoint: $endpoint);
		if ($existingId === null) {
			// Nothing to deactivate — the end state the caller wants is
			// already true, so this is a success, not a 404.
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		}

		try {
			$objectService->saveObject(
				object: ['subjectRef' => $subjectRef, 'endpoint' => $endpoint, 'active' => false],
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: push unsubscribe save failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end unsubscribe()

	/**
	 * The id of an existing subscription for this subject+endpoint, if any.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef The subject's own subjectRef.
	 * @param string $endpoint The push endpoint URL.
	 *
	 * @return string|null
	 */
	private function findExistingId(object $objectService, string $subjectRef, string $endpoint): ?string {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: push subscription lookup failed', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$matches = (string)($normalised['subjectRef'] ?? '') === $subjectRef && (string)($normalised['endpoint'] ?? '') === $endpoint;
			if ($matches === true) {
				return $this->rowId(row: $normalised);
			}
		}

		return null;
	}//end findExistingId()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return null;
	}//end rowId()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve the subject from the bearer (fail-closed).
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()

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
