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
		<dl v-if="kind === 'domain' && terms.length > 0" class="portal-summary__terms">
			<template v-for="term in terms" :key="`${term.label}-${term.value}`">
				<dt class="portal-summary__label">
					{{ term.label }}
				</dt>
				<dd class="portal-summary__value" :class="{ 'portal-summary__value--strong': term.strong }">
					{{ term.value }}
				</dd>
			</template>
		</dl>
		<template v-else-if="kind === 'theme' && themeId">
			<div class="portal-summary__theme">
				<span class="portal-summary__swatch" aria-hidden="true" />
				<span class="portal-summary__person-text">
					<strong class="portal-summary__name">{{ themeName }}</strong>
					<span class="portal-summary__muted">{{ t('portaliq', 'House style') }}</span>
				</span>
			</div>
			<router-link class="portal-summary__link" :to="{ name: 'Themes' }">
				{{ t('portaliq', 'View theme') }}
			</router-link>
		</template>
		<template v-else-if="kind === 'status' && status.pill">
			<CnStatusBadge class="portal-summary__pill" :label="status.pill.label" :variant="status.pill.tone" />
			<span v-for="line in statusLines" :key="line" class="portal-summary__muted portal-summary__line">{{ line }}</span>
		</template>
		<ul v-else-if="kind === 'editors' && people.length > 0" class="portal-summary__people">
			<li v-for="person in people" :key="person.key" class="portal-summary__person">
				<span class="portal-summary__initials" aria-hidden="true">{{ person.initials }}</span>
				<span class="portal-summary__person-text">
					<strong class="portal-summary__name">{{ person.name }}</strong>
					<span class="portal-summary__muted">{{ person.role }}</span>
				</span>
			</li>
		</ul>
		<p v-else class="portal-summary__empty">
			{{ emptyText }}
		</p>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { domainTerms, editorPeople, statusSummary } from '../lib/portalSummary.js'

/**
 * The translate function the summary helpers take.
 *
 * @param {string} key The key.
 * @param {object} [vars] The values.
 * @return {string} The text.
 */
const tr = (key, vars) => t('portaliq', key, vars)

export default {
	name: 'PortalSummaryCard',

	components: { CnStatusBadge },

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},

		/** Which summary: `domain`, `theme`, `status` or `editors`. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			themeName: '',
			waitingPages: 0,
		}
	},

	computed: {
		/**
		 * The heading of the card for its kind.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string} The translated heading, empty for an unknown kind.
		 */
		title() {
			return (
				{
					domain: t('portaliq', 'Domain and certificate'),
					theme: t('portaliq', 'Theme'),
					status: t('portaliq', 'Status and last publication'),
					editors: t('portaliq', 'Administrators'),
				}[this.kind] || ''
			)
		},

		/**
		 * Which summary this card is.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string} `domain`, `theme`, `status` or `editors`.
		 */
		kind() {
			return this.content?.kind || 'domain'
		},

		/**
		 * The portal the page shows.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {object} The portal, empty while it loads.
		 */
		portal() {
			return this.objectData || {}
		},

		/**
		 * The reader's locale.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string} BCP 47 tag.
		 */
		locale() {
			return getCanonicalLocale() || 'nl'
		},

		/**
		 * What the card says when it has nothing to show.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string} The text.
		 */
		emptyText() {
			if (this.kind === 'editors') {
				return t('portaliq', 'Nobody listed yet.')
			}
			return this.kind === 'domain'
				? t('portaliq', 'No domain yet.')
				: t('portaliq', 'Nothing to show yet.')
		},

		/**
		 * The domain card's terms.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {Array<object>} The terms.
		 */
		terms() {
			return domainTerms(this.portal, tr, this.locale)
		},

		/**
		 * The portal's theme id.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string} The id, empty without one.
		 */
		themeId() {
			return String(this.portal.theme || '')
		},

		/**
		 * The status card's pill and lines.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {object} The card.
		 */
		status() {
			return statusSummary(this.portal, tr, this.locale)
		},

		/**
		 * The status card's lines: the last change and, when pages wait, how many.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {string[]} The lines.
		 */
		statusLines() {
			const waiting = this.waitingPages > 0
				? [n('portaliq', '%n page waits to be published', '%n pages wait to be published', this.waitingPages)]
				: []
			return [...this.status.lines, ...waiting]
		},

		/**
		 * The administrators as people.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 * @return {Array<object>} The people.
		 */
		people() {
			return editorPeople(this.portal, tr)
		},
	},

	watch: {
		'portal.slug': {
			/**
			 * Read what the card names beyond the record once the portal is known.
			 *
			 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
			 */
			handler() {
				this.load()
			},

			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Read what the card names beyond the portal record: the theme's name,
		 * or how many of the portal's pages are not published yet.
		 *
		 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
		 */
		async load() {
			const slug = String(this.portal.slug || '')
			if (!slug) {
				return
			}
			try {
				if (this.kind === 'theme' && this.themeId) {
					const { data } = await axios.get(generateUrl('/apps/portaliq/api/portals/{slug}/theme', { slug }))
					const set = (Array.isArray(data?.sets) ? data.sets : []).find((s) => s.id === this.themeId)
					this.themeName = set?.name || this.themeId
				} else if (this.kind === 'status') {
					const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/portaliq/page'), { params: { portal: slug, status: 'draft', _limit: 1 } })
					this.waitingPages = Number(data?.total) || 0
				}
			} catch {
				this.themeName = this.themeName || this.themeId
			}
		},
	},
}
</script>

<style scoped>
/* A side card as the dossiq boards draw one (DqZaak, PtPortal): the board
   card's 20px 22px padding, headed by a 15px muted label at weight 600, its
   parts 12px apart (PtMededelingenActies). The heading ink carries
   `!important` against a theme that colours every heading (thematiq). */
.portal-summary {
	display: flex;
	flex-direction: column;
	gap: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px 22px;
	font-size: 14px;
}

.portal-summary__title {
	margin: 0;
	color: var(--color-text-maxcontrast) !important;
	font-size: 15px;
	font-weight: 600;
	line-height: 1.2;
}

.portal-summary__terms {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	margin: 0;
}

/* Nextcloud's own `dt`/`dd` rule (130px, end-aligned, 12px padding) gives
   way to the board's two-column list. */
.portal-summary__label,
.portal-summary__value {
	display: block;
	width: auto;
	margin: 0;
	padding: 0;
	text-align: start;
	white-space: normal;
}

.portal-summary__label {
	color: var(--color-text-maxcontrast);
}

.portal-summary__value--strong,
.portal-summary__name {
	font-weight: 600;
}

.portal-summary__theme,
.portal-summary__person {
	display: flex;
	gap: 12px;
	align-items: center;
}

.portal-summary__swatch {
	flex: none;
	width: 40px;
	height: 40px;
	border-radius: 8px;
	background: var(--color-primary-element);
}

.portal-summary__person-text {
	display: flex;
	flex-direction: column;
}

.portal-summary__theme .portal-summary__name {
	font-size: 15px;
}

.portal-summary__muted {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.portal-summary__line {
	font-size: 14px;
}

.portal-summary__link {
	align-self: flex-start;
	color: var(--color-primary-element);
	font-weight: 600;
	text-decoration: underline;
}

.portal-summary__pill {
	align-self: flex-start;
}

.portal-summary__people {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.portal-summary__initials {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 16px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	font-weight: 700;
}

.portal-summary__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
