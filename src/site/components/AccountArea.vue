<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE SIGNED-IN AREA (`/mijn/...`). Signed out it is the way in: the
		portal's sign-in routes, and the dev login only where the server accepts
		it. Signed in it renders the page the menu entry names, through
		pages/registry.js, so a section whose page is not built yet still shows
		up and says so.
	-->
	<section class="container pq-account" data-testid="site-account">
		<p v-if="!sessionKnown" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>

		<div v-else-if="!session" data-testid="site-account-signin">
			<h1 class="utrecht-heading-2">
				{{ t('Welcome') }}
			</h1>
			<p class="utrecht-paragraph">
				{{ t('Log in to view your information.') }}
			</p>
			<ul v-if="signInRoutes.length" class="pq-account__ways-in">
				<li v-for="way in signInRoutes" :key="way.mode">
					<a
						class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
						:href="way.href"
						:data-mode="way.mode"
						data-testid="site-account-signin-route">
						{{ way.label }}
					</a>
				</li>
			</ul>
			<p v-else class="utrecht-paragraph">
				{{ t('No login method is configured for this organisation yet.') }}
			</p>
			<button
				v-if="devLogin"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="site-devlogin"
				@click="$emit('devlogin')">
				{{ t('Dev-login (test)') }}
			</button>
			<p v-if="devError" class="utrecht-paragraph" role="alert">
				{{ devError }}
			</p>
		</div>

		<p v-else-if="loading" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>

		<p
			v-else-if="nav.length === 0"
			class="utrecht-paragraph"
			data-testid="site-account-empty">
			{{ t('No contributions to show yet.') }}
		</p>

		<template v-else-if="entry">
			<h1
				id="site-account-title"
				class="utrecht-heading-2"
				data-testid="site-account-title">
				{{ entry.label }}
			</h1>
			<p v-if="pageLoading" class="utrecht-paragraph" role="status">
				{{ t('Loading…') }}
			</p>
			<component
				:is="pageComponent"
				v-else-if="pageComponent"
				:key="entry.key"
				v-bind="pageProps"
				@navigate="$emit('navigate', $event)"
				@unread="$emit('unread', $event)"
				@refresh="$emit('refresh')"
				@removed="$emit('signout')" />
		</template>
	</section>
</template>

<script>
import { markRaw } from 'vue'
import PlaceholderPage from '../pages/PlaceholderPage.vue'
import { navKeyFor, OPEN_STORAGE_KEY } from '../../shared/openRecord.js'
import { routeForNav } from '../../shared/portalNav.js'
import { sitePageLoader } from '../pages/registry.js'

/**
 * The names a component declares as props, whether as an array or an object.
 *
 * @param {object} component The component.
 * @return {Array<string>} The prop names.
 */
function declaredProps(component) {
	const props = component && component.props
	if (Array.isArray(props)) {
		return props
	}
	return props ? Object.keys(props) : []
}

/**
 * The signed-in area of the site renderer.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export default {
	name: 'AccountArea',

	props: {
		/** Whether the session has been read; until then nothing is decided. */
		sessionKnown: { type: Boolean, default: false },
		/** The resident's session, or null. */
		session: { type: Object, default: null },
		/** Whether the contributions are still loading. */
		loading: { type: Boolean, default: false },
		/** The signed-in navigation. */
		nav: { type: Array, default: () => [] },
		/** The entry the route names, or null. */
		entry: { type: Object, default: null },
		/** The contributions aggregate, or null. */
		contributions: { type: Object, default: null },
		/** The shared portal API bound to the site's bearer. */
		api: { type: Object, default: null },
		/** The portal's sign-in routes. */
		signInRoutes: { type: Array, default: () => [] },
		/** Whether the server accepts the dev login. */
		devLogin: { type: Boolean, default: false },
		/** Why the dev login did not work, or ''. */
		devError: { type: String, default: '' },
		/** The site translator. */
		t: { type: Function, required: true },
		/** The site language. */
		locale: { type: String, default: 'nl' },
		/** The portal record from the content API. */
		portal: { type: Object, default: null },
	},

	emits: ['devlogin', 'navigate', 'unread', 'refresh', 'signout'],

	data() {
		return {
			// The page module for the entry on screen, loaded on demand, and
			// whether it is still loading. Loaded here rather than through
			// defineAsyncComponent so the props it declares are known: a page
			// gets only those, and nothing lands on its DOM as an attribute.
			pageComponent: null,
			pageLoading: false,
		}
	},

	computed: {
		/**
		 * The page contract (pages/registry.js), narrowed to what the page
		 * on screen declares.
		 *
		 * @return {object} The props.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		pageProps() {
			const contract = {
				entry: this.entry,
				page: this.entry && this.entry.page,
				contribution: this.entry && this.entry.contribution,
				api: this.api,
				session: this.session,
				portal: this.portal,
				contributions: this.contributions,
				nav: this.nav,
				t: this.t,
				locale: this.locale,
				navigate: (target) => this.$emit('navigate', target),
				closedMarker: this.contributions?.cases?.closedMarker === true,
				canOpen: (target) => navKeyFor(this.nav, target) !== null,
				openCase: (target, row) => this.openCase(target, row),
			}
			const wanted = declaredProps(this.pageComponent)
			return Object.fromEntries(
				Object.entries(contract).filter(([name]) => wanted.includes(name)),
			)
		},
	},

	watch: {
		entry: {
			immediate: true,
			handler() {
				this.loadPage()
			},
		},
	},

	methods: {
		/**
		 * Load the component for the entry on screen: the registered page,
		 * else the placeholder.
		 *
		 * @return {Promise<void>} Resolves when the page is set.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		async loadPage() {
			const entry = this.entry
			const loader = sitePageLoader(entry)
			if (!loader) {
				this.pageComponent = markRaw(PlaceholderPage)
				this.pageLoading = false
				return
			}
			this.pageLoading = true
			let component = PlaceholderPage
			try {
				const module = await loader()
				component = module.default || module
			} catch {
				// A page that does not load says it is not available here.
			}
			if (entry !== this.entry) {
				return
			}
			this.pageComponent = markRaw(component)
			this.pageLoading = false
		},

		/**
		 * Open a case from my cases on the page of the app it belongs to,
		 * the way a record link does: kept for the page, then routed to.
		 *
		 * @param {{app: string, collection: string, id: string}} target The case.
		 * @param {object} [row] The case row, so a case read under a mandate opens.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-record-link-must-open-its-record-after-sign-in-req-srp-021
		 */
		openCase(target, row) {
			const key = navKeyFor(this.nav, target)
			const entry = this.nav.find((candidate) => candidate.key === key)
			if (!entry) {
				return
			}
			try {
				window.sessionStorage.setItem(
					OPEN_STORAGE_KEY,
					JSON.stringify({
						app: target.app,
						collection: target.collection,
						id: target.id,
						row: row || null,
					}),
				)
			} catch {
				// Without storage the page opens without the case selected.
			}
			this.$emit('navigate', routeForNav(entry))
		},
	},
}
</script>

<style scoped>
.pq-account {
	padding-block: 24px;
}

.pq-account__ways-in {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}
</style>
