<?php

/**
 * Portaliq Content Controller
 *
 * The headless content API (ADR-086 §1). Every renderer — including
 * Portaliq's own built-in site renderer — consumes this and nothing else.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Contribution\PortalContributionFilter;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTrafficService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public, read-only content endpoints.
 *
 * Every route is public — this is a public portal's content, and a Docusaurus
 * build or a third-party front-end has to be able to read it with no Nextcloud
 * session at all. That is what makes the CMS headless rather than a portal
 * with an API bolted on.
 *
 * WHAT IS DELIBERATELY THE SAME: a request for an unknown site, an unverified
 * domain, an unpublished page and a route that never existed all produce the
 * SAME 404 body. Distinguishing them would turn the API into an oracle for
 * unreleased content and for which tenants exist on this installation.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- PortalSessionService::trustSatisfies
 * is deliberately THE single trust comparator (contract-v2 design decision);
 * every re-check calls it statically so the ordering can never fork. Same
 * reasoning, and the same suppression, as ContributionController.
 */
class ContentController extends Controller {


	/**
	 * Constructor.
	 *
	 * @param string                     $appName      The app id.
	 * @param IRequest                   $request      The request.
	 * @param PortalResolver             $resolver     Resolves the serving portal.
	 * @param CmsReader                  $reader       Reads portal-scoped content.
	 * @param PortalContributionRegistry $registry     Aggregates leaf apps' contributions.
	 * @param PortalContributionFilter   $contribFilter Scopes that aggregate to one portal.
	 * @param LoggerInterface            $logger       Records a provider failure the visitor never sees.
	 * @param PortalSessionService       $session      Resolves the caller's portal session for the content gate.
	 * @param PortalTrafficService       $traffic      Owns what a portal's traffic configuration means.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly CmsReader $reader,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalContributionFilter $contribFilter,
		private readonly LoggerInterface $logger,
		private readonly PortalSessionService $session,
		private readonly PortalTrafficService $traffic,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Refuse a CONTENT read the resolved portal does not allow this caller.
	 *
	 * `portal.authentication.modes` was declared, documented and rendered — and
	 * enforced NOWHERE. Measured on the rig: a portal set to
	 * `modes: ['digid'], minTrust: 'substantial'` — no public mode at all —
	 * still served its menus, its page list and full page BODIES to an
	 * anonymous caller, all HTTP 200. Declaring an authentication mode did
	 * nothing whatsoever.
	 *
	 * THE DOOR IS PUBLIC, THE ROOMS ARE NOT. `site()` deliberately does not
	 * call this: a visitor to a DigiD-only portal has to be able to load its
	 * title, theme and — above all — its `authentication.modes`, or there is
	 * nothing to render a sign-in door from. That is the same reason a login
	 * page is anonymous. Everything that carries actual content is gated.
	 *
	 * Fails closed to public READ-ONLY when the block is absent or unparseable,
	 * which is what the schema documents: a portal that never configured
	 * authentication keeps behaving exactly as it does today, so this change
	 * cannot silently take an existing public site off the air.
	 *
	 * @param array $portal The resolved portal.
	 *
	 * @return JSONResponse|null A refusal, or null when the read may proceed.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	private function refuseUnlessPermitted(array $portal): ?JSONResponse {
		$auth  = (array)($portal['authentication'] ?? []);
		$modes = array_values(array_filter((array)($auth['modes'] ?? []), 'is_string'));

		// Absent or unparseable => public read-only (the schema's own words).
		if ($modes === [] || in_array('public', $modes, true) === true) {
			return null;
		}

		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			// 401, not 404. `site()` already tells an anonymous caller this
			// portal exists and how to sign in, so hiding it here would buy no
			// secrecy and would cost the renderer its sign-in door. The modes
			// are echoed for exactly that purpose — they are public by design.
			return $this->refusal(
				error: 'authentication_required',
				status: Http::STATUS_UNAUTHORIZED,
				modes: $modes
			);
		}

		if (PortalSessionService::trustSatisfies($subject['trust'] ?? null, ($auth['minTrust'] ?? null)) === false) {
			// A real session that is not assured ENOUGH. Distinct from 401 on
			// purpose: re-authenticating with the same means would loop, so the
			// caller has to be told the level is the problem.
			return $this->refusal(
				error: 'insufficient_trust',
				status: Http::STATUS_FORBIDDEN,
				modes: $modes
			);
		}

		return null;
	}//end refuseUnlessPermitted()


	/**
	 * The refusal shape both gated answers share.
	 *
	 * `private, no-store` is load-bearing rather than tidy: a refusal for a
	 * NON-public portal must never be poolable at a CDN, or the first
	 * anonymous miss becomes the answer every later visitor gets — including
	 * the ones who did sign in.
	 *
	 * @param string $error  The machine-readable reason.
	 * @param int    $status The HTTP status.
	 * @param array  $modes  The portal's declared modes, for the sign-in door.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(string $error, int $status, array $modes): JSONResponse {
		$response = new JSONResponse(
			[
				'error'          => $error,
				'authentication' => ['modes' => $modes],
			],
			$status
		);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end refusal()


	/**
	 * The resolved site's own presentation record.
	 *
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The site, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function site(?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$auth = (array)($portal['authentication'] ?? []);

		return $this->publicJson(
			payload: [
				'title'   => (string)($portal['title'] ?? ''),
				'slug'    => (string)($portal['slug'] ?? ''),
				'theme'   => (string)($portal['theme'] ?? ''),
				'logo'    => (string)($portal['logo'] ?? ''),
				// The line under the name in the footer's logo block. The
				// reference carries one ("Één plek voor alle publicaties van
				// Gemeente Tilburg") and this app had no field for it at all,
				// so every portal's footer showed a bare title.
				'tagline' => (string)($portal['tagline'] ?? ''),
				'locales' => array_values((array)($portal['locales'] ?? [])),
				'locale'  => $this->locale(portal: $portal, requested: $locale),
				// HOW MANY BARS THE HEADER HAS. `double` is the government
				// pattern this renderer was built against — a title bar above a
				// separate navigation bar. `single` is the one bar with the
				// logo, the navigation and the sign-in controls on one line,
				// which is what most product and documentation sites use.
				//
				// Projected here rather than inferred from the theme: two
				// portals can share a palette and disagree about their chrome,
				// and a renderer guessing structure from colour is a renderer
				// nobody can predict.
				'headerVariant' => $this->headerVariant(portal: $portal),
				// The footer's own content: the brand line under the wordmark,
				// the social links, the colophon and any certification badges.
				//
				// It is PORTAL CONFIGURATION, not a renderer constant. The
				// footer previously showed a bare title because there was
				// nowhere for a portal to say anything else — and a colophon
				// naming the wrong legal entity is the kind of thing a
				// government portal cannot ship.
				'footer' => $this->footer(portal: $portal),
				// The MODES are public — a visitor has to know how to sign in.
				// Provider secrets are not here and never will be; they live in
				// the credential broker.
				//
				// `register` joins them because a visitor without an account has
				// to know there is a way to get one. It is a DESTINATION the
				// portal declares, never derived from the modes: `nextcloud`
				// accounts are created by an administrator and DigiD accounts by
				// the state, so there is nothing to infer.
				'authentication' => [
					'modes'         => array_values((array)($auth['modes'] ?? ['public'])),
					'register'      => trim((string)($auth['register'] ?? '')),
					'registerLabel' => trim((string)($auth['registerLabel'] ?? '')),
				],
				// What this portal asks its visitors' browsers to measure. The
				// client sends only what it finds here, so a portal that has
				// not enabled measurement produces a client that does nothing
				// at all rather than one the collector silently refuses.
				'traffic' => $this->traffic->clientConfig(portal: $portal),
			]
		);
	}//end site()


	/**
	 * How many bars the portal's header has.
	 *
	 * Unknown values fall back to `double` rather than to the newer look: a
	 * portal that has never heard of this field must keep the chrome it has,
	 * and a typo must not silently restructure a live government site.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return string `single` or `double`.
	 *
	 * @spec openspec/changes/portal-page-composition/specs/portal-page-composition/spec.md#requirement-every-region-of-a-portal-page-must-be-composed-from-widgets
	 */
	private function headerVariant(array $portal): string {
		$variant = (string)($portal['headerVariant'] ?? '');
		if ($variant === 'single') {
			return 'single';
		}

		return 'double';
	}//end headerVariant()


	/**
	 * The footer's authored content, shaped and filtered.
	 *
	 * EVERY LIST IS REBUILT RATHER THAN PASSED THROUGH. This response is
	 * public and anonymous, so what reaches it is exactly the keys named here
	 * with exactly the types named here — a portal record that grows a field
	 * must not start publishing it because the footer happened to be nearby.
	 *
	 * Entries missing a label or a destination are DROPPED. An empty footer
	 * link is a control a visitor can focus and learn nothing from, and the
	 * renderer should not have to decide that a second time.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return array<string, mixed> The footer contract.
	 *
	 * @spec openspec/changes/portal-page-composition/specs/portal-page-composition/spec.md#requirement-every-region-of-a-portal-page-must-be-composed-from-widgets
	 */
	private function footer(array $portal): array {
		$footer = (array)($portal['footer'] ?? []);

		return [
			'description' => trim((string)($footer['description'] ?? '')),
			'colophon'    => trim((string)($footer['colophon'] ?? '')),
			// The decorative scene above the footer, by NAME. An unknown value
			// becomes '' rather than a default illustration: a typo must not put
			// somebody else's canal on a municipal portal.
			'decoration'  => $this->decoration(footer: $footer),
			'socials'     => $this->projectedList(
				entries: (array)($footer['socials'] ?? []),
				keys: ['label', 'href', 'icon'],
				requireAnyOf: ['label', 'href']
			),
			'legalLinks'  => $this->projectedList(
				entries: (array)($footer['legalLinks'] ?? []),
				keys: ['label', 'href'],
				requireAnyOf: ['label', 'href']
			),
			// A badge needs only ONE of its two halves — "ISO 27001:2022" reads
			// fine as a mark and a value, and a mark on its own is still a
			// claim somebody chose to make.
			'badges'      => $this->projectedList(
				entries: (array)($footer['badges'] ?? []),
				keys: ['mark', 'value', 'href'],
				requireAnyOf: []
			),
		];
	}//end footer()


	/**
	 * The footer's decorative scene, from a closed set of names.
	 *
	 * An ALLOW-LIST, for the same reason the widget map is one: this value
	 * chooses which illustration a public page mounts, and "whatever the record
	 * said" is not a decision anybody made.
	 *
	 * @param array<string, mixed> $footer The portal's footer block.
	 *
	 * @return string A known decoration name, or ''.
	 */
	private function decoration(array $footer): string {
		$known = ['canal'];
		$name  = trim((string)($footer['decoration'] ?? ''));

		if (in_array($name, $known, true) === true) {
			return $name;
		}

		return '';
	}//end decoration()


	/**
	 * Project a list of authored entries onto exactly the keys named.
	 *
	 * Extracted because the footer's three lists are the same operation, and
	 * three copies of "trim these keys, drop the incomplete ones" is three
	 * places for the filter to drift out of step with the contract.
	 *
	 * `requireAnyOf` names the keys that must ALL be non-empty for an entry to
	 * survive — every list needs some such rule, because an entry with a
	 * destination and no label is a control a visitor can focus and learn
	 * nothing from, and one with a label and no destination is a dead link. An
	 * empty list means only that the entry must not be blank altogether.
	 *
	 * @param array<int, mixed>    $entries      The authored entries.
	 * @param array<int, string>   $keys         The keys to keep, in order.
	 * @param array<int, string>   $requireAnyOf Keys that must all be non-empty.
	 *
	 * @return array<int, array<string, string>> The projected entries.
	 */
	private function projectedList(array $entries, array $keys, array $requireAnyOf): array {
		$out = [];

		foreach ($entries as $entry) {
			$entry     = (array)$entry;
			$projected = [];
			foreach ($keys as $key) {
				$projected[$key] = trim((string)($entry[$key] ?? ''));
			}

			$missing = false;
			foreach ($requireAnyOf as $key) {
				if (($projected[$key] ?? '') === '') {
					$missing = true;
				}
			}

			if ($missing === true || implode('', $projected) === '') {
				continue;
			}

			$out[] = $projected;
		}

		return $out;
	}//end projectedList()


	/**
	 * The site's menus.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The menus, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function menus(?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $portal);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publicJson(
			payload: [
				'menus' => $this->reader->menus(
					portal: (string)$portal['slug'],
					locale: $this->locale(portal: $portal, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end menus()


	/**
	 * The site's published pages, without their bodies.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The page summaries, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function pages(?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $portal);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publicJson(
			payload: [
				'pages' => $this->reader->pages(
					portal: (string)$portal['slug'],
					locale: $this->locale(portal: $portal, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end pages()


	/**
	 * One published page by route.
	 *
	 * @param string|null $route  The in-site route, leading slash optional.
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The page, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function page(?string $route = null, ?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $portal);
		if ($refusal !== null) {
			return $refusal;
		}

		$normalised = '/'.ltrim((string)($route ?? ''), '/');
		$page = $this->reader->page(
			portal: (string)$portal['slug'],
			route: $normalised,
			locale: $this->locale(portal: $portal, requested: $locale),
			audience: $this->audience()
		);

		if ($page === null) {
			// Identical to an unknown route on purpose — see the class docblock.
			return $this->notFound();
		}

		return $this->publicJson(payload: $page);
	}//end page()


	/**
	 * The site's glossary.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The terms, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function glossary(?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $portal);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publicJson(
			payload: [
				'terms' => $this->reader->glossary(
					portal: (string)$portal['slug'],
					locale: $this->locale(portal: $portal, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end glossary()


	/**
	 * The leaf apps' contributed surfaces for the resolved portal.
	 *
	 * THIS IS THE BRIDGE, AND IT IS DELIBERATELY ON THE PUBLIC CONTRACT.
	 * The built-in renderer could have reached `PortalContributionRegistry`
	 * directly — it runs inside Nextcloud and the service is right there. It
	 * does not, because the moment it does, the renderer can show something a
	 * Docusaurus build cannot, and "headless" becomes a claim rather than a
	 * property (ADR-084, ADR-086 §1). Every consumer sees the same surfaces.
	 *
	 * ANONYMOUS ONLY, AND THAT IS NOT A LIMITATION OF THIS ENDPOINT — it is
	 * the boundary. `aggregateAnonymous()` asks each provider only for what it
	 * publishes to callers with no identity. A visitor with a session reads
	 * their own aggregate through `/api/contributions`, which resolves a
	 * subject from the bearer and scopes every collection to it. Serving a
	 * subject-scoped aggregate from a PUBLICLY CACHEABLE endpoint is how one
	 * visitor's inbox ends up in a CDN slot, so the two never meet.
	 *
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 *
	 * @return JSONResponse `{contributions: []}`, or the shared 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function contributions(?string $portal = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		$refusal = $this->refuseUnlessPermitted(portal: $portal);
		if ($refusal !== null) {
			return $refusal;
		}

		// A provider is third-party code reached through a duck-typed call
		// (ADR-046). One that throws must not take the portal's whole content
		// API down with it — the registry already logs and skips per provider,
		// and this is the outer belt for anything that escapes it.
		try {
			$aggregate = $this->registry->aggregateAnonymous();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Portaliq: anonymous contribution aggregation failed',
				['reason' => $e->getMessage()]
			);
			$aggregate = ['contributions' => []];
		}

		return $this->publicJson(
			payload: [
				'contributions' => $this->contribFilter->forPortal(
					contributions: (array)($aggregate['contributions'] ?? []),
					portalSlug: (string)$portal['slug']
				),
			]
		);
	}//end contributions()


	/**
	 * The audience this request is being served for.
	 *
	 * Part of the cache key. Anything carrying an Authorization header is
	 * treated as authenticated even before the bearer is validated — an
	 * invalid token must not be able to READ FROM the anonymous cache slot and
	 * then be rejected, because that is how a per-visitor response ends up
	 * cached under the anonymous key.
	 *
	 * @return string Either 'anonymous' or 'authenticated'.
	 */
	private function audience(): string {
		$header = (string)$this->request->getHeader('Authorization');
		if ($header === '') {
			return 'anonymous';
		}

		return 'authenticated';
	}//end audience()


	/**
	 * Resolve the locale for this request against the site's own set.
	 *
	 * @param array       $portal   The resolved portal.
	 * @param string|null $requested The requested locale.
	 *
	 * @return string The locale to serve.
	 */
	private function locale(array $portal, ?string $requested): string {
		$locales = array_values((array)($portal['locales'] ?? []));
		$default = (string)($locales[0] ?? 'nl');

		if ($requested === null || $requested === '') {
			return $default;
		}

		if (in_array($requested, $locales, true) === true) {
			return $requested;
		}

		return $default;
	}//end locale()


	/**
	 * A JSON response for content, with caching headers set once.
	 *
	 * A per-visitor response is marked `private, no-store` so a CDN in front
	 * cannot pool it across visitors — that leak would happen at the edge,
	 * where this installation's logs would never show it.
	 *
	 * @param array $payload The response body.
	 *
	 * @return JSONResponse The response.
	 */
	private function publicJson(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		if ($this->audience() === 'anonymous') {
			$response->addHeader('Cache-Control', 'public, max-age=300, must-revalidate');

			// READABLE CROSS-ORIGIN, BECAUSE THAT IS THE POINT OF A HEADLESS
			// CONTRACT. A portal built by the Docusaurus plugin is static HTML
			// on another host; without this its pages can be BUILT from the
			// contract but nothing on them can read it at runtime.
			//
			// `*` and never credentials. This branch serves the anonymous
			// audience — the same bytes any visitor could fetch by opening the
			// URL — so there is nothing here a permissive origin could reach
			// that it could not reach without one. The authenticated branch
			// below deliberately sends no such header: a response varying by
			// bearer must not be readable by a page that did not send it.
			$response->addHeader('Access-Control-Allow-Origin', '*');

			return $response;
		}

		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end publicJson()


	/**
	 * The single not-found response every miss shares.
	 *
	 * @return JSONResponse A 404.
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end notFound()


}//end class
