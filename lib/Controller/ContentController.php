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
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


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
				'locales' => array_values((array)($portal['locales'] ?? [])),
				'locale'  => $this->locale(portal: $portal, requested: $locale),
				// The MODES are public — a visitor has to know how to sign in.
				// Provider secrets are not here and never will be; they live in
				// the credential broker.
				'authentication' => ['modes' => array_values((array)($auth['modes'] ?? ['public']))],
			]
		);
	}//end site()


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
