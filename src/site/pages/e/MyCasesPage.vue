<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"My cases" on the site (cases-my-cases-page), ported from
	the React portal's MyCasesPage.jsx. Every case the signed-in person may
	read, from every app that contributes cases, in one list, newest first. Each
	row names the app it comes from, and the mandate it is read under when there
	is one. Open and closed cases sit on their own tabs; the "Closed" tab is only
	there when a contributing collection declares what closed means (the
	contributions answer's `cases.closedMarker`, handed in as `closedMarker`).

	Opening a case needs the shell's page lookup (`navKeyFor` in
	src/shared/openRecord.js): the shell passes `canOpen(target)`,
	`openCase(target, row)` and `caseRoute(target)`. With a route the case title
	is a real link (a new tab, a bookmark); a plain click still opens it through
	`openCase`, so a case read under a mandate keeps its row. Without them a case
	title is plain text, exactly as the React page shows a case no page can
	open.
-->
<template>
	<section
		class="pq-cases"
		aria-labelledby="pq-cases-title"
		data-testid="my-cases">
		<!-- The page title: the shell leaves its own h1 out for this page
		     (OWNS_HEADING in pages/registry.js). -->
		<h1 id="pq-cases-title" class="utrecht-heading-2">
			{{ t('My cases') }}
		</h1>

		<p
			v-if="data === null"
			class="utrecht-paragraph"
			role="status"
			data-testid="my-cases-loading">
			{{ t('Loading…') }}
		</p>

		<p
			v-else-if="data.ok === false && data.error === 'group_too_large'"
			class="utrecht-paragraph pq-e-error"
			role="alert"
			data-testid="my-cases-error">
			{{
				t(
					'This organisation has too many cases to list here. Choose a narrower mandate.',
				)
			}}
		</p>

		<!-- Any other failed read says so and offers to try again; it never
		     reads as "no cases" (site-mijn-omgeving-components REQ-SMO-009). -->
		<div v-else-if="data.ok === false" data-testid="my-cases-error">
			<LoadError
				:text="mt('Your cases could not be loaded.')"
				:retryLabel="mt('Try again')"
				@retry="load" />
		</div>

		<p
			v-else-if="split.open.length + split.closed.length === 0"
			class="utrecht-paragraph"
			data-testid="my-cases-empty">
			{{ t('No cases yet.') }}
		</p>

		<template v-else>
			<div
				v-if="closedMarker"
				class="pq-cases__tabs"
				role="tablist"
				:aria-label="t('My cases')"
				@keydown.left.prevent="switchTab"
				@keydown.right.prevent="switchTab">
				<button
					id="pq-cases-tab-open"
					ref="tab-open"
					type="button"
					role="tab"
					class="utrecht-button utrecht-button--subtle"
					:aria-selected="tab !== 'closed' ? 'true' : 'false'"
					aria-controls="pq-cases-panel"
					:tabindex="tab !== 'closed' ? 0 : -1"
					data-testid="my-cases-tab-open"
					@click="tab = 'open'">
					{{ t('Open ({count})', { count: split.open.length }) }}
				</button>
				<button
					id="pq-cases-tab-closed"
					ref="tab-closed"
					type="button"
					role="tab"
					class="utrecht-button utrecht-button--subtle"
					:aria-selected="tab === 'closed' ? 'true' : 'false'"
					aria-controls="pq-cases-panel"
					:tabindex="tab === 'closed' ? 0 : -1"
					data-testid="my-cases-tab-closed"
					@click="tab = 'closed'">
					{{ t('Closed ({count})', { count: split.closed.length }) }}
				</button>
			</div>

			<div
				id="pq-cases-panel"
				:role="closedMarker ? 'tabpanel' : null"
				:aria-labelledby="
					closedMarker
						? tab === 'closed'
							? 'pq-cases-tab-closed'
							: 'pq-cases-tab-open'
						: null
				">
				<p
					v-if="shown.length === 0"
					class="utrecht-paragraph"
					data-testid="my-cases-none-here">
					{{ t(tab === 'closed' ? 'No closed cases.' : 'No cases yet.') }}
				</p>
				<!-- Each case a Den Haag case card (site-mijn-omgeving-components
				     REQ-SMO-002), naming its type (REQ-SMO-030). -->
				<!-- Or, when the portal says `myCases.display: rows`, one row per
				     case (zuiddrecht-resident-pages-match-the-boards). -->
				<ul
					v-else
					class="pq-cases__list"
					:class="{ 'pq-cases__list--rows': asRows }"
					data-testid="my-cases-list">
					<CaseCard
						v-for="(item, index) in rows"
						:key="
							item.target
								? `${item.target.app}:${item.target.collection}:${item.target.id}`
								: index
						"
						data-testid="my-cases-row"
						:card="item.card"
						:mandate="item.mandate"
						:meta="asRows ? '' : item.meta"
						:route="item.route"
						:button="item.openable && !item.route"
						:display="asRows ? 'row' : ''"
						:dueLabel="t('Due by')"
						@open="openCase(item.target, item.row)" />
				</ul>
			</div>
		</template>
	</section>
</template>

<script>
import CaseCard from '../../components/mijn/CaseCard.vue'
import LoadError from '../../components/mijn/LoadError.vue'
import {
	caseStatus,
	caseTarget,
	caseTitle,
	splitCases,
} from '../../../shared/myCases.js'
import { actingFor, learnMandates } from '../../components/e/actingFor.js'
import { caseCard } from '../../components/mijn/cases.js'
import { mijnTranslator } from '../../components/mijn/rows.js'
import { longDate, readerLocale } from './format.js'

export default {
	name: 'MyCasesPage',

	components: { CaseCard, LoadError },

	props: {
		/** The session as `/portal/api/session` returns it. */
		session: { type: Object, default: null },
		/** The portal record. */
		portal: { type: Object, default: null },
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The shell's `navigate(key, params)`. */
		navigate: { type: Function, default: () => {} },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
		/** Whether any case collection tells closed from open (contributions `cases.closedMarker`). */
		closedMarker: { type: Boolean, default: false },
		/** The mandate to act under; empty follows the acting-for choice of the session. */
		mandateId: { type: String, default: '' },
		/** Whether a page shows this case: `(target) => boolean`. */
		canOpen: { type: Function, default: null },
		/** Open the case on its app's page: `(target, row) => void`. */
		openCase: { type: Function, default: () => {} },
		/** The in-site route a case opens on: `(target) => string`, '' for none. */
		caseRoute: { type: Function, default: null },
		/** The contributions aggregate (or its list), for a row's due day. */
		contributions: { type: [Object, Array], default: null },
		/** The list to show without fetching (test seam). */
		initialData: { type: Object, default: null },
		/** 'open' or 'closed' (test seam). */
		initialTab: { type: String, default: 'open' },
	},

	emits: ['loaded'],

	data() {
		return { data: this.initialData, tab: this.initialTab, request: 0 }
	},

	computed: {
		/**
		 * The mandate the list is read under: the page's own, else the session's choice.
		 *
		 * @return {string} The mandate id, or `self`.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		actingUnder() {
			return this.mandateId || actingFor.id
		},

		/**
		 * @return {(key: string, vars?: object) => string} The translator of the mijn omgeving components.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		mt() {
			return mijnTranslator(this.t, readerLocale(this.locale))
		},

		/**
		 * @return {boolean} Whether the portal draws the list as rows.
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
		 */
		asRows() {
			return this.portal?.myCases?.display === 'rows'
		},

		/**
		 * The cases, open and closed apart.
		 *
		 * @return {{open: Array<object>, closed: Array<object>}} The two lists.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		split() {
			return splitCases(this.data?.cases)
		},

		/**
		 * The cases of the tab on screen.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		shown() {
			return this.closedMarker && this.tab === 'closed'
				? this.split.closed
				: this.split.open
		},

		/**
		 * The rows on screen, each with its status in words.
		 *
		 * @return {Array<object>} The rows.
		 *
		 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-my-cases/spec.md#requirement-a-case-on-my-cases-shows-its-status-in-words-never-a-code
		 */
		rows() {
			const locale = readerLocale(this.locale)
			return this.shown.map((row) => {
				const target = caseTarget(row)
				const date = longDate(
					row.created || row.startedAt || row['@self']?.created,
					locale,
				)
				const source = row._source?.label || row._source?.appId || ''
				const openable =
					target !== null
					&& typeof this.canOpen === 'function'
					&& this.canOpen(target) === true
				return {
					// A row reads its collection for the day it is due by; the
					// folder card stays as it was.
					card: caseCard(
						row,
						this.asRows ? this.collectionOf(row) : null,
						{
							tr: this.mt,
							locale,
							today: new Date(),
						},
					),
					meta: [source, date].filter(Boolean).join(', '),
					row,
					target,
					openable,
					route:
						openable && typeof this.caseRoute === 'function'
							? this.caseRoute(target) || ''
							: '',
					title: caseTitle(row),
					source,
					mandate: row._mandate?.label || '',
					status: caseStatus(row),
					date,
				}
			})
		},
	},

	watch: {
		/**
		 * Another mandate chosen: read the list again.
		 *
		 * @return {void}
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		actingUnder() {
			this.load()
		},
	},

	/**
	 * Read the list on arrival, unless a test handed one in.
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
	 */
	mounted() {
		if (this.initialData === null) {
			this.load()
		}
	},

	beforeUnmount() {
		this.request++
	},

	methods: {
		/**
		 * The contributed collection a merged row came from (`_source`), so a
		 * row knows its due field; null when none is known.
		 *
		 * @param {object} row A case row of the merged list.
		 * @return {object|null}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
		 */
		collectionOf(row) {
			const list = Array.isArray(this.contributions)
				? this.contributions
				: this.contributions?.contributions
			const app = row?._source?.appId
			const id = row?._source?.collection
			const contribution = (Array.isArray(list) ? list : []).find(
				(candidate) => candidate && candidate.app === app,
			)
			return (
				(contribution?.collections || []).find(
					(candidate) => candidate && candidate.id === id,
				) || null
			)
		},

		/**
		 * Read the list under the mandate in effect; a later read wins.
		 *
		 * @return {Promise<void>} Resolves when read.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		async load() {
			const ticket = ++this.request
			this.data = null
			const answer = await this.api.fetchMyCases(this.actingUnder)
			if (ticket !== this.request) {
				return
			}
			this.data = answer
			learnMandates(answer)
			this.$emit('loaded', answer)
		},

		/**
		 * Move between the two tabs with the arrow keys, as a tablist does.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
		 */
		switchTab() {
			this.tab = this.tab === 'closed' ? 'open' : 'closed'
			this.$nextTick(() => this.$refs[`tab-${this.tab}`]?.focus())
		},
	},
}
</script>

<style scoped>
.pq-cases > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

/* The tab list reads the set's tab roles when it names them
   (thematiq zuiddrecht-website-type-and-controls); else as it was. */
.pq-cases__tabs {
	display: flex;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	border-block-end: var(--utrecht-border-width-sm, 1px) solid
		var(--thematiq-tab-line-color, var(--utrecht-color-grey-80, currentcolor));
}

.pq-cases__tabs [aria-selected='true'] {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
	text-decoration: underline;
	text-decoration-color: var(--thematiq-tab-current-color, currentcolor);
	box-shadow: inset 0 -4px 0 var(--thematiq-tab-current-color, transparent);
}

.pq-cases__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
	gap: var(--utrecht-space-block-lg, 1.5rem) var(--utrecht-space-inline-md, 1rem);
	margin: 0;
	padding: 0;
}

/* The rows: one under the other, 12px apart. */
.pq-cases__list--rows {
	grid-template-columns: minmax(0, 1fr);
	gap: 0.75rem;
}

.pq-e-error {
	color: var(
		--utrecht-feedback-danger-color,
		var(--nldesign-color-error, currentcolor)
	);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
