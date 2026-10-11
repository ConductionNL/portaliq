<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalSummaryCard: one summary card in the side column of a portal's page
  (domain, house style, status). Reads the portal the page shows and says what
  stands there; the settings tab holds the controls.

  @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
-->
<template>
	<div class="portal-summary" :data-testid="`portal-summary-${kind}`">
		<h4 class="portal-summary__title">
			{{ title }}
		</h4>
		<div v-if="rows.length > 0" class="portal-summary__rows">
			<div
				v-for="row in rows"
				:key="`${row.label}-${row.value}`"
				class="portal-summary__row">
				<span class="portal-summary__label">{{ row.label }}</span>
				<span class="portal-summary__value">{{ row.value }}</span>
			</div>
		</div>
		<p v-else class="portal-summary__empty">
			{{ emptyText }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PortalSummaryCard',

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},

		/** Which summary: `domain`, `theme` or `status`. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		title() {
			return {
				domain: t('portaliq', 'Domain and certificate'),
				theme: t('portaliq', 'Theme'),
				status: t('portaliq', 'Status and last publication'),
			}[this.kind] || ''
		},

		kind() {
			return this.content?.kind || 'domain'
		},

		emptyText() {
			return this.kind === 'domain'
				? t('portaliq', 'No domain yet.')
				: t('portaliq', 'Nothing to show yet.')
		},

		/**
		 * The label and value pairs this card shows.
		 *
		 * @return {Array<{label: string, value: string}>}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		rows() {
			const o = this.objectData || {}
			if (this.kind === 'theme') {
				return o.theme ? [{ label: t('portaliq', 'House style'), value: String(o.theme) }] : []
			}
			if (this.kind === 'status') {
				const out = []
				if (o.status) {
					out.push({
						label: t('portaliq', 'Status'),
						value: o.status === 'published' ? t('portaliq', 'Published') : t('portaliq', 'Draft'),
					})
				}
				const updated = o['@self']?.updated
				if (updated) {
					out.push({ label: t('portaliq', 'Last changed'), value: new Date(updated).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' }) })
				}
				return out
			}
			const out = (Array.isArray(o.domains) ? o.domains : [])
				.map((d) => (typeof d === 'string' ? d : d?.hostname || ''))
				.filter(Boolean)
				.map((host) => ({ label: t('portaliq', 'Domain'), value: host }))
			if (Array.isArray(o.locales) && o.locales.length > 0) {
				out.push({ label: t('portaliq', 'Language'), value: o.locales.join(', ') })
			}
			return out
		},
	},
}
</script>

<style scoped>
.portal-summary {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px 20px;
}
.portal-summary__title {
	margin: 0 0 10px;
	font-size: 15px;
}
.portal-summary__row {
	display: flex;
	gap: 16px;
	padding: 3px 0;
}
.portal-summary__label {
	color: var(--color-text-maxcontrast);
	min-width: 80px;
}
.portal-summary__value {
	font-weight: 600;
}
.portal-summary__empty {
	color: var(--color-text-maxcontrast);
}
</style>
