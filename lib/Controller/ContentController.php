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

use Closure;
use OCA\Portaliq\Kernel\Contracts\CmsReaderInterface;
use OCA\Portaliq\Kernel\Contracts\NextcloudRegistrationDoorInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionFilterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalNoticeReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PublicAssistantChannelInterface;
use OCA\Portaliq\Kernel\Contracts\PublicSourceScopeInterface;
use OCA\Portaliq\Kernel\Contracts\SiteOrganisationInterface;
use OCA\Portaliq\Kernel\Contracts\TrafficConfigResolverInterface;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
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
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   -- eleven parameters, of which
 * `$appName` and `$request` are Nextcloud's own `Controller` contract and
 * cannot be dropped or grouped. The class injects nine collaborators of its
 * own, under the threshold; folding them into a parameter object would hide
 * the dependencies from the container rather than remove them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- 13, one over the bound,
 * since the site record carries the portal's running notices
 * (operate-maintenance-notice). Each collaborator is one read the public
 * content API answers; moving the notice read behind another collaborator
 * would hide the coupling, not remove it.
 */
class ContentController extends Controller {
	use PublicContentGate;


	/**
	 * Constructor.
	 *
	 * @param string                     $appName      The app id.
	 * @param IRequest                   $request      The request.
	 * @param PortalResolverInterface             $resolver     Resolves the serving portal.
	 * @param CmsReaderInterface                  $reader       Reads portal-scoped content.
	 * @param PortalContributionRegistryInterface $registry     Aggregates leaf apps' contributions.
	 * @param PortalContributionFilterInterface   $contribFilter Scopes that aggregate to one portal.
	 * @param LoggerInterface            $logger       Records a provider failure the visitor never sees.
	 * @param PortalSessionServiceInterface       $session      Resolves the caller's portal session for the content gate.
	 * @param TrafficConfigResolverInterface      $traffic      Resolves the portal's measurement configuration.
	 * @param IURLGenerator              $urlGenerator Builds the absolute collector URL.
	 * @param PortalNoticeReaderInterface         $notices      The notices running on the site now.
	 * @param IUserSession               $userSession  Tells a signed-in Nextcloud user (an editor) from a visitor.
	 * @param PublicSourceScopeInterface $publicSourceScope The PublicSourceScope helper.
	 * @param PublicAssistantChannelInterface|null $assistant   The public assistant's channel, when wired.
	 * @param PublicSourceScopeInterface|null     $assistantScope What the public assistant may read.
	 * @param NextcloudRegistrationDoorInterface|null $registrationDoor Where "Create an account" leads (REQ-NCL-005).
	 * @param SiteOrganisationInterface|null      $siteOrganisation Names the portal's organisation.
	 * @param MediaReferences|null       $media        Resolves the portal's media:<id> images.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolverInterface $resolver,
		private readonly CmsReaderInterface $reader,
		private readonly PortalContributionRegistryInterface $registry,
		private readonly PortalContributionFilterInterface $contribFilter,
		private readonly LoggerInterface $logger,
		private readonly PortalSessionServiceInterface $session,
		private readonly TrafficConfigResolverInterface $traffic,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalNoticeReaderInterface $notices,
		private readonly IUserSession $userSession,
		private readonly PublicSourceScopeInterface $publicSourceScope,
		private readonly ?PublicAssistantChannelInterface $assistant = null,
		private readonly ?PublicSourceScopeInterface $assistantScope = null,
		private readonly ?NextcloudRegistrationDoorInterface $registrationDoor = null,
		private readonly ?SiteOrganisationInterface $siteOrganisation = null,
		private readonly ?MediaReferences $media = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * A portal image field as the address the site can load: a media:<id>
	 * reference becomes the published item's public address of this portal,
	 * '' when it does not resolve; any other value is sent as it is stored.
	 *
	 * @param array<string, mixed> $portal The serving portal.
	 * @param string               $field  The image field.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
	 */
	private function portalImage(array $portal, string $field): string {
		$value = $portal[$field] ?? '';
		if (is_string($value) === false) {
			return '';
		}

		if (MediaReferences::isReference(value: $value) === false) {
			return $value;
		}

		if ($this->media === null) {
			return '';
		}

		return $this->media->image(portal: (string)($portal['slug'] ?? ''), value: $value);
	}//end portalImage()

	/**
	 * The resolved site's own presentation record.
	 *
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 * @param string|null $locale Requested locale.
	 *
	 * @return JSONResponse The site, or 404.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function site(?string $portal = null, ?string $locale = null): JSONResponse {
		$portal = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($portal === null) {
			return $this->notFound();
		}

		return $this->publicJson(
			payload: [
				'title'   => (string)($portal['title'] ?? ''),
				'slug'    => (string)($portal['slug'] ?? ''),
				'theme'   => (string)($portal['theme'] ?? ''),
				// The logo and the hero image may be items of the portal's
				// media library (portal-identity-from-the-admin REQ-PIA-001):
				// sent as their public address, never as the reference.
				'logo'    => $this->portalImage(portal: $portal, field: 'logo'),
				'heroImage' => $this->portalImage(portal: $portal, field: 'heroImage'),
				// The line under the name in the footer's logo block. The
				// reference carries one ("Één plek voor alle publicaties van
				// Gemeente Tilburg") and this app had no field for it at all,
				// so every portal's footer showed a bare title.
				'tagline' => (string)($portal['tagline'] ?? ''),
				'locales' => array_values((array)($portal['locales'] ?? [])),
				'locale'  => $this->locale(portal: $portal, requested: $locale),
				// The MODES are public — a visitor has to know how to sign in.
				// Provider secrets are not here and never will be; they live in
				// the credential broker.
				// The shell: the public part of `authentication` (modes, and a
				// declared register destination), the header's shape, the
				// footer on named keys and the portal's regions
				// (portal-theme-blocks-and-contributed-pages REQ-PTB-004,
				// REQ-PTB-005, REQ-PTB-009). Provider secrets never leave the
				// record; they live in the credential broker.
				...$this->reader->shell(portal: $portal),
				// The resolved measurement configuration, defaults filled in,
				// so the client sends only what the portal asked for; and the
				// absolute collector URL, so a statically built site on its
				// own domain knows where to post. Nothing here is secret: it
				// is exactly what the client is about to act on.
				'traffic' => $this->traffic->resolve(portal: $portal),
				'collector' => $this->urlGenerator->linkToRouteAbsolute('portaliq.traffic.collect'),
				// Where "Wachtwoord vergeten" leads: Nextcloud's own login page, with a
				// way back to the account route. '' unless the portal offers that route
				// (password-reset-from-the-sign-in-page REQ-PWR-001).
				'lostPasswordUrl' => $this->lostPasswordUrl(portal: $portal),
				// Where "Create an account" leads: Nextcloud's own registration
				// form, '' unless the portal offers the account route, names a
				// resident group and the registration app fills that group
				// (nextcloud-account-registration REQ-NCL-005). Built here from a
				// route name, never from the request.
				'registerUrl' => ($this->registrationDoor?->registerUrl(portal: $portal) ?? ''),
				// Whether a visitor's search also reads the text inside public
				// documents. On unless the portal switched it off
				// (portal-federated-search REQ-PFS-CONTENT-001).
				'searchInsideDocuments' => (($portal['searchInsideDocuments'] ?? true) !== false),
				// Whether the "Ask a question" widget is offered: the portal turned it on
				// AND hermiq's entry point answers. Off otherwise
				// (search-assistant-from-public-content REQ-SAP-006).
				'assistantEnabled' => $this->assistantEnabled(portal: $portal),
				// Maintenance and warning notices running now
				// (operate-maintenance-notice). This answer is cached for up
				// to five minutes, so each carries its end and the client
				// drops it once that has passed.
				'notices' => $this->notices->active(portal: (string)($portal['slug'] ?? ''), surface: 'site'),
				// The id and the name of the portal's organisation, so the
				// search's organisation filter reads a name, not an id
				// (woo-search-names-the-organisation). Null when unknown.
				'organisation' => $this->siteOrganisation?->forPortal(portal: $portal),
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
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($locale): JSONResponse {
				return $this->publicJson(
					payload: [
						'menus' => $this->reader->menus(
							portal: (string)$portal['slug'],
							locale: $this->locale(portal: $portal, requested: $locale),
							audience: $this->audience()
						),
					]
				);
			}
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
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($locale): JSONResponse {
				return $this->publicJson(
					payload: [
						'pages' => $this->reader->pages(
							portal: (string)$portal['slug'],
							locale: $this->locale(portal: $portal, requested: $locale),
							audience: $this->audience()
						),
					]
				);
			}
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
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($route, $locale): JSONResponse {
				$normalised = '/'.ltrim((string)($route ?? ''), '/');
				$page = $this->reader->page(
					portal: (string)$portal['slug'],
					route: $normalised,
					locale: $this->locale(portal: $portal, requested: $locale),
					audience: $this->audience(),
					organisation: (string)($portal['organisation'] ?? '')
				);

				if ($page === null) {
					// Identical to an unknown route on purpose — see the class docblock.
					return $this->notFound();
			}

			return $this->publicJson(payload: $page);
			}
		);
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
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($locale): JSONResponse {
				return $this->publicJson(
					payload: [
						'terms' => $this->reader->glossary(
							portal: (string)$portal['slug'],
							locale: $this->locale(portal: $portal, requested: $locale),
							audience: $this->audience()
						),
					]
				);
			}
		);
	}//end glossary()


	/**
	 * The portal's published FAQ entries.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 * @param string|null $page   Only the entries shown on this page route.
	 * @param string|null $topic  Only the entries of this topic.
	 *
	 * @return JSONResponse The entries, or 404.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function faq(?string $portal=null, ?string $locale=null, ?string $page=null, ?string $topic=null): JSONResponse {
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($locale, $page, $topic): JSONResponse {
				return $this->publicJson(
					payload: [
						'entries' => $this->reader->faq(
							portal: (string)$portal['slug'],
							locale: $this->locale(portal: $portal, requested: $locale),
							audience: $this->audience(),
							page: trim((string)$page),
							topic: trim((string)$topic)
						),
					]
				);
			}
		);
	}//end faq()

	/**
	 * One published product finder.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale Requested locale.
	 * @param string|null $finder The finder's id; empty for the first published one.
	 *
	 * @return JSONResponse The finder, or 404.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function finder(?string $portal=null, ?string $locale=null, ?string $finder=null): JSONResponse {
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal) use ($locale, $finder): JSONResponse {
				$found = $this->reader->finder(
					portal: (string)$portal['slug'],
					id: trim((string)$finder),
					locale: $this->locale(portal: $portal, requested: $locale),
					audience: $this->audience()
				);
				if ($found === null) {
					return $this->notFound();
			}

			return $this->publicJson(payload: ['finder' => $found]);
			}
		);
	}//end finder()

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
		return $this->forPermittedPortal(
			portal: $portal,
			answer: function (array $portal): JSONResponse {
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
			}
		);
	}//end contributions()


	/**
	 * Answer for the portal of this request when this caller may read its
	 * content: the shared 404 for an unknown portal, the gate's 401 / 403
	 * before anything is read, else the answer.
	 *
	 * @param string|null                                $portal Explicit portal slug; else resolved from the request.
	 * @param Closure(array<string, mixed>): JSONResponse $answer The answer for the resolved, permitted portal.
	 *
	 * @return JSONResponse
	 */
	private function forPermittedPortal(?string $portal, Closure $answer): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return $this->notFound();
		}

		return ($this->refuseUnlessPermitted(portal: $resolved) ?? $answer($resolved));
	}//end forPermittedPortal()


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
	 * A signed-in Nextcloud user (an editor reading the site) gets the
	 * anonymous body, but never a cacheable one: served `public, max-age=300`,
	 * the editor's own tab showed the page as it was before they published,
	 * for five minutes (found on :8080, 02 Oct 2026). Anonymous visitors stay
	 * cacheable.
	 *
	 * @param array $payload The response body.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-site-must-show-what-an-editor-published-not-a-cached-copy-req-ssp-001
	 */
	private function publicJson(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		if ($this->audience() === 'anonymous' && $this->userSession->getUser() === null) {
			$response->addHeader('Cache-Control', 'public, max-age=300, must-revalidate');

			return $response;
		}

		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end publicJson()



	/**
	 * The address behind "Wachtwoord vergeten", or '' when the portal does not
	 * offer the Nextcloud account route. Portaliq adds no reset of its own.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return string The absolute address, or ''.
	 *
	 * @spec openspec/changes/password-reset-from-the-sign-in-page/specs/portal-ways-in/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-password-reset-req-pwr-001
	 */
	private function lostPasswordUrl(array $portal): string {
		$modes = ($portal['authentication']['modes'] ?? []);
		if (is_array($modes) === false || in_array('nextcloud', $modes, true) === false) {
			return '';
		}

		$back = $this->urlGenerator->linkToRoute(
			'portaliq.session.nextcloud',
			['portal' => (string)($portal['slug'] ?? '')]
		);

		return $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm', ['redirect_url' => $back]);
	}//end lostPasswordUrl()

	/**
	 * Whether the assistant widget is on for a portal.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 *
	 * @return bool True when the portal enabled it and hermiq answers.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
	 */
	private function assistantEnabled(array $portal): bool {
		$scope = $this->assistantScope ?? $this->publicSourceScope;
		return $scope->enabledFor(portal: $portal) === true && $this->assistant !== null && $this->assistant->isAvailable() === true;
	}//end assistantEnabled()
}//end class
