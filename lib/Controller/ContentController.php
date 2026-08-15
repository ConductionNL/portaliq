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

use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\WebsiteResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public, read-only content endpoints.
 *
 * Every route is public — this is a public website's content, and a Docusaurus
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
	 * @param string          $appName  The app id.
	 * @param IRequest        $request  The request.
	 * @param WebsiteResolver $resolver Resolves the serving website.
	 * @param CmsReader       $reader   Reads website-scoped content.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WebsiteResolver $resolver,
		private readonly CmsReader $reader,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * The resolved site's own presentation record.
	 *
	 * @param string|null $site   Explicit site slug, for a consumer not using the host.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The site, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-website-or-to-none
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function site(?string $site = null, ?string $locale = null): JSONResponse {
		$website = $this->resolver->resolve(request: $this->request, siteSlug: $site);
		if ($website === null) {
			return $this->notFound();
		}

		$auth = (array)($website['authentication'] ?? []);

		return $this->publicJson(
			payload: [
				'title'   => (string)($website['title'] ?? ''),
				'slug'    => (string)($website['slug'] ?? ''),
				'theme'   => (string)($website['theme'] ?? ''),
				'logo'    => (string)($website['logo'] ?? ''),
				'locales' => array_values((array)($website['locales'] ?? [])),
				'locale'  => $this->locale(website: $website, requested: $locale),
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
	 * @param string|null $site   Explicit site slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The menus, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function menus(?string $site = null, ?string $locale = null): JSONResponse {
		$website = $this->resolver->resolve(request: $this->request, siteSlug: $site);
		if ($website === null) {
			return $this->notFound();
		}

		return $this->publicJson(
			payload: [
				'menus' => $this->reader->menus(
					website: (string)$website['slug'],
					locale: $this->locale(website: $website, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end menus()


	/**
	 * The site's published pages, without their bodies.
	 *
	 * @param string|null $site   Explicit site slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The page summaries, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function pages(?string $site = null, ?string $locale = null): JSONResponse {
		$website = $this->resolver->resolve(request: $this->request, siteSlug: $site);
		if ($website === null) {
			return $this->notFound();
		}

		return $this->publicJson(
			payload: [
				'pages' => $this->reader->pages(
					website: (string)$website['slug'],
					locale: $this->locale(website: $website, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end pages()


	/**
	 * One published page by route.
	 *
	 * @param string|null $route  The in-site route, leading slash optional.
	 * @param string|null $site   Explicit site slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The page, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function page(?string $route = null, ?string $site = null, ?string $locale = null): JSONResponse {
		$website = $this->resolver->resolve(request: $this->request, siteSlug: $site);
		if ($website === null) {
			return $this->notFound();
		}

		$normalised = '/'.ltrim((string)($route ?? ''), '/');
		$page = $this->reader->page(
			website: (string)$website['slug'],
			route: $normalised,
			locale: $this->locale(website: $website, requested: $locale),
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
	 * @param string|null $site   Explicit site slug.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The terms, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-all-content-must-be-scoped-to-a-website
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function glossary(?string $site = null, ?string $locale = null): JSONResponse {
		$website = $this->resolver->resolve(request: $this->request, siteSlug: $site);
		if ($website === null) {
			return $this->notFound();
		}

		return $this->publicJson(
			payload: [
				'terms' => $this->reader->glossary(
					website: (string)$website['slug'],
					locale: $this->locale(website: $website, requested: $locale),
					audience: $this->audience()
				),
			]
		);
	}//end glossary()


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
	 * @param array       $website   The resolved website.
	 * @param string|null $requested The requested locale.
	 *
	 * @return string The locale to serve.
	 */
	private function locale(array $website, ?string $requested): string {
		$locales = array_values((array)($website['locales'] ?? []));
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
