<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-site" :class="themeClass" data-testid="site-root">
		<a class="pq-site__skip" href="#pq-main">Direct naar de inhoud</a>

		<header class="pq-site__header" data-testid="site-header">
			<h1 class="pq-site__title" data-testid="site-title">
				{{ site.title || '…' }}
			</h1>

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

			<article v-else-if="page" data-testid="site-page">
				<h2 data-testid="page-title">
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
import { fetchGlossary, fetchMenus, fetchPage, fetchSite } from './lib/contentApi.js'

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
			}
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

<style scoped>
.pq-site {
	font-family: var(--pq-font-family, system-ui, sans-serif);
	color: var(--pq-text-color, #1a1a1a);
	background: var(--pq-bg-color, #ffffff);
	min-height: 100vh;
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
