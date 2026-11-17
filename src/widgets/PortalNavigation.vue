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
	<div class="page-choice" data-testid="portal-navigation">
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
			<p v-if="items.length === 0" class="portal-navigation__empty">
				{{ t('portaliq', 'No installed app contributes pages to the portal yet.') }}
			</p>

			<section
				v-for="group in items"
				:key="group.audience"
				class="portal-navigation__audience"
				:data-testid="`portal-navigation-${group.audience}`">
				<h3 class="portal-navigation__heading">
					{{ t('portaliq', 'Pages for {audience}', { audience: group.audience }) }}
				</h3>
				<p v-if="group.pages.length === 0" class="portal-navigation__empty">
					{{ t('portaliq', 'No page for this audience.') }}
				</p>
				<ol v-else class="page-choice__list portal-navigation__list">
					<li
						v-for="(page, index) in group.pages"
						:key="page.page"
						class="page-choice__row"
						:class="{ 'page-choice__row--hidden': page.hidden }"
						:data-testid="`portal-navigation-page-${page.page}`">
						<span
							:id="labelId(group, page)"
							class="page-choice__label">
							{{ page.label }}
							<span class="page-choice__app">{{ page.appLabel }}</span>
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
				v-if="items.length > 0"
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
import { pageChoiceEditor } from './pageChoiceEditor.js'

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

	mixins: [
		pageChoiceEditor({
			key: 'audiences',
			field: 'navigation',
			url: navigationUrl,
			shape: navigationFrom,
			copy: copyAudiences,
			loadError: () => t('portaliq', 'The pages of this portal could not be loaded.'),
		}),
	],

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
		 */
		owner() {
			return String(this.objectData?.slug || '')
		},
	},

	methods: {
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
			this.edited()
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
			this.edited()
		},
	},
}
</script>

<style scoped src="./pageChoice.css"></style>

<style scoped>
.portal-navigation__audience {
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.portal-navigation__heading {
	font-size: var(--default-font-size);
	font-weight: bold;
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
}

.portal-navigation__list {
	margin: 0;
}

.page-choice__row--hidden .page-choice__label {
	color: var(--color-text-maxcontrast);
}

.portal-navigation__moves {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
