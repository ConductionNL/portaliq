<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalHomePage: whether this portal has a home page, as a widget on the
  portal's own page (portaliq-cms).

  A home page is a page of this portal whose route is `/` and whose status is
  `published`. The portal root stays a CMS page slot and still answers not
  found, so nothing here changes what a visitor gets. What it changes is that
  the state is reported where the portal is configured, instead of being found
  by a visitor who is signed in and still reads "page not found".

  Three states, from PortalHomePageController: no page at the root at all, a
  draft at the root, or a published page. The first two are configuration
  errors and say what to do about them. Nothing is blocked: a portal is
  routinely configured before its pages exist.

  @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
-->
<template>
	<div class="portal-home-page" data-testid="portal-home-page">
		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-else :type="type" :data-testid="`portal-home-page-${state}`">
			<p class="portal-home-page__headline">{{ headline }}</p>
			<p v-if="consequence" class="portal-home-page__consequence">
				{{ consequence }}
			</p>
			<p v-if="meaning" class="portal-home-page__meaning">{{ meaning }}</p>
			<p v-if="remedy" class="portal-home-page__remedy">{{ remedy }}</p>
			<NcButton
				v-if="target"
				variant="secondary"
				data-testid="portal-home-page-open"
				@click="open">
				{{ openLabel }}
			</NcButton>
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	homePageState,
	homePageTarget,
	homePageUrl,
	noteType,
	ROOT_ROUTE,
} from '../lib/portalHomePage.js'

export default {
	name: 'PortalHomePage',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			answer: null,
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},

		/**
		 * The portal's name, so the report says which portal it is about.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		portalTitle() {
			return String(this.objectData?.title || this.slug)
		},

		/**
		 * Which of the three states the server answered.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		state() {
			return homePageState(this.answer)
		},

		/**
		 * The note card type for that state.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		type() {
			return noteType(this.state)
		},

		/**
		 * The page this report can open, or null when there is none.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		target() {
			return homePageTarget(this.answer)
		},

		/**
		 * What is wrong, or that nothing is.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		headline() {
			if (this.state === 'published') {
				return t('portaliq', '{portal} has a published home page.', {
					portal: this.portalTitle,
				})
			}

			if (this.state === 'draft') {
				return t(
					'portaliq',
					'{portal} has a home page, but it is still a draft.',
					{ portal: this.portalTitle },
				)
			}

			return t('portaliq', '{portal} has no home page.', {
				portal: this.portalTitle,
			})
		},

		/**
		 * What a visitor gets because of it.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		consequence() {
			if (this.state === 'published') {
				return ''
			}

			if (this.state === 'draft') {
				return t(
					'portaliq',
					'A draft is not served, so the portal address says the page does not exist.',
				)
			}

			return t(
				'portaliq',
				'Anyone who opens the portal address is told the page does not exist.',
			)
		},

		/**
		 * What a home page is, in the terms an editor acts on.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		meaning() {
			if (this.state === 'published') {
				return ''
			}

			return t(
				'portaliq',
				'A home page is a page of this portal with route / and status published.',
			)
		},

		/**
		 * What to do about it.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		remedy() {
			if (this.state === 'published') {
				return ''
			}

			if (this.state === 'draft') {
				return t('portaliq', 'Publish {page} to put it live.', {
					page: this.draftName,
				})
			}

			return t('portaliq', 'Add a page with route /, then publish it.')
		},

		/**
		 * The draft page's name, or its route when it has no title yet.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		draftName() {
			return String(this.answer?.pageTitle || ROOT_ROUTE)
		},

		/**
		 * The label on the way out of the report.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		openLabel() {
			if (this.state === 'draft') {
				return t('portaliq', 'Open this page')
			}

			return t('portaliq', 'Open the pages of this portal')
		},
	},

	watch: {
		/**
		 * Check again when the detail page hands over another portal.
		 *
		 * @return {void}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		slug() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the portal's home-page state.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		async load() {
			this.loading = true
			this.error = ''
			if (this.slug === '') {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(homePageUrl(this.slug, generateUrl))
				this.answer = data.homePage || null
			} catch {
				this.error = t(
					'portaliq',
					'The home page of this portal could not be checked.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the draft at the root, or this portal's pages.
		 *
		 * @return {void}
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
		 */
		open() {
			if (this.target && this.$router) {
				this.$router.push(this.target)
			}
		},
	},
}
</script>

<style scoped>
.portal-home-page {
	padding: calc(var(--default-grid-baseline) * 2);
}

.portal-home-page__headline {
	font-weight: bold;
}

.portal-home-page__meaning,
.portal-home-page__remedy {
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
