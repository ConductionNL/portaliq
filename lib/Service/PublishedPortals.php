<?php

/**
 * Portaliq Published Portals
 *
 * Reads the published portals once per request (ADR-086 §§2, 11).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The published portals, read from OpenRegister and memoised for the request.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
 */
class PublishedPortals {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The published portals, read once per request.
	 *
	 * `allPublishedPortals()` is a `findAll(limit: 500)` against OpenRegister,
	 * and `site()` alone reaches it five times -- once for the resolved slug,
	 * the theme stylesheet, the theme logo, the NLDS stylesheet and the title
	 * (review of #516). The container hands out one instance per request, so
	 * memoising on the instance is request scope: a portal edited in one
	 * request is read fresh by the next.
	 *
	 * Null means "not read yet", which is NOT the same as the empty array a
	 * fail-closed read returns -- see `allPublishedPortals()`.
	 *
	 * @var array|null
	 */
	private ?array $publishedPortals = null;


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface       $logger    The logger.
	 * @param PortalRegisterContext $context   Points the shared ObjectService at this app's schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalRegisterContext $context,
	) {
	}//end __construct()


	/**
	 * Every published portal.
	 *
	 * Memoised for the life of this instance, i.e. for the request (review of
	 * #516): `site()` composes its page from five helpers that each resolve
	 * the portal themselves, and without this each one re-ran a
	 * `findAll(limit: 500)` against OpenRegister on every public page load.
	 *
	 * Only an ANSWERED read is memoised. The three `return []` paths are not:
	 * two of them are transient (OpenRegister threw, or the shared
	 * ObjectService could not be pointed at this app's schemas) and caching
	 * them would turn one unlucky read into a 404 for the whole request,
	 * which is a worse failure than the duplicate reads this removes.
	 *
	 * @return array The published portal objects.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function allPublishedPortals(): array {
		if ($this->publishedPortals !== null) {
			return $this->publishedPortals;
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			// Applied through the context helper rather than by two slug
			// setters, because the slug form inherits whatever schema ref an
			// unrelated app left pending on the shared service — which took
			// every public read of this portal down. See PortalRegisterContext.
			if ($this->context->apply(objectService: $objectService, schemaSlug: 'portal') === false) {
				return [];
			}

			// RBAC and multitenancy OFF: a public site is read by visitors who
			// are not Nextcloud users at all, so OR's user-based scoping would
			// filter every row out. The security boundary here is the
			// `status: published` filter plus per-domain verification, not OR's
			// user model.
			$rows = $objectService->findAll(
				config: ['filters' => ['status' => 'published'], 'limit' => 500, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: portal read failed',
				['app' => Application::APP_ID, 'reason' => $e->getMessage()]
			);
			// Fail CLOSED. An empty list means every request 404s, which is
			// visible immediately; returning "some site" on an OR error would
			// serve the wrong tenant silently.
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$this->publishedPortals = array_map(
			static function ($row) {
				if (is_array($row) === true) {
					return $row;
				}

				return (array)$row->jsonSerialize();
			},
			$rows
		);

		return $this->publishedPortals;
	}//end allPublishedPortals()
}//end class
