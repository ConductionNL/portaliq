<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The page for a route that does not exist or is not published, drawn on the
	Zuiddrecht board NietGevonden (contact-page-question-form-and-not-found).

	An unpublished page and a route that never existed get THIS page and
	nothing else: the answer carries no word that tells them apart. The
	`data-portaliq-status` and `data-portaliq-path` attributes stay on the
	wrapper because the traffic collector counts missing pages by them.
-->
<template>
	<div
		class="container pq-not-found"
		role="alert"
		data-testid="site-error"
		data-portaliq-status="404"
		:data-portaliq-path="path">
		<p class="utrecht-paragraph pq-not-found__code" data-testid="not-found-code">
			{{ t('Error code 404') }}
		</p>
		<h2 class="utrecht-heading-2">
			{{ t('Page not found') }}
		</h2>
		<p class="utrecht-paragraph">
			{{ t('This page does not exist (any more). Maybe the address was typed wrong, or we moved the page.') }}
		</p>

		<form
			v-if="view.search"
			class="pq-not-found__search"
			role="search"
			data-testid="not-found-search"
			@submit.prevent="onSearch">
			<label class="utrecht-form-label" for="pq-not-found-term">{{
				t('Search for what you need')
			}}</label>
			<input
				id="pq-not-found-term"
				v-model="term"
				class="utrecht-textbox utrecht-textbox--html-input"
				type="search"
				name="q"
				autocomplete="off" />
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action">
				{{ t('Search') }}
			</button>
		</form>

		<p class="utrecht-paragraph">
			{{ t('Or go on to') }}
		</p>
		<ul class="pq-not-found__links" data-testid="not-found-links">
			<li v-for="link in view.links" :key="link.kind">
				<a
					:href="hrefFor(link.route)"
					:data-testid="`not-found-${link.kind}`"
					@click.prevent="$emit('navigate', link.route)">{{ link.label }}</a>
			</li>
		</ul>

		<p
			v-if="view.report"
			class="utrecht-paragraph"
			data-testid="not-found-report">
			{{ view.report }}
		</p>
	</div>
</template>

<script>
import { notFoundView } from '../lib/notFound.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/form-label-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

/**
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
 */
export default {
	name: 'NotFoundPage',

	props: {
		/** The route that was asked for, kept for the traffic collector. */
		path: { type: String, default: '' },
		/** The portal's contact route. */
		contactRoute: { type: String, default: '/contact' },
		/** The portal's published page summaries, or null while they load. */
		pages: { type: Array, default: null },
		/** Whether the portal has a resident area. */
		hasResidentArea: { type: Boolean, default: false },
		/** The resident area's name. */
		residentLabel: { type: String, default: '' },
		/** Whether the portal has search. */
		searchEnabled: { type: Boolean, default: false },
		/** The address of an in-site route, so a link opens in a new tab. */
		hrefForRoute: { type: Function, default: (route) => route },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['navigate', 'search'],

	data() {
		return { term: '' }
	},

	computed: {
		/**
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		view() {
			return notFoundView({
				contactRoute: this.contactRoute,
				pages: this.pages,
				hasResidentArea: this.hasResidentArea,
				residentLabel: this.residentLabel,
				searchEnabled: this.searchEnabled,
				t: this.t,
			})
		},
	},

	methods: {
		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its address.
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		hrefFor(route) {
			return this.hrefForRoute(route)
		},

		/**
		 * Hand a typed term to the shell, which opens the search page.
		 *
		 * @return {void}
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
		 */
		onSearch() {
			const term = this.term.trim()
			if (term !== '') {
				this.$emit('search', term)
			}
		},
	},
}
</script>

<style scoped>
.pq-not-found__code {
	color: var(--utrecht-color-grey-600, #5f6368);
	font-weight: 600;
}

.pq-not-found__search {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: end;
	margin-block: var(--utrecht-space-block-md, 1rem);
}

.pq-not-found__links {
	margin-block: 0 var(--utrecht-space-block-md, 1rem);
}
</style>
