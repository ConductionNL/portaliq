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
			<h1 class="utrecht-heading-2" data-testid="site-account-title">
				{{ entry.label }}
			</h1>
			<component
				:is="pageComponent"
				:key="entry.key"
				:entry="entry"
				:api="api"
				:session="session"
				:contributions="contributions"
				:nav="nav"
				:t="t"
				:locale="locale"
				@navigate="$emit('navigate', $event)"
				@unread="$emit('unread', $event)"
				@refresh="$emit('refresh')" />
		</template>
	</section>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import PlaceholderPage from '../pages/PlaceholderPage.vue'
import { sitePageLoader } from '../pages/registry.js'

/**
 * One async component per loader, so moving between two sections that share
 * a page does not download or remount it twice.
 *
 * @type {WeakMap<() => Promise<object>, object>}
 */
const ASYNC_PAGES = new WeakMap()

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
	},

	emits: ['devlogin', 'navigate', 'unread', 'refresh'],

	computed: {
		/**
		 * The component for the entry on screen: the registered page, else
		 * the placeholder.
		 *
		 * @return {object} The component.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		pageComponent() {
			const loader = sitePageLoader(this.entry)
			if (!loader) {
				return PlaceholderPage
			}
			if (!ASYNC_PAGES.has(loader)) {
				ASYNC_PAGES.set(loader, defineAsyncComponent(loader))
			}
			return ASYNC_PAGES.get(loader)
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
