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

			<div class="ac-header__navigation-breadcrumb">
				<div class="container" />
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

				<article
					v-else-if="page"
					class="utrecht-article"
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
						:widgets="page.body.widgets || []" />

					<div v-else class="container">
						<MarkdownBlock
							data-testid="page-markdown"
							:source="(page.body && page.body.markdown) || ''" />
					</div>
				</article>

				<section
					v-if="showGlossary"
					class="container"
					data-testid="site-glossary">
					<h2>Begrippenlijst</h2>
					<dl>
						<template v-for="term in glossary" :key="term.term">
							<dt data-testid="glossary-term">
								{{ term.term }}
							</dt>
							<dd>
								{{ term.definition }}
								<em v-if="term.synonyms && term.synonyms.length">
									(ook: {{ term.synonyms.join(', ') }})
								</em>
							</dd>
						</template>
					</dl>
				</section>

				<!--
				Contributed surfaces (ADR-046). Rendered as an INDEX, not as
				live data: each entry names a collection or an action a leaf
				app publishes on this portal, and following it is what fetches
				anything. That distinction is the whole safety story here —
				this section is built from a publicly cacheable, anonymous
				response, so it must never itself carry a visitor's rows.
			-->
				<section
					v-if="contributions.length"
					class="pq-site__contributions"
					data-testid="site-contributions">
					<h2>Diensten</h2>
					<div
						v-for="contribution in contributions"
						:key="contribution.app"
						class="pq-site__contribution"
						:data-testid="`contribution-${contribution.app}`"
						:data-app="contribution.app">
						<h3>{{ contribution.label || contribution.app }}</h3>
						<ul>
							<li
								v-for="entry in entriesOf(contribution)"
								:key="entry.id || entry.label"
								data-testid="contribution-entry">
								{{ entry.label || entry.id }}
							</li>
						</ul>
					</div>
				</section>
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
								<a
									:href="item.link"
									@click.prevent="go(item.link)"
									>{{ item.name }}</a
								>
							</li>
						</ul>
					</nav>

					<div class="ac-footer__logo">
						<div class="con-logo-container footer" />
						<span>
							<span>{{ site.title }}</span>
						</span>
					</div>
				</div>
			</section>

			<section class="ac-footer__sub-footer">
				<div class="container">
					<p data-testid="site-footer-colophon">{{ site.title }}</p>
				</div>
			</section>
		</footer>
	</div>
</template>

<script>
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

	components: { MarkdownBlock, SiteMenu, WidgetGrid },

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
		bodyProvidesHeading() {
			const body = this.page.body || {}
			if (body.type !== 'grid') {
				return false
			}

			return (body.widgets || []).some((w) => w.widgetKey === 'hero')
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
			return this.menus.filter((menu) => (menu.position || 0) !== 0)
		},

		/**
		 * @return {boolean} Whether the glossary section is shown.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		showGlossary() {
			return this.route === '/begrippen' && this.glossary.length > 0
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
		 * The entries of one contribution, as an index of what it offers.
		 *
		 * Collections and actions are shown together on purpose. To a visitor
		 * "my invoices" and "submit a declaration" are two things the portal
		 * can do; the split between reading a collection and invoking an
		 * action is an implementation detail of the contract, not a category
		 * a citizen recognises.
		 *
		 * @param {object} contribution One contribution.
		 * @return {Array} Its collections and actions.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
		 */
		entriesOf(contribution) {
			const collections = Array.isArray(contribution.collections)
				? contribution.collections
				: []
			const actions = Array.isArray(contribution.actions)
				? contribution.actions
				: []

			return [...collections, ...actions]
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
			try {
				this.page = await fetchPage(route, this.portalSlug)
			} catch (error) {
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
		 * Navigate within the site without a full page load.
		 *
		 * @param {string} link The target route.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
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
	color: var(--pq-text-color);
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
</style>
