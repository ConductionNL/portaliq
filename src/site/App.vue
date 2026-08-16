<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-site" :class="themeClass" data-testid="site-root">
		<a
			class="utrecht-skip-link utrecht-skip-link--visible-on-focus pq-site__skip"
			href="#pq-main"
			>Direct naar de inhoud</a
		>

		<header
			class="utrecht-page-header pq-site__header"
			data-testid="site-header">
			<h1 class="utrecht-heading-1 pq-site__title" data-testid="site-title">
				{{ site.title || '…' }}
			</h1>

			<!--
				The sign-in affordance appears ONLY when the portal declares a
				mode other than `public`. A portal with no accounts must show
				no login button: an inert one is a support ticket from every
				visitor who presses it.

				This offers the door. It does not guard anything — per-portal
				authentication is declared in the schema and enforced nowhere
				yet, and nothing here should be read as if it were a gate.
			-->
			<div
				v-if="session || signInRoutes.length"
				class="pq-site__auth"
				data-testid="site-auth">
				<template v-if="session">
					<span data-testid="site-auth-subject">{{ sessionLabel }}</span>
					<button
						type="button"
						data-testid="site-signout"
						@click="signOut">
						Uitloggen
					</button>
				</template>
				<a
					v-for="entry in signInRoutes"
					v-else
					:key="entry.mode"
					:href="entry.href"
					:data-mode="entry.mode"
					data-testid="site-signin">
					{{ entry.label }}
				</a>
			</div>

			<SiteMenu
				v-for="menu in menus"
				:key="menu.title"
				:menu="menu"
				:currentRoute="route"
				@navigate="go" />
		</header>

		<main id="pq-main" class="pq-site__main">
			<p v-if="loading" data-testid="site-loading">Bezig met laden…</p>

			<!-- A failed load says so. Rendering an empty page instead would
			     make a broken deployment look exactly like an empty site — the
			     one confusion this surface can least afford. -->
			<div v-else-if="error" role="alert" data-testid="site-error">
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
				<h2 class="utrecht-heading-2" data-testid="page-title">
					{{ page.title }}
				</h2>

				<WidgetGrid
					v-if="page.body && page.body.type === 'grid'"
					:widgets="page.body.widgets || []" />

				<MarkdownBlock
					v-else
					data-testid="page-markdown"
					:source="(page.body && page.body.markdown) || ''" />
			</article>

			<section v-if="showGlossary" data-testid="site-glossary">
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
		</main>

		<footer class="pq-site__footer" data-testid="site-footer">
			<p>{{ site.title }}</p>
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
	--pq-font-family: var(
		--nldesign-font-family,
		var(--nldesign-typography-sans-serif-font-family, system-ui, sans-serif)
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
	font-family: var(--pq-font-family);
	color: var(--pq-text-color);
	background: var(--pq-bg-color, #ffffff);
	min-height: 100vh;
}

.pq-site :any-link {
	color: var(--pq-link-color);
}

.pq-site__skip {
	position: absolute;
	left: -9999px;
}

.pq-site__skip:focus {
	position: static;
	display: inline-block;
	padding: 0.5rem 1rem;
}

.pq-site__header {
	border-bottom: 1px solid var(--pq-border-color, #d0d0d0);
	padding: 1.25rem 1.5rem;
}

.pq-site__title {
	color: var(--pq-heading-color, #1a1a1a);
	margin: 0 0 0.75rem;
	font-size: 1.75rem;
}

.pq-site__main {
	padding: 1.5rem;
	max-width: 68rem;
}

.pq-site__footer {
	border-top: 1px solid var(--pq-border-color, #d0d0d0);
	padding: 1rem 1.5rem;
	color: var(--pq-muted-color, #6b6b6b);
}

dt {
	font-weight: 600;
	margin-top: 0.75rem;
}

dd {
	margin: 0.25rem 0 0;
}
</style>
