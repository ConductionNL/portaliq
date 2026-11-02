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
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\NewsletterPreflightService;
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
 * Staff authoring for newsletters.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- `IUserSession` is the
 * gate-7 (no-admin-idor) authorization guard every `#[NoAdminRequired]`
 * method now calls first; it is a security requirement, not incidental
 * coupling (see `ContributionController`'s identical suppression).
 */
class NewsletterController extends Controller {
	use ObjectServiceLookup;
	use StaffActionGuard;
	use ObjectArrayRow;
	use StaffRowWrite;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'newsletter';

	/**
	 * The ADR-023 action every staff method is gated by (compose, preflight and send a newsletter).
	 */
	public const ACTION = 'portal.send-newsletter';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Confirms an authenticated Nextcloud user reached this endpoint.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param NewsletterPreflightService $preflight The recipient-count/refusal check.
	 * @param LoggerInterface $logger The logger.
	 * @param ActionAuthService $actionAuth Decides whether this staff user may send newsletters (ADR-023).
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly NewsletterPreflightService $preflight,
		private readonly LoggerInterface $logger,
		private readonly ActionAuthService $actionAuth,
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
		$this->requireAuthenticatedStaff();

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
		$this->requireAuthenticatedStaff();

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
		$this->requireAuthenticatedStaff();

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

		return $this->writeRow(id: $id, data: ['sentAt' => gmdate('c')]);
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
}//end class
