<?php

/**
 * Portaliq Portal Runtime Config Resolver
 *
 * Maps the serving `portal` object onto the runtime config the public portal
 * SPA boots from (WOO-566).
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

/**
 * Builds the portal SPA's runtime config from the PORTAL OBJECT.
 *
 * WHY THIS EXISTS, in one paragraph.
 *
 * `/portal` and `/site` are two renderers of the same public page, and until
 * now they answered "whose page is this?" from two different places. `/site`
 * read the `portal` object; `/portal` read an OpenRegister Organisation plus
 * an `org_presentation_<uuid>` blob in IAppConfig that NOTHING in the fleet
 * has ever written — no endpoint, no admin UI, no occ command, no migration.
 * So every visitor to `/portal` got the neutral default, for every tenant,
 * forever, and the `title`/`theme`/`logo` an editor had filled in were read by
 * one renderer and ignored by the other.
 *
 * The product decision of 2026-09-22 settled which of the two is the source:
 * an organisation may run SEVERAL portals that look different from each other.
 * That does not merely favour the portal object, it rules the other one out —
 * `org_presentation_<uuid>` is keyed on the organisation and can hold exactly
 * one presentation.
 *
 * WHAT THIS DELIBERATELY DOES NOT TAKE OVER: the OIDC broker. Only the
 * PRESENTATION half of `PortalOrganisationConfigService` was superseded. The
 * broker config is a live spec requirement, and a client secret belongs to the
 * legal tenant rather than to a presentation layer — two portals of one
 * organisation share an identity provider even when they share no colours. So
 * the login buttons keep resolving off `?org=`, through the very same call
 * `PortalPageController::index()` made before this change.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
 */
class PortalRuntimeConfigResolver {


	/**
	 * Constructor.
	 *
	 * @param PortalResolver                  $portalResolver Resolves which portal serves this request.
	 * @param PortalOrganisationConfigService $orgResolver    Supplies the neutral default and the
	 *                                                        OIDC provider list.
	 * @param PortalThemeResolver             $themeResolver  Maps a portal's theme reference onto a
	 *                                                        real thematiq token stylesheet.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portalResolver,
		private readonly PortalOrganisationConfigService $orgResolver,
		private readonly PortalThemeResolver $themeResolver,
	) {
	}//end __construct()


	/**
	 * Which portal serves this request.
	 *
	 * THREE ways in, in this order, and the order is a security decision:
	 *
	 *   1. `?portal=<slug>` — the explicit, primary parameter, identical to
	 *      `/site`. A named portal that does not exist is a MISS, never an
	 *      invitation to fall through to something else; otherwise
	 *      `?portal=typo` would quietly serve whichever portal owns the
	 *      hostname.
	 *   2. `?org=<value>` — the alias kept for existing links (WOO-570's mail
	 *      deeplink builds one). Deterministic and strict: see
	 *      {@see PortalResolver::resolveByOrganisation()}.
	 *   3. Neither given — the request host, matched against VERIFIED domains.
	 *
	 * An explicitly named tenant that misses does NOT fall through to host
	 * matching. Naming a tenant and getting a different one is worse than
	 * naming a tenant and getting none.
	 *
	 * @param IRequest $request    The incoming request.
	 * @param string   $portalSlug The `?portal=` value (may be empty).
	 * @param string   $orgValue   The `?org=` value (may be empty).
	 *
	 * @return array|null The serving portal, or null when nothing resolved.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	public function resolvePortal(IRequest $request, string $portalSlug, string $orgValue): ?array {
		try {
			if (trim($portalSlug) !== '') {
				return $this->portalResolver->resolve(request: $request, portalSlug: trim($portalSlug));
			}

			if (trim($orgValue) !== '') {
				return $this->portalResolver->resolveByOrganisation(organisation: $orgValue);
			}

			return $this->portalResolver->resolve(request: $request);
		} catch (\Throwable) {
			// Fail CLOSED, exactly as `/site` does: an OpenRegister that is
			// down renders the neutral shell, never a guess at a tenant.
			return null;
		}
	}//end resolvePortal()


	/**
	 * The runtime config for a resolved portal (or for none).
	 *
	 * The NEUTRAL DEFAULT IS THE FLOOR, always. It is fetched from
	 * `PortalOrganisationConfigService::resolve('')`, whose documented
	 * behaviour for an empty slug is exactly that: the complete, tenant-free
	 * config shape with the visitor's locale applied. Every branch below can
	 * only overwrite individual keys of it, so the shape the SPA receives
	 * cannot go missing a field no matter which path produced it.
	 *
	 * NOTE ON WHAT IS **NOT** LAYERED IN: the old OpenRegister Organisation
	 * lookup. It is gone from the branding path rather than kept as a
	 * fallback beneath the portal, and that was a deliberate call rather than
	 * an oversight — an org-wide code search found two references to
	 * `org_presentation_` in the entire fleet, both inside this app (the
	 * reader and one e2e spec), and no writer anywhere. A fallback layer that
	 * can never find anything is not a safety net, it is code that has to be
	 * read, tested and maintained forever to do nothing.
	 *
	 * @param array|null $portal   The serving portal, or null.
	 * @param string     $orgValue The `?org=` value, for the OIDC lookup.
	 * @param string     $locale   The visitor's resolved locale.
	 *
	 * @return array<string, mixed> The SPA runtime config.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function runtimeConfigFor(?array $portal, string $orgValue, string $locale): array {
		$config = $this->orgResolver->resolve(orgSlug: '', locale: $locale);

		// The login buttons still belong to the ORGANISATION, not to the
		// presentation. This is deliberately the IDENTICAL call `index()` made
		// before this change — same service, same slug, same lookup — so that
		// no broker deployment moves and the diff for a reviewer is "the
		// branding keys stopped coming from here", not "OIDC was rewritten
		// too". Only `oidcProviders` is taken from the result; every branding
		// key it carries is discarded on purpose, because an organisation can
		// no longer answer "what does this portal look like".
		$orgValue = trim($orgValue);
		if ($orgValue !== '') {
			$resolved = $this->orgResolver->resolve(orgSlug: $orgValue, locale: $locale);
			$config['oidcProviders'] = (array)($resolved['oidcProviders'] ?? []);
		}

		if ($portal === null) {
			return $config;
		}

		return $this->applyPortalBranding(config: $config, portal: $portal);
	}//end runtimeConfigFor()


	/**
	 * The thematiq token stylesheet for a resolved portal, or ''.
	 *
	 * Same fail-quiet posture as `/site`: no portal, no theme, theme app
	 * absent or theme file missing all return '' and the page renders
	 * UNSTYLED rather than in whichever brand happened to be first. A portal
	 * quietly wearing another municipality's colours looks correct in every
	 * screenshot; an unstyled one gets reported within the hour.
	 *
	 * @param array|null $portal The serving portal, or null.
	 *
	 * @return string The stylesheet path relative to the theme app's `css/`, or ''.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function themeStylesheetFor(?array $portal): string {
		if ($portal === null) {
			return '';
		}

		try {
			return (string)$this->themeResolver->stylesheetFor(
				theme: trim((string)($portal['theme'] ?? ''))
			);
		} catch (\Throwable) {
			return '';
		}
	}//end themeStylesheetFor()


	/**
	 * Overlay a resolved portal's presentation onto the neutral default.
	 *
	 * @param array<string, mixed> $config The neutral-default config.
	 * @param array                $portal The resolved portal object.
	 *
	 * @return array<string, mixed> The config with this portal's presentation.
	 */
	private function applyPortalBranding(array $config, array $portal): array {
		// `title` -> `organisationName`. This is the visible half of the
		// change: the SPA header renders this string, and the schema says of
		// `title` that it "replaces the hard-coded literal 'Portaliq' every
		// tenant currently sees". A blank title leaves the default standing
		// rather than rendering an empty header.
		$title = trim((string)($portal['title'] ?? ''));
		if ($title !== '') {
			$config['organisationName'] = $title;
		}

		$config['organisationSlug'] = trim((string)($portal['slug'] ?? ''));

		// THE THEME IS ONLY REPORTED WHEN IT ACTUALLY RESOLVES, and that is
		// not pedantry. The SPA turns this value into a `theme-<name>` class
		// on its root element, so a theme reference that no token set backs
		// would put a plausible, brand-shaped class name on a page wearing
		// none of that brand's colours. Anyone verifying by reading the DOM
		// would see the right answer and the wrong page — which is precisely
		// the failure mode that kept the ORIGINAL bug hidden.
		if ($this->themeStylesheetFor(portal: $portal) !== '') {
			$config['theme'] = trim((string)($portal['theme'] ?? ''));
		}

		// The portal's own logo, when it declares one. The SPA shell does not
		// render an <img> for it today (the brand arrives through the token
		// set's `--nldesign-logo-url` instead), but it is part of the
		// documented config shape and a consumer reading the initial state
		// should get the portal's value rather than a stale null. Only an
		// http(s) or root-relative URL gets through: the first consumer to
		// bind this to `src` or `href` must not inherit a `javascript:` URL
		// an editor typed into a free-text field.
		$logo = $this->logoUrl(portal: $portal);
		if ($logo !== '') {
			$config['logo'] = $logo;
		}

		// `frameAncestors[]` -> the CSP the controller builds. Before this,
		// the value came from `allowedEmbedOrigins` in an override nothing
		// ever set, so it was the empty list on every request and every
		// tenant got `frame-ancestors 'none'`. Fail-closed behaviour is
		// UNCHANGED: an absent, malformed or empty list is still the empty
		// list, and the controller still clears the `'self'` default first.
		$config['allowedEmbedOrigins'] = $this->frameAncestors(portal: $portal);

		return $config;
	}//end applyPortalBranding()


	/**
	 * The portal's declared frame ancestors, as CSP source expressions.
	 *
	 * These strings go into the `frame-ancestors` directive VERBATIM:
	 * Nextcloud's policy builder joins them with a space and escapes nothing.
	 * Before this change the list came from an override nothing ever wrote;
	 * now it comes from a field any portal editor can fill. So every entry is
	 * parsed and rebuilt as `scheme://host[:port]`, and anything that does
	 * not survive that is DROPPED, not passed through:
	 *
	 *   - `*` and keywords (`'self'`, `'none'`) — a bare wildcard is the
	 *     "any origin may iframe a portal carrying a bearer token" exposure
	 *     the schema's own description says this field replaces;
	 *   - anything with a `;`, a comma or whitespace inside — one such value
	 *     ends the directive and starts a new one (`https://a.example;
	 *     script-src *` would add a script policy to the portal page);
	 *   - a path, query, fragment or credentials, and any scheme but http(s).
	 *
	 * A leading `*.` on the host stays allowed: `https://*.gemeente.nl` is a
	 * legitimate CSP source and names one organisation's subdomains, not the
	 * world.
	 *
	 * @param array $portal The resolved portal object.
	 *
	 * @return array<int, string> The origins, possibly empty.
	 */
	private function frameAncestors(array $portal): array {
		$raw = ($portal['frameAncestors'] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$origins = [];
		foreach ($raw as $origin) {
			if (is_string($origin) === false) {
				continue;
			}

			$origin = $this->cspOrigin(origin: $origin);
			if ($origin === '') {
				continue;
			}

			$origins[] = $origin;
		}

		return array_values(array_unique($origins));
	}//end frameAncestors()


	/**
	 * One frame ancestor rebuilt as `scheme://host[:port]`, or '' to drop it.
	 *
	 * @param string $origin The value as the portal object carries it.
	 *
	 * @return string The normalised origin, or '' when it is not one.
	 */
	private function cspOrigin(string $origin): string {
		$origin = trim($origin);
		if ($origin === '' || preg_match('/[\s;,\'"]/', $origin) === 1) {
			return '';
		}

		$parts = parse_url($origin);
		if (is_array($parts) === false || $this->isBareOrigin(parts: $parts) === false) {
			return '';
		}

		$origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
		if (isset($parts['port']) === false) {
			return $origin;
		}

		return $origin.':'.(int)$parts['port'];
	}//end cspOrigin()


	/**
	 * Whether parsed URL parts describe an origin and nothing more.
	 *
	 * @param array<string, int|string> $parts What parse_url() returned.
	 *
	 * @return bool True for http(s), a DNS host (optionally `*.`-prefixed) and
	 *              at most a port.
	 */
	private function isBareOrigin(array $parts): bool {
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (in_array($scheme, ['https', 'http'], true) === false) {
			return false;
		}

		// A DNS name, optionally `*.`-prefixed. Rejects `*`, IPv6 literals
		// and anything parse_url() let through that CSP would read as more
		// than one token.
		$hostPattern = '/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';
		if (preg_match($hostPattern, strtolower((string)($parts['host'] ?? ''))) !== 1) {
			return false;
		}

		// An origin carries no credentials, path, query or fragment; a value
		// that does is not what the editor meant, so it does not frame.
		foreach (['user', 'pass', 'query', 'fragment'] as $part) {
			if (isset($parts[$part]) === true) {
				return false;
			}
		}

		return in_array(($parts['path'] ?? ''), ['', '/'], true);
	}//end isBareOrigin()


	/**
	 * The portal's logo URL, or '' when it is absent or not a safe URL.
	 *
	 * @param array $portal The resolved portal object.
	 *
	 * @return string An http(s) or root-relative URL, or ''.
	 */
	private function logoUrl(array $portal): string {
		$logo = ($portal['logo'] ?? '');
		if (is_string($logo) === false) {
			return '';
		}

		$logo = trim($logo);
		if (str_starts_with($logo, '/') === true && str_starts_with($logo, '//') === false) {
			return $logo;
		}

		$scheme = strtolower((string)parse_url($logo, PHP_URL_SCHEME));
		if (in_array($scheme, ['https', 'http'], true) === true) {
			return $logo;
		}

		return '';
	}//end logoUrl()


}//end class
