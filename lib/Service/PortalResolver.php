<?php

/**
 * Portaliq Portal Resolver
 *
 * Resolves the one `portal` a request is served from (ADR-086 §§2, 11).
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Resolves the serving portal for a request.
 *
 * TWO resolution modes, in this order:
 *
 *   1. An EXPLICIT site slug (`?portal=` / the `site` route parameter). This is
 *      the headless path — a Docusaurus build or any other consumer that is
 *      not reaching Portaliq over the site's own hostname still has to name
 *      the site it wants.
 *   2. The request HOST, matched against a portal's verified `domains[]`.
 *
 * There is deliberately NO third mode and no fallback. An unresolved host
 * returns null, and the caller turns that into a 404. A "first portal" or
 * "default portal" fallback is precisely how a multi-tenant host ends up
 * serving one tenant's content under another tenant's domain — the failure is
 * invisible from inside the request, because a page renders either way.
 *
 * A domain only resolves once `verified` is true. Without that, pointing DNS
 * at this installation would be enough for anyone to claim any hostname.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
 */
class PortalResolver {

	/**
	 * The register the CMS schemas live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param PublishedPortals $publishedPortals The published portals, read once per request.
	 * @param LoggerInterface  $logger           The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublishedPortals $publishedPortals,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Resolve the portal for a request.
	 *
	 * @param IRequest    $request  The incoming request.
	 * @param string|null $portalSlug An explicit site slug, when the caller named one.
	 *
	 * @return array|null The portal object, or null when nothing matched.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolve(IRequest $request, ?string $portalSlug = null): ?array {
		$sites = $this->allPublishedPortals();
		if ($sites === []) {
			return null;
		}

		if ($portalSlug !== null && $portalSlug !== '') {
			foreach ($sites as $site) {
				if (($site['slug'] ?? null) === $portalSlug) {
					return $site;
				}
			}

			// A named site that does not exist is a miss, NOT an invitation to
			// fall through to host matching — otherwise `?portal=typo` would
			// quietly serve whatever site owns the hostname.
			return null;
		}

		return $this->resolveByHost(host: $this->requestHost(request: $request), sites: $sites);
	}//end resolve()


	/**
	 * Resolve the portal for the traffic collector: HOST first, slug second.
	 *
	 * The reverse of `resolve()`, deliberately. A collector that honoured a
	 * named slug over the host would let any page attribute its events to
	 * any portal it cared to name. The slug is only consulted when the host
	 * resolves to nothing, which is the case for a `kind: external` portal
	 * on its own domain posting to the platform host.
	 *
	 * @param IRequest    $request    The incoming request.
	 * @param string|null $portalSlug The slug the caller named, if any.
	 *
	 * @return array|null The portal object, or null when nothing matched.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	public function resolveForCollector(IRequest $request, ?string $portalSlug = null): ?array {
		$sites = $this->allPublishedPortals();
		if ($sites === []) {
			return null;
		}

		$byHost = $this->resolveByHost(host: $this->requestHost(request: $request), sites: $sites);
		if ($byHost !== null) {
			return $byHost;
		}

		if ($portalSlug === null || $portalSlug === '') {
			return null;
		}

		foreach ($sites as $site) {
			if (($site['slug'] ?? null) === $portalSlug) {
				return $site;
			}
		}

		return null;
	}//end resolveForCollector()

	/**
	 * Resolve the portal an `?org=` value names — an ALIAS, never a key.
	 *
	 * `portal.organisation` is not unique and is not meant to be. The product
	 * decision of 2026-09-22 (WOO-566) is explicit: one organisation may run
	 * several portals that look different from each other. So the only answer
	 * this method can give honestly is the unambiguous one — EXACTLY ONE
	 * published portal carries the value.
	 *
	 * At zero matches the organisation is unknown here. At two or more the
	 * question "which branding belongs to this organisation" genuinely has no
	 * answer, and the tempting shortcut — take the first, or the newest —
	 * would serve one tenant's brand under another tenant's name. That failure
	 * is invisible from inside the request: the page renders, the colours are
	 * a real municipality's, and nothing looks wrong to anyone who is not
	 * already the wronged tenant.
	 *
	 * Both misses therefore return null and the caller falls back to the
	 * neutral default, which is the same posture `resolve()` takes for an
	 * unknown slug.
	 *
	 * @param string $organisation The `?org=` value.
	 *
	 * @return array|null The single matching portal, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolveByOrganisation(string $organisation): ?array {
		$organisation = trim($organisation);
		if ($organisation === '') {
			return null;
		}

		$matches = $this->portalsOf(organisation: $organisation);
		if (count($matches) === 1) {
			return $matches[0];
		}

		if ($matches !== []) {
			$this->logAmbiguous(organisation: $organisation, matches: $matches);
		}

		return null;
	}//end resolveByOrganisation()


	/**
	 * The published portals whose organisation is this one, trimmed.
	 *
	 * @param string $organisation The trimmed `?org=` value.
	 *
	 * @return array<int, array> The matching portals.
	 */
	private function portalsOf(string $organisation): array {
		$matches = [];
		foreach ($this->allPublishedPortals() as $site) {
			$value = ($site['organisation'] ?? null);
			if (is_string($value) === true && trim($value) === $organisation) {
				$matches[] = $site;
			}
		}

		return $matches;
	}//end portalsOf()


	/**
	 * Log an organisation that matched several portals.
	 *
	 * Worth a log line rather than silence: an ambiguous `?org=` is a
	 * deployment that has outgrown the alias, and the neutral default the
	 * visitor gets looks like a bug from the outside.
	 *
	 * @param string            $organisation The trimmed `?org=` value.
	 * @param array<int, array> $matches      The matching portals.
	 *
	 * @return void
	 */
	private function logAmbiguous(string $organisation, array $matches): void {
		$this->logger->info(
			'Portaliq: ?org= matched several published portals; refusing to guess a brand',
			[
				'organisation' => $organisation,
				'portals' => array_map(
					static function (array $site): string {
						return (string)($site['slug'] ?? '');
					},
					$matches
				),
			]
		);
	}//end logAmbiguous()


	/**
	 * Match a host against the verified domains of the published portals.
	 *
	 * @param string $host  The request host, lower-cased, without port.
	 * @param array  $sites The published portals.
	 *
	 * @return array|null The matching portal, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
	 */
	public function resolveByHost(string $host, array $sites): ?array {
		$match = $this->verifiedMatch(host: $host, sites: $sites);
		if ($match === null) {
			return null;
		}

		return $match['site'];
	}//end resolveByHost()


	/**
	 * The verified domain entry this request is served on, or null.
	 *
	 * A domain carries settings of its own (whether its host serves the site
	 * at its root, and the page its root opens). Those settings belong to the
	 * host, so they are read ONLY when the host itself decides the portal:
	 * the request host matches a verified domain, and the caller named either
	 * no portal or that same portal. A request that names another portal by
	 * slug is served as that portal and gets no domain, so the settings of
	 * one portal's host never shape another portal's pages.
	 *
	 * An unknown host and an unverified domain both answer null, exactly as
	 * they resolve to no portal.
	 *
	 * @param IRequest    $request    The incoming request.
	 * @param string|null $portalSlug The slug the caller named, if any.
	 *
	 * @return array<string, mixed>|null The domain entry, or null.
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function servingDomain(IRequest $request, ?string $portalSlug = null): ?array {
		$sites = $this->allPublishedPortals();
		if ($sites === []) {
			return null;
		}

		$match = $this->verifiedMatch(host: $this->requestHost(request: $request), sites: $sites);
		if ($match === null) {
			return null;
		}

		if ($portalSlug !== null && $portalSlug !== '' && ($match['site']['slug'] ?? null) !== $portalSlug) {
			return null;
		}

		return $match['domain'];
	}//end servingDomain()


	/**
	 * The portal and the verified domain entry a host matches, or null.
	 *
	 * @param string $host  The request host, lower-cased, without port.
	 * @param array  $sites The published portals.
	 *
	 * @return array{site: array<string, mixed>, domain: array<string, mixed>}|null
	 */
	private function verifiedMatch(string $host, array $sites): ?array {
		return (new PortalDomainMatch(logger: $this->logger))->find(host: $host, sites: $sites);
	}//end verifiedMatch()


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
	 * Every published portal.
	 *
	 * @return array The published portal objects.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	public function allPublishedPortals(): array {
		return $this->publishedPortals->allPublishedPortals();
	}//end allPublishedPortals()



	/**
	 * @param array<string, mixed>|null $portal The serving portal, or null when it could not be resolved.
	 * @param string                    $locale The visitor's language, never empty.
	 *
	 * @return string The language to serve.
	 *
	 * The visitor's language held against the languages the portal declares
	 * (`locales`, site-matches-the-zuiddrecht-boards). A portal that declares
	 * only `nl` serves `nl` to a browser that asks for English: the document
	 * language, and so every date the widgets print, follow the portal. A
	 * portal that declares nothing, or null, serves what the visitor asked for.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	public function localeFor(?array $portal, string $locale): string {
		$declared = $this->declaredLocales(portal: $portal);
		if ($declared === []) {
			return $locale;
		}

		$base = strtolower(substr($locale, 0, 2));
		foreach ($declared as $candidate) {
			if ($candidate === strtolower($locale) || substr($candidate, 0, 2) === $base) {
				return $locale;
			}
		}

		return $declared[0];
	}//end localeFor()


	/**
	 * @param array<string, mixed>|null $portal The portal.
	 *
	 * @return list<string> Its declared locales, lower case, blanks left out.
	 *
	 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-document-language-follows-the-portal
	 */
	private function declaredLocales(?array $portal): array {
		$declared = [];
		foreach ((array)($portal['locales'] ?? []) as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				$declared[] = strtolower(trim($candidate));
			}
		}

		return $declared;
	}//end declaredLocales()
}//end class
