<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalSettings: the Instellingen tab of a portal, as one stack of cards.

  The portal detail page's tab strip takes one widget per tab, so this widget
  holds the settings that used to sit in the side column and the Zaaktypen
  tab: house style, the case types residents see, sign-in, registration and
  accessibility. Each part stays the widget it was; this only places them.

  @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
-->
<template>
	<div class="portal-settings" data-testid="portal-settings">
		<section
			v-for="section in sections"
			:key="section.id"
			class="portal-settings__card"
			:data-testid="`portal-settings-${section.id}`">
			<h3 class="portal-settings__title">
				{{ section.title }}
			</h3>
			<component :is="section.component" :objectData="objectData" />
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PortalAccessibility from './PortalAccessibility.vue'
import PortalCaseTypes from './PortalCaseTypes.vue'
import PortalDetailsForm from './PortalDetailsForm.vue'
import PortalDomains from './PortalDomains.vue'
import PortalEditors from './PortalEditors.vue'
import PortalRegistration from './PortalRegistration.vue'
import PortalSignin from './PortalSignin.vue'
import PortalTheme from './PortalTheme.vue'

export default {
	name: 'PortalSettings',

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The cards, in the order of the board.
		 *
		 * @return {Array<{id: string, title: string, component: object}>}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		sections() {
			return [
				{ id: 'details', title: t('portaliq', 'Portal details'), component: PortalDetailsForm },
				{ id: 'theme', title: t('portaliq', 'House style'), component: PortalTheme },
				{ id: 'domains', title: t('portaliq', 'Domains'), component: PortalDomains },
				{ id: 'case-types', title: t('portaliq', 'Case types residents see'), component: PortalCaseTypes },
				{ id: 'signin', title: t('portaliq', 'Sign in'), component: PortalSignin },
				{ id: 'registration', title: t('portaliq', 'Registration'), component: PortalRegistration },
				{ id: 'accessibility', title: t('portaliq', 'Accessibility'), component: PortalAccessibility },
				{ id: 'editors', title: t('portaliq', 'Administrators'), component: PortalEditors },
			]
		},
	},
}
</script>

<style scoped>
.portal-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.portal-settings__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px 20px;
}
.portal-settings__title {
	margin: 0 0 12px;
	font-size: 16px;
}
</style>
