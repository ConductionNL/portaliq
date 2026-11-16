<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PortalNavigation: which contributed pages one portal's menu shows, per
  audience, and in which order, as a section on the portal's own page.

  Lists per audience the pages the installed apps contribute, each with a
  "Show in this portal" switch and up and down buttons. Saving writes the
  portal's navigation through PortalNavigationController, which is
  admin-only. Hiding a page here is not access control: the records stay
  readable by an account that may see them on another portal, so the
  section says so under its title and points to the account's own choice.

  @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
-->
<template>
	<div class="portal-navigation" data-testid="portal-navigation">
		<p class="portal-navigation__intro">
			{{
				t(
					'portaliq',
					"Hidden pages are left out of this portal's menu. To stop one client reading them, hide them on the client's account.",
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p v-if="audiences.length === 0" class="portal-navigation__empty">
				{{ t('portaliq', 'No installed app contributes pages to the portal yet.') }}
			</p>

			<section
				v-for="group in audiences"
				:key="group.audience"
				class="portal-navigation__audience"
				:data-testid="`portal-navigation-${group.audience}`">
				<h3 class="portal-navigation__heading">
					{{ t('portaliq', 'Pages for {audience}', { audience: group.audience }) }}
				</h3>
				<p v-if="group.pages.length === 0" class="portal-navigation__empty">
					{{ t('portaliq', 'No page for this audience.') }}
				</p>
				<ol v-else class="portal-navigation__list">
					<li
						v-for="(page, index) in group.pages"
						:key="page.page"
						class="portal-navigation__row"
						:class="{ 'portal-navigation__row--hidden': page.hidden }"
						:data-testid="`portal-navigation-page-${page.page}`">
						<span
							:id="labelId(group, page)"
							class="portal-navigation__label">
							{{ page.label }}
							<span class="portal-navigation__app">{{ page.appLabel }}</span>
						</span>
						<NcCheckboxRadioSwitch
							:modelValue="!page.hidden"
							type="switch"
							:aria-describedby="labelId(group, page)"
							@update:modelValue="(value) => setShown(page, value)">
							{{ t('portaliq', 'Show in this portal') }}
						</NcCheckboxRadioSwitch>
						<span class="portal-navigation__moves">
							<NcButton
								variant="tertiary"
								:disabled="index === 0"
								:aria-label="t('portaliq', 'Move {page} up', { page: page.label })"
								:data-testid="`portal-navigation-up-${page.page}`"
								@click="move(group, index, -1)">
								<template #icon>
									<ArrowUp :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="index === group.pages.length - 1"
								:aria-label="t('portaliq', 'Move {page} down', { page: page.label })"
								:data-testid="`portal-navigation-down-${page.page}`"
								@click="move(group, index, 1)">
								<template #icon>
									<ArrowDown :size="20" />
								</template>
							</NcButton>
						</span>
					</li>
				</ol>
			</section>

			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>

			<NcButton
				v-if="audiences.length > 0"
				variant="primary"
				:disabled="saving || !dirty"
				data-testid="portal-navigation-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import {
	copyAudiences,
	movePage,
	navigationFrom,
	navigationUrl,
} from '../lib/portalNavigation.js'

export default {
	name: 'PortalNavigation',

	components: {
		ArrowDown,
		ArrowUp,
		NcButton,
		NcCheckboxRadioSwitch,
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
			saved: [],
			audiences: [],
			loading: true,
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},

		/**
		 * Whether the order or a switch differs from what is stored.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		dirty() {
			return (
				JSON.stringify(navigationFrom(this.audiences))
				!== JSON.stringify(navigationFrom(this.saved))
			)
		},
	},

	watch: {
		/**
		 * Load again when the detail page hands over another portal.
		 *
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
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
		 * Read the portal's navigation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		async load() {
			this.loading = true
			this.error = ''
			if (this.slug === '') {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(navigationUrl(this.slug, generateUrl))
				this.accept(data.audiences || [])
			} catch {
				this.error = t('portaliq', 'The pages of this portal could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take a list from the server as the stored state.
		 *
		 * @param {Array<object>} audiences The audiences with their pages.
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		accept(audiences) {
			this.saved = copyAudiences(audiences)
			this.audiences = copyAudiences(audiences)
		},

		/**
		 * The id of a row's label, for the switch to point at.
		 *
		 * @param {object} group The audience.
		 * @param {object} page The page.
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		labelId(group, page) {
			return `portal-navigation-label-${group.audience}-${page.page}`.replace(/[^A-Za-z0-9_-]/g, '-')
		},

		/**
		 * Flip one switch.
		 *
		 * @param {object} page The page.
		 * @param {boolean} value Shown or not.
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		setShown(page, value) {
			page.hidden = !value
			this.notice = ''
		},

		/**
		 * Move one page up or down within its audience.
		 *
		 * @param {object} group The audience.
		 * @param {number} index The page's place.
		 * @param {number} step -1 for up, +1 for down.
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		move(group, index, step) {
			group.pages = movePage(group.pages, index, step)
			this.notice = ''
		},

		/**
		 * Save the navigation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(navigationUrl(this.slug, generateUrl), {
					navigation: navigationFrom(this.audiences),
				})
				this.accept(data.audiences || [])
				this.notice = t('portaliq', 'Your choices are saved.')
			} catch {
				this.error = t('portaliq', 'Your choices could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.portal-navigation {
	padding: calc(var(--default-grid-baseline) * 2);
}

.portal-navigation__audience {
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.portal-navigation__heading {
	font-size: var(--default-font-size);
	font-weight: bold;
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
}

.portal-navigation__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.portal-navigation__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.portal-navigation__row--hidden .portal-navigation__label {
	color: var(--color-text-maxcontrast);
}

.portal-navigation__label {
	display: flex;
	flex-direction: column;
	flex: 1 1 200px;
	font-weight: bold;
}

.portal-navigation__app {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.portal-navigation__moves {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
