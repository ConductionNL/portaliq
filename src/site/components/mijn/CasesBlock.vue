<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A `cases` block: the resident's cases from one `cases` collection, as
	case cards, open ones only when it says `open`. When the collection
	declares a steps provider, the block asks it only for the cards on screen.
	"Alle zaken" leads to the collection's page when there are more. Loading
	shows a skeleton, a failed read an alert with "Opnieuw proberen", and no
	cases a sentence.
-->
<template>
	<section
		class="pq-cases-block"
		:aria-labelledby="label ? headingId : undefined"
		:data-collection="block.collection"
		data-testid="mijn-cases-block">
		<!-- With `showAll` the link to every case stands beside the heading
		     (zuiddrecht-resident-pages-match-the-boards). -->
		<div v-if="label && showAllLink" class="pq-cases-block__head">
			<component :is="`h${level}`" :id="headingId" class="utrecht-heading-3">
				{{ label }}
			</component>
			<a
				class="utrecht-link pq-cases-block__all"
				:href="allHref"
				data-testid="mijn-cases-all"
				@click="openAll"
				>{{ tr('All cases') }}</a
			>
		</div>
		<component
			:is="`h${level}`"
			v-else-if="label"
			:id="headingId"
			class="utrecht-heading-3">
			{{ label }}
		</component>
		<Skeleton
			v-if="loading && shown.rows.length === 0"
			:label="tr('Loading')"
			:rows="2" />
		<LoadError
			v-else-if="failed"
			:text="tr('Your cases could not be loaded.')"
			:retryLabel="tr('Try again')"
			@retry="$emit('retry')" />
		<EmptyState
			v-else-if="shown.rows.length === 0"
			:text="
				tr(
					block.open
						? 'You have no running cases.'
						: 'You have no cases yet.',
				)
			" />
		<template v-else>
			<ul
				class="pq-cases-block__list"
				:class="{ 'pq-cases-block__list--compact': compact }">
				<CaseCard
					v-for="entry in entries"
					:key="entry.id"
					:card="entry.card"
					:route="entry.route"
					:display="compact ? 'compact' : ''"
					@open="open(entry)" />
			</ul>
			<p
				v-if="shown.more && allRoute && !showAllLink"
				class="utrecht-paragraph">
				<a class="utrecht-link" :href="allHref" @click="openAll">{{
					tr('All cases')
				}}</a>
			</p>
		</template>
	</section>
</template>

<script>
import CaseCard from './CaseCard.vue'
import EmptyState from './EmptyState.vue'
import LoadError from './LoadError.vue'
import Skeleton from './Skeleton.vue'
import {
	keepRecordToOpen,
	recordRoute,
	sessionStore,
} from '../../pages/inbox/inbox.js'
import { caseCard, casesOnScreen } from './cases.js'
import { mijnTranslator, siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export default {
	name: 'CasesBlock',

	components: { CaseCard, EmptyState, LoadError, Skeleton },

	props: {
		/** The normalised block: `collection`, `open?`, `limit?`, `label?`. */
		block: { type: Object, required: true },
		/** The `cases` collection it reads. */
		collection: { type: Object, default: null },
		/** The collection's rows, scoped to the resident. */
		rows: { type: Array, default: () => [] },
		/** Whether the rows are still loading. */
		loading: { type: Boolean, default: false },
		/** Whether the rows could not be read. */
		failed: { type: Boolean, default: false },
		/** The shared portal api (`fetchSteps`). */
		api: { type: Object, default: null },
		/** The app of the contribution the block belongs to. */
		app: { type: String, default: '' },
		/** Every navigation entry, to find the page that shows a case. */
		nav: { type: Array, default: () => [] },
		/** The heading level of the block's label. */
		level: { type: Number, default: 2 },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Today; a test passes a fixed day. */
		today: { type: Date, default: null },
		/** Steps to start from, by case id, for a test. */
		initialSteps: { type: Object, default: null },
	},

	emits: ['navigate', 'retry'],

	data() {
		return { steps: { ...(this.initialSteps || {}) } }
	},

	computed: {
		/**
		 * @return {(key: string, vars?: object) => string} The translator.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		tr() {
			return mijnTranslator(this.t, this.locale)
		},

		/**
		 * @return {string} The block's heading, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		label() {
			return this.block?.label || ''
		},

		/**
		 * @return {string} The heading's id.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		headingId() {
			return `pq-cases-${String(this.block?.collection || '').replace(/[^A-Za-z0-9_-]/g, '')}`
		},

		/**
		 * @return {{rows: Array<object>, more: boolean}} The cases on screen.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		shown() {
			return casesOnScreen(this.rows, this.block, this.collection)
		},

		/**
		 * @return {boolean} Whether the block draws the board card.
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		compact() {
			return this.block?.display === 'compact'
		},

		/**
		 * Whether "Alle zaken" stands beside the heading: the block says
		 * `showAll` and the collection has a page.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
		 */
		showAllLink() {
			return this.block?.showAll === true && this.allRoute !== ''
		},

		/**
		 * The cards, each with the route of the page that shows the case.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		entries() {
			return this.shown.rows.map((row, index) => {
				const id = String(row.id || row.uuid || row['@self']?.id || '')
				const link = { app: this.app, collection: this.block.collection, id }
				return {
					id: id || String(index),
					link,
					route: id ? recordRoute(this.nav, link) || '' : '',
					card: caseCard(row, this.collection, {
						tr: this.tr,
						locale: this.locale,
						today: this.today || new Date(),
						steps: this.steps[id]?.steps || null,
						yourTurn: this.block?.yourTurn || [],
					}),
				}
			})
		},

		/**
		 * @return {string} The route of the collection's own page, or ''.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		allRoute() {
			return (
				recordRoute(this.nav, {
					app: this.app,
					collection: this.block.collection,
				}) || ''
			)
		},

		/**
		 * @return {string} That page's real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		allHref() {
			return siteHref(this.allRoute)
		},
	},

	watch: {
		shown: {
			immediate: true,
			/**
			 * Ask the steps provider for the cards that came on screen.
			 *
			 * @return {void}
			 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
			 */
			handler() {
				this.loadSteps()
			},
		},
	},

	methods: {
		/**
		 * Read the steps of every card on screen that has none yet, when the
		 * collection declares a steps provider. A failed read leaves the card
		 * without a step position; it never hides the card.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		async loadSteps() {
			if (
				!this.collection?.steps
				|| typeof this.api?.fetchSteps !== 'function'
			) {
				return
			}
			const wanted = this.shown.rows
				.map((row) => String(row.id || row.uuid || row['@self']?.id || ''))
				.filter((id) => id && !(id in this.steps))
			await Promise.all(
				wanted.map(async (id) => {
					this.steps = { ...this.steps, [id]: null }
					let answer
					try {
						answer = await this.api.fetchSteps(this.collection, id)
					} catch {
						answer = null
					}
					this.steps = { ...this.steps, [id]: answer }
				}),
			)
		},

		/**
		 * Keep the case to open, so its page selects it, then go.
		 *
		 * @param {object} entry The card.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		open(entry) {
			if (!entry.route) {
				return
			}
			keepRecordToOpen(sessionStore(), entry.link)
			this.$emit('navigate', entry.route)
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
		 */
		openAll(event) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', this.allRoute)
		},
	},
}
</script>

<style scoped>
.pq-cases-block {
	margin-block-end: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-cases-block__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
	gap: var(--utrecht-space-block-lg, 1.5rem) var(--utrecht-space-inline-md, 1rem);
	margin: 0;
	padding: 0;
}

/* The board: cards from 300px, 16px apart, and the link beside the heading. */
.pq-cases-block__list--compact {
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 18.75rem), 1fr));
	gap: 1rem;
}

.pq-cases-block__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.5rem;
}

.pq-cases-block__head > * {
	margin: 0;
}

.pq-cases-block__all {
	font-weight: 600;
}
</style>
