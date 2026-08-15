<?php

/**
 * Portaliq Website Resolver
 *
 * Resolves the one `website` a request is served from (ADR-086 §§2, 11).
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-website-or-to-none
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the serving website for a request.
 *
 * TWO resolution modes, in this order:
 *
 *   1. An EXPLICIT site slug (`?site=` / the `site` route parameter). This is
 *      the headless path — a Docusaurus build or any other consumer that is
 *      not reaching Portaliq over the site's own hostname still has to name
 *      the site it wants.
 *   2. The request HOST, matched against a website's verified `domains[]`.
 *
 * There is deliberately NO third mode and no fallback. An unresolved host
 * returns null, and the caller turns that into a 404. A "first website" or
 * "default website" fallback is precisely how a multi-tenant host ends up
 * serving one tenant's content under another tenant's domain — the failure is
 * invisible from inside the request, because a page renders either way.
 *
 * A domain only resolves once `verified` is true. Without that, pointing DNS
 * at this installation would be enough for anyone to claim any hostname.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-website-or-to-none
 */
class WebsiteResolver {

	/**
	 * OpenRegister's ObjectService FQCN, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register the CMS schemas live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Resolve the website for a request.
	 *
	 * @param IRequest    $request  The incoming request.
	 * @param string|null $siteSlug An explicit site slug, when the caller named one.
	 *
	 * @return array|null The website object, or null when nothing matched.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-website-or-to-none
	 */
	public function resolve(IRequest $request, ?string $siteSlug = null): ?array {
		$sites = $this->allPublishedSites();
		if ($sites === []) {
			return null;
		}

		if ($siteSlug !== null && $siteSlug !== '') {
			foreach ($sites as $site) {
				if (($site['slug'] ?? null) === $siteSlug) {
					return $site;
				}
			}

			// A named site that does not exist is a miss, NOT an invitation to
			// fall through to host matching — otherwise `?site=typo` would
			// quietly serve whatever site owns the hostname.
			return null;
		}

		return $this->resolveByHost(host: $this->requestHost(request: $request), sites: $sites);
	}//end resolve()


	/**
	 * Match a host against the verified domains of the published websites.
	 *
	 * @param string $host  The request host, lower-cased, without port.
	 * @param array  $sites The published websites.
	 *
	 * @return array|null The matching website, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
	 */
	public function resolveByHost(string $host, array $sites): ?array {
		if ($host === '') {
			return null;
		}

		foreach ($sites as $site) {
			foreach (($site['domains'] ?? []) as $domain) {
				if (is_array($domain) === false) {
					continue;
				}

				$hostname = strtolower(trim((string)($domain['hostname'] ?? '')));
				if ($hostname === '' || $hostname !== $host) {
					continue;
				}

				// Unverified is treated exactly like unknown. This is the whole
				// point of verification: DNS pointing here is not consent.
				if (($domain['verified'] ?? false) !== true) {
					$this->logger->info(
						'Portaliq: host matched an UNVERIFIED domain; refusing to serve',
						['host' => $host, 'site' => $site['slug'] ?? null]
					);
					continue;
				}

				return $site;
			}
		}

		return null;
	}//end resolveByHost()


	/**
	 * The host this request is for, taken from the server-resolved value.
	 *
	 * `IRequest::getServerHost()` already honours the instance's trusted-proxy
	 * configuration, so a client-forged `Host` header cannot select a site on
	 * a correctly configured deployment. Reading `$_SERVER['HTTP_HOST']`
	 * directly here would hand site selection to the caller.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return string The lower-cased host, port stripped.
	 */
	private function requestHost(IRequest $request): string {
		$host = strtolower(trim($request->getServerHost()));
		$colon = strrpos($host, ':');
		if ($colon !== false && str_contains($host, ']') === false) {
			$host = substr($host, 0, $colon);
		}

		return $host;
	}//end requestHost()


	/**
	 * Every published website.
	 *
	 * @return array The published website objects.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	public function allPublishedSites(): array {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: 'website');
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
				'Portaliq: website read failed',
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

		return array_map(
			static function ($row) {
				if (is_array($row) === true) {
					return $row;
				}

				return (array)$row->jsonSerialize();
			},
			$rows
		);
	}//end allPublishedSites()


}//end class
