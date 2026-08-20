<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE `ac-*` SKELETON IS THE REFERENCE IMPLEMENTATION'S, ON PURPOSE.

		`nlds-app.css` is 1362 `ac-*` selectors and 633 `con-*` against 157
		`utrecht-*` — it styles the reference's DOM, not a portal's, so loading
		it changed nothing on screen until this renderer emitted the matching
		structure. Captured from the running reference rather than guessed:

		  .ac-app-container
		    header.ac-header                       1280x151
		      .ac-header__navigation-main          1280x96
		      .ac-header__navigation-secondary     1280x55
		        .container                         1200 @ x=40
		      .ac-header__navigation-breadcrumb
		    main.ac-app-main                       1280 @ x=0, full bleed
		      .container                           1200 @ x=40
		    footer.ac-footer
		      h2.sr-only
		      section                              96px band, blue-600
		        .container.ac-footer__container     4-column grid, 28px gap
		      section.ac-footer__sub-footer        28px band, blue-500

		Two things in that tree are load-bearing in a way the class names do
		NOT advertise, and both are explained where they are emitted below:
		the footer needs TWO sections (the CSS selects on `:first-of-type` /
		`:last-of-type:not(:only-of-type)`, never on `.ac-footer__sub-footer`),
		and every reading region needs its own `.container` — `ac-app-main` is
		deliberately full-bleed.

		`pq-site` and the `data-testid`s stay so the existing e2e keeps
		addressing the same nodes.
	-->
	<div
		class="pq-site ac-app-container"
		:class="themeClass"
		data-testid="site-root">
		<!--
			NO SKIP LINK HERE — it is emitted by `templates/site.php`, ahead of
			this mount point.

			It lived here, and here it did not exist until the bundle had
			downloaded, parsed and mounted. The visitor who most needs a skip
			link is the one tabbing into a page that is still loading, and for
			them there was nothing to find. The shell owns the document, so the
			shell owns the SC 2.4.1 affordance; a second one at this level would
			be a duplicate tab stop announcing the same target twice.
		-->
		<header class="ac-header pq-site__header" data-testid="site-header">
			<div class="ac-header__navigation-main">
				<div class="ac-header__logo">
					<div>
						<div class="con-logo-container header" />
						<span class="sr-only">Logo</span>
						<h1 class="logo-text" data-testid="site-title">
							{{ site.title || '…' }}
						</h1>
					</div>
				</div>

				<!--
					The sign-in affordance appears ONLY when the portal declares
					a mode other than `public`. A portal with no accounts must
					show no login button: an inert one is a support ticket from
					every visitor who presses it.

					It sits in the reference's `__right-section` / `ac-navigation`
					slot, which is where that implementation puts
					Aanmelden/Inloggen.
				-->
				<div class="ac-header__right-section">
					<div
						v-if="session || signInRoutes.length"
						class="ac-navigation pq-site__auth"
						data-testid="site-auth">
						<template v-if="session">
							<span data-testid="site-auth-subject">{{
								sessionLabel
							}}</span>
							<button
								type="button"
								data-testid="site-signout"
								@click="signOut">
								Uitloggen
							</button>
						</template>
						<nav v-else aria-label="Gebruikersmenu">
							<ul>
								<li v-for="entry in signInRoutes" :key="entry.mode">
									<a
										:href="entry.href"
										:data-mode="entry.mode"
										data-testid="site-signin">
										{{ entry.label }}
									</a>
								</li>
							</ul>
						</nav>
					</div>
				</div>
			</div>

			<div class="ac-header__navigation-secondary">
				<div class="container">
					<div class="ac-c-navigation__container">
						<SiteMenu
							v-for="menu in headerMenus"
							:key="menu.title"
							:menu="menu"
							:currentRoute="route"
							@navigate="go" />
					</div>
				</div>
			</div>

			<!--
				THE BREADCRUMB, matching the reference's `Kruimelpad` landmark.

				It renders only BELOW the home route: a trail whose only entry
				is the page you are on tells the visitor nothing and adds a
				landmark for a screen reader to step through.

				The last crumb is the current page and is NOT a link — an
				anchor to where you already are is a control that does nothing.
			-->
			<div class="ac-header__navigation-breadcrumb">
				<div class="container">
					<nav
						v-if="breadcrumbs.length > 1"
						class="ac-breadcrumb"
						aria-label="Kruimelpad"
						data-testid="site-breadcrumb">
						<ul class="ac-breadcrumb__list">
							<li
								v-for="(crumb, index) in breadcrumbs"
								:key="crumb.route"
								class="ac-breadcrumb__item">
								<a
									v-if="index < breadcrumbs.length - 1"
									class="utrecht-link"
									:href="hrefForRoute(crumb.route)"
									@click.prevent="go(crumb.route)">
									{{ crumb.label }}
								</a>
								<span v-else aria-current="page">{{
									crumb.label
								}}</span>
								<span
									v-if="index < breadcrumbs.length - 1"
									class="ac-breadcrumb__separator"
									aria-hidden="true">
									›
								</span>
							</li>
						</ul>
					</nav>
				</div>
			</div>
		</header>

		<!--
			`.container` IS THE CONTENT COLUMN, AND IT IS NOT OPTIONAL.

			`ac-app-main` itself is full-bleed — measured on the reference, 1280
			wide at x=0 with zero padding — because the bands inside it paint
			edge to edge. What holds the READING column is a `.container`:
			`max-width: 1200px; margin: 0 40px; padding: 0 16px`, landing at
			x=40, and every band in the header and footer already uses one.

			Main was the only region emitting its content as a direct child, so
			body copy started hard against the viewport edge at x=0 while the
			navigation above it and the footer below both began at 40. On a wide
			screen that is a full-width line of text — the least readable layout
			the design system can produce, and the only place it happened.
		-->
		<main id="pq-main" class="ac-app-main pq-site__main">
			<!--
				MAIN IS FULL-BLEED AND THE CONTAINER MOVED INWARDS.

				It used to wrap everything here, which was right while the only
				block was markdown and wrong the moment bands existed: a hero
				inside this column measured 1168px against the reference's 1280,
				and nothing inside the hero could recover the width because the
				clamp was an ancestor.

				This is the reference's own structure — `main` full-bleed, every
				`section` bringing its own `.container` — so each region below
				takes one, and `WidgetGrid` decides per block whether to.
			-->
			<div>
				<p v-if="loading" class="container" data-testid="site-loading">
					Bezig met laden…
				</p>

				<!-- A failed load says so. Rendering an empty page instead would
			     make a broken deployment look exactly like an empty site — the
			     one confusion this surface can least afford. -->
				<div
					v-else-if="error"
					class="container"
					role="alert"
					data-testid="site-error">
					<h2>
						{{
							error.status === 404
								? 'Pagina niet gevonden'
								: 'Er ging iets mis'
						}}
					</h2>
					<p>
						{{
							error.status === 404
								? 'Deze pagina bestaat niet (meer).'
								: 'De inhoud kon niet worden geladen.'
						}}
					</p>
				</div>

				<!--
					`utrecht-article` IS A PROSE MEASURE, so a grid does not get
					one.

					The class carries `max-inline-size` in token sets that
					define it — Rotterdam's is 750px, straight from RODS — and
					that is exactly right for a column of running text. Applied
					to a 12-column widget grid it clamps the whole page to a
					reading width: measured at 1280px viewport, the article came
					out 750px wide at x=0 while the header and footer
					`.container`s sat at x=40 and 1200px, so the content was both
					narrower than the design and misaligned with the furniture
					above and below it.

					The element stays an `<article>` either way — the page IS a
					self-contained document, which is a question about semantics
					and not about line length.
				-->
				<article
					v-else-if="page"
					:class="bodyIsGrid ? null : 'utrecht-article'"
					data-testid="site-page">
					<!--
						THE RENDERER'S OWN TITLE HEADING IS A FALLBACK, not a
						fixture. A page whose body opens with a hero already
						declares its heading, and emitting this one as well
						printed the same sentence twice — once here in black
						above the band, once inside the band.

						The check is on the BODY rather than on a flag, because
						the duplication is a property of what the page actually
						renders, not of what an author remembered to tick.
					-->
					<div v-if="!bodyProvidesHeading" class="container">
						<h2 class="utrecht-heading-2" data-testid="page-title">
							{{ page.title }}
						</h2>
					</div>

					<WidgetGrid
						v-if="page.body && page.body.type === 'grid'"
						:widgets="page.body.widgets || []"
						:glossary="glossary"
						:contributions="contributions"
						:routeParam="routeParam"
						@navigate="go"
						@search="goSearch" />

					<div v-else class="container">
						<MarkdownBlock
							data-testid="page-markdown"
							:source="(page.body && page.body.markdown) || ''" />
					</div>
				</article>

				<!--
					NEITHER THE GLOSSARY NOR THE CONTRIBUTED SURFACES ARE
					HARD-CODED HERE ANY MORE.

					They were two `<section>`s carrying literal
					`<h2>Begrippenlijst</h2>` and `<h2>Diensten</h2>`, rendered on
					every page that happened to satisfy a condition. That made
					them impossible for a portal to move, rename, translate,
					reorder or leave out — "Diensten / Meldingen" appeared under
					the content of pages whose author never asked for it, and the
					glossary could only ever live at one route.

					Both are CONTENT, so both belong in a page body as blocks an
					author places. The data still reaches this component and is
					still fetched over the same public contract; what changed is
					that nothing renders it unless a page asks.

					BOTH HALVES OF THAT MOVE ARE NEEDED, and only one of them
					shipped first. The `glossary` block existed and was placed;
					the contributed surfaces lost their section and gained no
					block, so the public contract kept answering with
					contributions that no page could render — a bridge built
					from one side, which is invisible because the page still
					looks complete. `contributions` is now a block too, and it
					takes its rows from here for the same reason the glossary
					does: this renderer runs at a public origin, so a block that
					fetched for itself could not mount there.
				-->
			</div>
		</main>

		<!--
			TWO SECTIONS, AND THE COUNT IS LOAD-BEARING.

			`nlds-app.css` styles this footer by POSITION, not by class:

			  .ac-footer section:first-of-type              { 96px band, blue-600 }
			  .ac-footer section:first-of-type .container   { display: grid, 4 cols }
			  .ac-footer section:last-of-type:not(:only-of-type)
			                                               { 28px band, blue-500 }

			`.ac-footer__sub-footer` appears in NO rule. This markup used to be a
			single `<section class="ac-footer__sub-footer">`, which looked right
			and rendered wrong: being the only section it was `:only-of-type`, so
			it picked up the FIRST band's 96px padding and dark blue, and the
			`:not(:only-of-type)` guard deliberately excluded it from the strip
			rule it was named after. Measured against the reference: 211px against
			368px, one band where there are two.

			So the sub-footer strip exists only when a second section does. Both
			are emitted unconditionally.
		-->
		<footer class="ac-footer pq-site__footer" data-testid="site-footer">
			<!-- The reference labels its footer for assistive tech and hides the
			     heading visually; a landmark with no name is announced as just
			     "footer". -->
			<h2 class="sr-only">Footer</h2>

			<section>
				<div class="container ac-footer__container">
					<nav
						v-for="menu in footerMenus"
						:key="menu.title"
						class="ac-footer__links"
						:aria-label="menu.title"
						data-testid="site-footer-menu">
						<h3 class="ac-footer__menu-title">{{ menu.title }}</h3>
						<ul>
							<li v-for="item in menu.items" :key="item.name">
								<!--
									The reference marks every footer link with an
									external-link glyph. It is DECORATIVE here —
									`aria-hidden` — because the link already has
									its own text; announcing "external link"
									twice per item helps nobody.
								-->
								<a
									class="ac-footer__link"
									:href="item.link"
									:target="
										isExternal(item.link) ? '_blank' : undefined
									"
									:rel="
										isExternal(item.link)
											? 'noopener noreferrer'
											: undefined
									"
									@click="onFooterLink($event, item.link)">
									<CnSiteIcon
										v-if="isExternal(item.link)"
										name="external-link"
										:size="18" />
									<span>{{ item.name }}</span>
								</a>
							</li>
						</ul>
					</nav>

					<div class="ac-footer__logo">
						<div class="con-logo-container footer" />
						<span>
							<span>{{ site.title }}</span>
							<!-- The reference's footer logo carries a tagline under
							     the name. It is portal CONTENT, so it comes from the
							     portal record rather than a constant. -->
							<span
								v-if="site.tagline"
								data-testid="site-footer-tagline">
								{{ site.tagline }}
							</span>
						</span>
					</div>
				</div>
			</section>

			<section class="ac-footer__sub-footer">
				<div class="container">
					<!--
						The reference's strip is a HORIZONTAL NAV of legal links
						(Privacy, Algemene voorwaarden, Disclaimer, FAQ), not a
						colophon line. `.ac-footer__sub-footer-horizontal` is the
						class its CSS separates with a pipe between items.

						Driven by a menu so it is configurable per portal — the
						colophon it replaces was the portal title and nothing
						else, which no portal could change.
					-->
					<nav
						v-if="subFooterMenu"
						class="ac-footer__sub-footer-links"
						:aria-label="subFooterMenu.title"
						data-testid="site-subfooter-menu">
						<ul class="ac-footer__sub-footer-horizontal">
							<li v-for="item in subFooterMenu.items" :key="item.name">
								<a
									:href="item.link"
									@click.prevent="go(item.link)"
									>{{ item.name }}</a
								>
							</li>
						</ul>
					</nav>

					<p v-else data-testid="site-footer-colophon">{{ site.title }}</p>
				</div>
			</section>
		</footer>
	</div>
</template>

<script>
import { CnSiteIcon } from '@conduction/nextcloud-vue/public'
import MarkdownBlock from './components/MarkdownBlock.vue'
import SiteMenu from './components/SiteMenu.vue'
import WidgetGrid from './components/WidgetGrid.vue'
import { authBaseFrom, fetchSession, signInRoutes } from './lib/authApi.js'
import {
	fetchContributions,
	fetchGlossary,
	fetchMenus,
	fetchPage,
	fetchSite,
	resolveApiBase,
} from './lib/contentApi.js'

/**
 * The built-in site renderer.
 *
 * It reads the PUBLIC content API and nothing else — the same endpoints the
 * Docusaurus plugin reads. That is what keeps the CMS headless: if this
 * component ever needed a Portaliq internal, the finding would be that the API
 * is incomplete (ADR-086 §1).
 */
export default {
	name: 'App',

	components: { CnSiteIcon, MarkdownBlock, SiteMenu, WidgetGrid },

	props: {
		/** Explicit site slug, when not resolving by host. */
		portalSlug: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			site: {},
			menus: [],
			glossary: [],
			contributions: [],
			session: null,
			page: null,
			route: '/',
			// The trailing segment of a route that resolved to its PARENT
			// page — the publication id in `/publicatie/<id>`. Empty for an
			// ordinary page. See `loadRoute`.
			routeParam: '',
			// Where the hero's search box sends a term. A constant rather than
			// a portal field for now: the seeded portal puts search at
			// `/zoeken`, matching the reference, and a portal that moves it
			// wants a `searchRoute` on the portal object rather than a guess
			// here.
			searchRoute: '/zoeken',
			loading: true,
			error: null,
		}
	},

	computed: {
		/**
		 * Theme class for the resolved site.
		 *
		 * Mirrors the fleet's theming convention — a `<variant>-theme` class
		 * whose tokens come from themiq. Portaliq defines no tokens of its own
		 * (ADR-086 §6); if the theme does not resolve, the page is unstyled
		 * rather than silently restyled to somebody else's brand.
		 *
		 * @return {string} The theme class, or ''.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		themeClass() {
			return this.site.theme ? `${this.site.theme}-theme` : ''
		},

		/**
		 * Whether the page body already opens with its own heading.
		 *
		 * Only a `hero` qualifies today: it is the one block that renders a
		 * page-level heading. Asking the BODY rather than trusting a flag keeps
		 * this true by construction — a page gains or loses its own heading by
		 * gaining or losing the block that draws one.
		 *
		 * @return {boolean} True when the renderer must not add a title heading.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Whether this page's body is a widget grid rather than markdown.
		 *
		 * Decides whether the page wears `utrecht-article`, which carries a
		 * prose `max-inline-size` — see the template.
		 *
		 * @return {boolean} True when the body is a grid.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * The breadcrumb trail for the current route.
		 *
		 * Built from the route's own segments, with the CURRENT page's title
		 * used for the last crumb once the page has loaded. Intermediate
		 * segments are humanised from the path rather than looked up — an
		 * ancestor need not be a page at all (`/publicatie` is a page,
		 * `/publicatie/<id>` is a subject), so asking the CMS for a title per
		 * ancestor would 404 on the common case.
		 *
		 * @return {Array<object>} `{route, label}` crumbs, home first.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		breadcrumbs() {
			const crumbs = [{ route: '/', label: 'Home' }]
			const segments = String(this.route || '/')
				.split('/')
				.filter(Boolean)

			segments.forEach((segment, index) => {
				const route = `/${segments.slice(0, index + 1).join('/')}`
				const isLast = index === segments.length - 1

				// The id segment of `/publicatie/<id>` is not a word; the
				// page's own title is what a visitor recognises.
				let label = segment.charAt(0).toUpperCase() + segment.slice(1)
				if (isLast === true && this.page && this.page.title) {
					label = this.page.title
				}

				crumbs.push({ route, label })
			})

			return crumbs
		},

		bodyIsGrid() {
			return (
				(this.page && this.page.body && this.page.body.type === 'grid')
				=== true
			)
		},

		bodyProvidesHeading() {
			const body = this.page.body || {}
			if (body.type !== 'grid') {
				return false
			}

			// A block that renders its SUBJECT's name owns the page heading.
			//
			// `hero` states the page's own title. `publicationDetail` states
			// the publication's, which is the more specific and more useful
			// one — so a detail page printed "Publicatie" as an h1 and then
			// "Subsidieregister Rotterdam" as another, two page titles where
			// the reference has one, and the generic one first.
			return (body.widgets || []).some(
				(w) => w.widgetKey === 'hero' || w.widgetKey === 'publicationDetail',
			)
		},

		/**
		 * The menus shown in the header bar.
		 *
		 * PLACEMENT COMES FROM `position`, WHICH IS WHAT THAT FIELD IS FOR — the
		 * register describes it as "ordering of this menu relative to others on
		 * the same portal (a header menu versus a footer menu)". So the rule is
		 * read off the existing contract rather than added to it: no schema
		 * change, no data migration, and a portal that declares one menu keeps
		 * behaving exactly as it did.
		 *
		 * Position 0 is the header. Everything else is a footer column.
		 *
		 * @return {Array} The header menus.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		headerMenus() {
			return this.menus.filter((menu) => (menu.position || 0) === 0)
		},

		/**
		 * The menus shown as columns in the footer's first band.
		 *
		 * The counterpart of `headerMenus`: every menu the header does not
		 * claim. Before this split, EVERY menu rendered in the header bar and
		 * the footer had no links at all — a portal could not express a footer
		 * column even though its data model already had the field to do it.
		 *
		 * The band is a four-column grid, so a portal declaring more than three
		 * footer menus wraps rather than overflowing; the logo occupies the
		 * fourth cell.
		 *
		 * @return {Array} The footer menus.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		footerMenus() {
			return this.menus.filter(
				(menu) => (menu.position || 0) !== 0 && menu !== this.subFooterMenu,
			)
		},

		/**
		 * The legal strip at the very bottom, if the portal declares one.
		 *
		 * CONVENTION, read off the existing `position` field rather than added
		 * to the schema: the HIGHEST position is the sub-footer. The reference
		 * puts Privacy / Algemene voorwaarden / Disclaimer / FAQ there, visually
		 * separate from the link columns above, and a portal needs some way to
		 * say which menu that is.
		 *
		 * Requires at least two footer menus, so a portal with a single footer
		 * menu keeps it as a COLUMN rather than having it silently demoted to
		 * the strip — one menu is far more likely to be links than legalese.
		 *
		 * @return {object|null} The sub-footer menu, or null.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		subFooterMenu() {
			// POSITION IS NOW A CONTRACT, not a comparison: 0 is the header, 1
			// is a footer column, 2 or higher is the legal strip.
			//
			// It used to be "the highest position, when there are at least
			// two" — which meant a portal could not have a legal strip WITHOUT
			// also having a footer column. The reference has exactly that
			// shape: one nav, in the strip, and a band above carrying only the
			// title and tagline. Reproducing it required inventing a footer
			// column the reference does not have.
			//
			// A portal with menus at 1 and 2 is unaffected; only a portal
			// whose single menu sits at 2 or above moves, and moving it is the
			// point.
			const strip = this.menus.filter((menu) => (menu.position || 0) >= 2)
			if (strip.length === 0) {
				return null
			}

			return strip.reduce((highest, menu) =>
				(menu.position || 0) > (highest.position || 0) ? menu : highest,
			)
		},

		/**
		 * The sign-in routes this portal offers, from its DECLARED modes.
		 *
		 * Derived from `/api/content/site`, so the decision travels on the
		 * public contract even though the act of signing in does not.
		 *
		 * @return {Array} Zero or more routes.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		signInRoutes() {
			return signInRoutes(this.site, authBaseFrom(resolveApiBase()))
		},

		/**
		 * @return {string} How to name the signed-in visitor.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		sessionLabel() {
			return (
				this.session?.name
				|| this.session?.subject
				|| this.session?.sub
				|| 'Ingelogd'
			)
		},
	},

	/**
	 * Boot: resolve the route, then load the portal and its first page —
	 * entirely from the public content API, with no Nextcloud global read.
	 *
	 * @return {Promise<void>} Resolves when the first page is on screen.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-renderer-must-not-depend-on-nextcloud-globals
	 */
	async mounted() {
		this.route = this.routeFromLocation()
		window.addEventListener('popstate', this.onPopState)
		await this.loadSite()
		await this.loadRoute(this.route)
	},

	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
	},

	methods: {
		/**
		 * The in-site route this browser location represents.
		 *
		 * @return {string} The route, always with a leading slash.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-renderer-must-not-depend-on-nextcloud-globals
		 */
		routeFromLocation() {
			const params = new URLSearchParams(window.location.search)
			const explicit = params.get('route')
			if (explicit) {
				return explicit.startsWith('/') ? explicit : `/${explicit}`
			}

			return '/'
		},

		/**
		 * Handle browser back/forward.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		onPopState() {
			const next = this.routeFromLocation()
			this.route = next
			this.loadRoute(next)
		},

		/**
		 * Load the portal record, its menus and its glossary.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		async loadSite() {
			try {
				const [site, menus, glossary] = await Promise.all([
					fetchSite(this.portalSlug),
					fetchMenus(this.portalSlug),
					fetchGlossary(this.portalSlug),
				])
				this.site = site
				this.menus = menus
				this.glossary = glossary
			} catch (error) {
				this.error = error
				return
			}

			// Contributions load SEPARATELY and never reject into the block
			// above. They come from third-party apps reached through a
			// duck-typed provider (ADR-046): a leaf app that is broken, half
			// installed, or simply absent must cost the visitor a section, not
			// the whole portal. The CMS content above is the portal; this is
			// an addition to it.
			try {
				this.contributions = await fetchContributions(this.portalSlug)
			} catch {
				this.contributions = []
			}

			// The session is read last and cannot fail the page. A portal whose
			// auth edge is down must still serve its public content, which is
			// the overwhelming majority of what it serves; `fetchSession`
			// resolves null rather than throwing for exactly that reason.
			this.session = await fetchSession(authBaseFrom(resolveApiBase()))

			this.applyDocumentTitle()
		},

		/**
		 * Put the PORTAL's name in the browser tab.
		 *
		 * Found by comparing against the portal this replaces: that one titles
		 * its tab properly and this one said "Nextcloud" — the hosting
		 * platform's name, on a white-label portal whose entire purpose is
		 * that a visitor never learns what it is built on.
		 *
		 * It is not a cosmetic detail. The tab title is the bookmark name, the
		 * history entry, the window-switcher label and the search-result
		 * heading. A municipality's portal filed under "Nextcloud" is wrong in
		 * every one of those places at once, and none of them appear in a
		 * screenshot of the page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		applyDocumentTitle() {
			const portalName = this.site.title
			if (!portalName) {
				return
			}

			const pageName = this.page?.title
			document.title =
				pageName && pageName !== portalName
					? `${pageName} - ${portalName}`
					: portalName
		},

		/**
		 * End the portal session and return to the signed-out view.
		 *
		 * The local state is cleared even when the edge refuses, because the
		 * alternative is a page that says "signed in" to somebody who has just
		 * asked, twice, not to be.
		 *
		 * @return {Promise<void>} Resolves when signed out.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		async signOut() {
			try {
				await fetch(`${authBaseFrom(resolveApiBase())}/session`, {
					method: 'DELETE',
					credentials: 'include',
				})
			} catch {
				// Reported by the state change below, not by an alert.
			}

			this.session = null
		},

		/**
		 * Load one page by route.
		 *
		 * @param {string} route The in-portal route.
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-unpublished-content-must-be-indistinguishable-from-absent-content
		 */
		async loadRoute(route) {
			this.loading = true
			this.error = null
			this.routeParam = ''
			try {
				this.page = await fetchPage(route, this.portalSlug)
			} catch (error) {
				// A ROUTE CAN ADDRESS A THING RATHER THAN A PAGE.
				//
				// `/publicatie/8f21…` is one page and thousands of subjects; no
				// CMS holds a page per publication. So an unresolved route
				// falls back to its PARENT and hands the trailing segment to
				// the page as `routeParam`, which is how `/publicatie/<id>`
				// resolves to the `/publicatie` page rendering that id.
				//
				// Only ONE level, and only on a 404. Walking all the way up
				// would make `/does/not/exist` render the home page — a
				// mistyped URL answering with content is worse than answering
				// with "not found", because nothing tells the visitor they are
				// in the wrong place.
				const parent = this.parentRoute(route)
				if (this.isNotFound(error) === true && parent !== null) {
					try {
						this.page = await fetchPage(parent, this.portalSlug)
						this.routeParam = route.slice(parent.length + 1)
						this.loading = false
						return
					} catch (parentError) {
						this.error = parentError
						this.page = null
						this.loading = false
						return
					}
				}

				this.page = null
				// A 404 is information, not a fault — an unknown route and an
				// unpublished page are answered identically by the API on
				// purpose, and both belong on screen as "not found".
				this.error = error
			} finally {
				this.loading = false
			}
		},

		/**
		 * The parent of a multi-segment route, or null when there is none.
		 *
		 * @param {string} route The in-site route.
		 * @return {string|null} The parent route.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		parentRoute(route) {
			const segments = String(route || '')
				.split('/')
				.filter(Boolean)

			if (segments.length < 2) {
				return null
			}

			return `/${segments.slice(0, -1).join('/')}`
		},

		/**
		 * Whether a load error was a 404 rather than a real failure.
		 *
		 * Checked explicitly: retrying the parent on a 500 would turn a broken
		 * backend into a page that renders, which hides the outage.
		 *
		 * @param {object} error The rejection from fetchPage.
		 * @return {boolean} True when the route simply does not exist.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		isNotFound(error) {
			return (error && error.status) === 404
		},

		/**
		 * Navigate within the site without a full page load.
		 *
		 * @param {string} link The target route.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Whether a link leaves this portal.
		 *
		 * An absolute URL to another origin is external; everything else is an
		 * in-site route this renderer handles itself. The distinction decides
		 * both the icon and whether the click is intercepted — calling
		 * `preventDefault` on an outbound link would strand the visitor on a
		 * dead control.
		 *
		 * @param {string} link The href.
		 * @return {boolean} True when it points off-site.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		isExternal(link) {
			return /^https?:\/\//i.test(String(link || ''))
		},

		/**
		 * Follow a footer link, in-site or out.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {string} link The href.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		onFooterLink(event, link) {
			if (this.isExternal(link)) {
				return
			}

			event.preventDefault()
			this.go(link)
		},

		/**
		 * Navigate to an in-site route without leaving the document.
		 *
		 * The counterpart to `onPopState`: this one pushes the entry, that one
		 * consumes it, and both end in `loadRoute` so forward and back render
		 * by the same path.
		 *
		 * ONLY ROOT-RELATIVE LINKS ARE FOLLOWED HERE. Anything else — an
		 * absolute URL, a `mailto:`, a protocol-relative `//host` — is left to
		 * the browser, which is the only thing entitled to leave this origin.
		 *
		 * @param {string} link The in-portal route, e.g. `/begrippen`.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * A real, shareable href for an in-site route.
		 *
		 * The breadcrumb intercepts the click, but the anchor still carries a
		 * working URL so middle-click, "open in new tab" and a page whose
		 * bundle failed to load all behave.
		 *
		 * @param {string} route The in-site route.
		 * @return {string} The href.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Take a term typed into the home page's hero straight to the results.
		 *
		 * The term travels in `_search`, which is the parameter the search
		 * block reads on mount — so the landing page's box and the search
		 * page's box produce the same URL, and that URL is shareable.
		 *
		 * @param {string} term The submitted term.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		goSearch(term) {
			const url = new URL(window.location.href)
			url.search = ''
			url.searchParams.set('route', this.searchRoute)
			if (term) {
				url.searchParams.set('_search', term)
			}

			this.route = this.searchRoute
			window.history.pushState({}, '', url)
			this.loadRoute(this.searchRoute)
		},

		hrefForRoute(route) {
			const url = new URL(window.location.href)
			url.search = ''
			if (route && route !== '/') {
				url.searchParams.set('route', route)
			}

			return url.toString()
		},

		go(link) {
			if (!link || !link.startsWith('/')) {
				return
			}

			this.route = link
			const url = new URL(window.location.href)
			url.searchParams.set('route', link)
			window.history.pushState({}, '', url)
			this.loadRoute(link)
		},
	},
}
</script>

<!--
	THE PAGE SHELL. Unscoped on purpose: these rules target Nextcloud's own
	`body` and `#content`, which are not this component's elements and which a
	scoped block therefore cannot reach.

	MEASURED on a live instance before this block existed — a white-label
	government portal was rendering as a 451px-wide white column floating on
	Nextcloud's blue theming WALLPAPER:

	    body      position: fixed; background: rgb(0,103,158)
	                                url(/apps/theming/img/background/jo-my…)
	    #content  position: fixed; margin: 50px 8px 8px      <- app chrome
	    .pq-site  451px of 1264    <- shrink-wrapped: NC makes #content a flex
	                                  container and the site never claimed width

	Removing the header (RENDER_AS_BASE) took away the bar at the top and left
	all of that behind. A visitor still saw Nextcloud — just without its name
	on it, which is arguably worse than the header was.

	So the public site resets the page it is served in: no wallpaper, no fixed
	positioning, no app-chrome offsets, full width. Guarded by `.layout-base`
	and `#content.app-public` so it can only ever apply on the public site
	route, never to the admin SPA rendered by the same app.
-->
<style>
body.layout-base {
	position: static;
	width: 100%;
	min-height: 100vh;
	margin: 0;
	background-image: none;
	background-color: var(--nldesign-color-page-background, #fff);
	overflow: auto;
}

body.layout-base #content.app-public {
	position: static;
	display: block;
	width: 100%;
	max-width: none;
	min-height: 100vh;
	margin: 0;
	padding: 0;
	border-radius: 0;
	background: transparent;
}

body.layout-base .pq-site {
	width: 100%;
	min-height: 100vh;
}
</style>

<style scoped>
/*
 * THE THEME BRIDGE. Before this block the renderer read `--pq-*` variables
 * that NOTHING EVER SET, so every portal fell through to the same hardcoded
 * fallbacks and two differently-themed portals were pixel-identical. The
 * themiq token file was already loading; it was simply never consumed.
 *
 * Each `--pq-*` is now a CHAIN, not a single lookup, because the 44 themiq
 * themes do not share one vocabulary — `vng.css` and `venray.css` have
 * literally no token name in common. A single `var(--nldesign-color-text)`
 * would theme a third of the fleet and silently miss the rest. The chain ends
 * in the original hardcoded value, so a portal with no theme, or a theme that
 * defines none of these, renders exactly as it did before.
 *
 * Portaliq still defines NO tokens of its own (ADR-086 §6). It only decides
 * which themiq token a given surface reads.
 */
.pq-site {
	/*
	 * `--nldesign-font-family` FIRST, because it is the one the themes
	 * actually define. Measured: `vng.css` contains ZERO occurrences of
	 * `--nldesign-typography-sans-serif-font-family` and defines
	 * `--nldesign-font-family: 'Avenir', …, Roboto, …`. So this chain resolved
	 * to `system-ui` on every themed portal — the theme was loaded, consumed
	 * for colour, and silently ignored for type.
	 *
	 * This is the disjoint-vocabulary problem the comment above describes,
	 * caught in the block that was written to avoid it: I picked a token name
	 * without checking it against a real theme file.
	 */
	/*
	 * `--utrecht-document-font-family` FIRST, because that is the name the
	 * NLDS component CSS and the generated token sets actually agree on, and
	 * because WITHOUT it the portal inherits Nextcloud's own theme font.
	 *
	 * MEASURED on :8080: the vng token resolved correctly to `'Avenir',
	 * sans-serif` at the portal root, no loaded stylesheet set a font on
	 * `.logo-text` at all, and the heading still rendered in **Marianne** —
	 * inherited from Nextcloud's `lasuite.css`. A themed portal was wearing
	 * the host platform's typeface while every token was present and correct.
	 * The `--nldesign-*` names below stay as the fallback chain for themes
	 * that only define those.
	 */
	--pq-font-family: var(
		--utrecht-document-font-family,
		var(
			--nldesign-font-family,
			var(--nldesign-typography-sans-serif-font-family, system-ui, sans-serif)
		)
	);
	--pq-text-color: var(
		--nldesign-color-text,
		var(--nldesign-color-black, #1a1a1a)
	);
	--pq-heading-color: var(
		--nldesign-color-primary,
		var(--nldesign-color-text, #1a1a1a)
	);
	--pq-border-color: var(--nldesign-color-border, #d0d0d0);
	--pq-muted-color: var(--nldesign-color-text-muted, #6b6b6b);
	--pq-link-color: var(
		--nldesign-color-link,
		var(--nldesign-color-primary, #0b5cab)
	);
	/*
	 * NO GLOBAL FONT. The design system assigns type PER COMPONENT:
	 * measured on the reference, `.logo-text` is Avenir 36/700 while
	 * `.ac-c-navigation__label` is Roboto 16/500 — two families on one
	 * page, both token-driven. A blanket family here overrode the nav
	 * with Avenir 600 and left the bar 1px short of the reference.
	 *
	 * This declaration existed to beat Nextcloud's own `lasuite.css`
	 * (Marianne) while the portal rendered inside the NC shell. It does
	 * not render there any more, so the workaround outlived its cause.
	 */
	/*
	 * NO DOCUMENT COLOUR. This is the THIRD rule of this shape removed from
	 * this renderer, after `.pq-site :any-link` and MarkdownBlock's heading
	 * colour, and it failed the same way.
	 *
	 * It set `#333` on the whole portal. The design system does not: measured
	 * on the reference, `html`, `body` and every ancestor of a card are
	 * rgb(0, 0, 0) — the browser default — and text that should be grey gets
	 * there through `utrecht-paragraph`, per element, on a known surface.
	 *
	 * Inheriting #333 instead put card headings at rgb(51, 51, 51) against the
	 * design's rgb(0, 0, 0). The description matched only by coincidence: #333
	 * is what `utrecht-paragraph` sets anyway, so the blanket rule looked
	 * right everywhere it happened to agree and was wrong everywhere else.
	 */
	background: var(--pq-bg-color, #ffffff);
	min-height: 100vh;

	/*
	 * FULL BLEED. Nextcloud's `#content` gives the app a padded, inset
	 * column — correct for an app, wrong for a portal that is supposed to
	 * BE the page. Measured against the reference: its container is
	 * 1280x…@0 while ours rendered 1235 wide starting 50px down, so every
	 * band (header, nav, footer) was narrower and lower than the design.
	 * Reset the inherited box rather than fighting it per-element.
	 */
	margin: 0;
	padding: 0;
	width: 100%;
	max-width: none;
}

/*
 * THERE IS DELIBERATELY NO BLANKET LINK COLOUR HERE.
 *
 * `.pq-site :any-link { color: var(--pq-link-color) }` used to sit at this
 * spot. One selector, every anchor on the portal, one colour — and because a
 * scoped style loads last it beat every context-specific rule the design
 * system has.
 *
 * MEASURED in the footer: `nlds-app.css` says `.ac-footer a { color: inherit }`
 * so footer links take the band's white; ours computed rgb(0, 68, 136) against
 * a rgb(0, 69, 137) background. Those two colours are one step apart. The links
 * were not merely off-brand, they were INVISIBLE — a contrast failure on a
 * government portal, produced by a rule whose whole purpose was to make links
 * look right.
 *
 * The design system already colours links per context — `.ac-footer a`,
 * `.ac-c-navigation__*`, `.utrecht-link` — each against the background it
 * actually sits on, which is the only way contrast can be reasoned about. A
 * portal-wide override cannot know that background and so cannot be correct.
 *
 * `--pq-link-color` is still defined above and still used by `MarkdownBlock`,
 * which renders body copy on a known light surface.
 */

/*
 * The `.pq-site__skip` rules that sat here are gone with the element they
 * styled. They were a hand-rolled `left: -9999px` / `position: static on
 * focus` pair, and they could never have applied to the skip link's new home
 * anyway: these styles are SCOPED, so they carry a `data-v-*` attribute
 * selector that markup emitted by `templates/site.php` does not have.
 *
 * `@utrecht/skip-link-css` — already imported by `main.js` — does the job
 * properly. MEASURED on the server-rendered link: `position: fixed`, parked at
 * y=-1440 while unfocused and snapping to y=0 on focus. The off-left trick
 * this file used is the older, worse version of that; some screen readers
 * treat a far-off-screen element as decorative.
 */

/*
 * NO LAYOUT HERE. These elements now also carry the reference implementation's
 * `ac-header` / `ac-app-main` / `ac-footer` classes, and `nlds-app.css` styles
 * them. Anything this file says about their box wins — scoped styles carry a
 * data-attribute and load last — so every rule below was silently overriding
 * the design system it was supposed to be adopting.
 *
 * MEASURED, ours against the reference, before this block was removed:
 *
 *     .ac-header                 padding  0        <- 24px 20px   (this file)
 *     .ac-header__navigation-main  width  1280px   <- 1232px      (consequence)
 *     .ac-app-main               width    1280px   <- 1088px, max-width 1088
 *     .ac-footer                 padding  0        <- 24px 16px
 *
 * Every delta traced back here, not to a missing rule in the vendored CSS. So
 * the fix is deletion: let the design system own the layout, and keep this
 * file for what is genuinely portal-specific.
 */

dt {
	font-weight: 600;
	margin-top: 0.75rem;
}

dd {
	margin: 0.25rem 0 0;
}

/*
 * THE BREADCRUMB IS ONE LINE.
 *
 * `nlds-app.css` carries no rule for `.ac-breadcrumb`, so its list items were
 * block-level and the separator dropped onto a line of its own — a three-line
 * trail where the reference has one.
 */
.ac-breadcrumb__list {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.ac-breadcrumb__item {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
