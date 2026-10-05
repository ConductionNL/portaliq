<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A collection's rows as cards with a progress figure ("120 van 400 uur"),
	as LearniqTrainer.dc.html draws the trainer's students. The figure is
	text; the bar beside it is decorative. A row without a total shows no
	figure, and a row nothing can name shows no empty heading.
-->
<template>
	<ul class="pq-progress-cards" data-testid="mijn-progress-cards">
		<li v-for="card in cards" :key="card.key" class="pq-progress-cards__card">
			<div
				v-if="card.title !== '' || card.subtitle"
				class="pq-progress-cards__head">
				<span
					v-if="block.avatar && card.title"
					class="pq-progress-cards__avatar"
					aria-hidden="true"
					>{{ card.title.charAt(0) }}</span
				>
				<div>
					<p v-if="card.title !== ''" class="pq-progress-cards__title">
						{{ card.title }}
					</p>
					<p v-if="card.subtitle" class="pq-progress-cards__subtitle">
						{{ card.subtitle }}
					</p>
				</div>
			</div>
			<p v-if="card.status || card.note" class="pq-progress-cards__status">
				<DataBadge
					v-if="card.status"
					:text="card.status"
					:state="card.tone" />
				<span v-if="card.note">{{ card.note }}</span>
			</p>
			<template v-if="card.figure">
				<p class="pq-progress-cards__figure">{{ card.figure }}</p>
				<span class="pq-progress-cards__bar" aria-hidden="true"
					><span :style="{ inlineSize: card.width }"
				/></span>
			</template>
			<div v-if="card.soon" class="pq-progress-cards__soon">
				<p v-if="block.soonLabel" class="pq-progress-cards__soon-label">
					{{ block.soonLabel }}
				</p>
				<p class="pq-progress-cards__soon-text">{{ card.soon }}</p>
			</div>
		</li>
	</ul>
</template>

<script>
import DataBadge from './DataBadge.vue'
import { cardParts } from './displays.js'
import { mijnTranslator } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-show-its-rows-as-cards-with-a-progress-figure-req-smo-028
 */
export default {
	name: 'ProgressCards',

	components: { DataBadge },

	props: {
		/** The rows. */
		rows: { type: Array, required: true },
		/** The block: `progress: {valueField, totalField, label?}`. */
		block: { type: Object, required: true },
		/** The collection's own naming fields, used when the block names none. */
		titleFields: { type: Array, default: () => [] },
		/** The collection, for its value labels (site-school-blocks). */
		collection: { type: Object, default: null },
		/** The site translator. */
		t: { type: Function, default: null },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {Array<object>} `{key, title, figure, width}` per row.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-show-its-rows-as-cards-with-a-progress-figure-req-smo-028
		 */
		cards() {
			const tr = mijnTranslator(this.t, this.locale)
			const progress = this.block?.progress || null
			// The block names its rows (REQ-SMO-028), then the collection, then
			// the three properties a schema happens to call a name. A schema
			// with none of the three drew a bar and nothing identifying, which
			// is why a cards block may say what names it.
			const declared =
				this.block?.titleFields?.length > 0
					? this.block.titleFields
					: this.titleFields
			const fields =
				declared.length > 0 ? declared : ['name', 'title', 'givenName']
			return this.rows.map((row, index) => {
				const title = fields
					.map((field) => row?.[field])
					.filter(
						(value) => typeof value === 'string' && value.trim() !== '',
					)
					.join(' ')
				const value = Number(row?.[progress?.valueField])
				const total = Number(row?.[progress?.totalField])
				const figured =
					progress
					&& Number.isFinite(value)
					&& Number.isFinite(total)
					&& total > 0
				return {
					// A sub line, a status with its note, and what is coming up
					// (site-school-blocks).
					...cardParts(row, this.block, this.collection),
					key: String(row?.id || row?.uuid || index),
					title,
					figure: figured
						? tr('{value} of {total} {label}', {
								value,
								total,
								label: progress.label || '',
							}).trim()
						: '',
					width: figured
						? `${Math.min(100, Math.round((value / total) * 100))}%`
						: '0%',
				}
			})
		},
	},
}
</script>

<style scoped>
.pq-progress-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr));
	gap: var(--utrecht-space-inline-md, 1rem);
	margin: 0 0 var(--utrecht-space-block-lg, 1.5rem);
	padding: 0;
	list-style: none;
}

.pq-progress-cards__card {
	padding: var(--utrecht-space-block-md, 1rem);
	border: 1px solid var(--utrecht-color-grey-80, #ccc);
	border-radius: 0.5rem;
	background-color: var(--utrecht-document-background-color, #fff);
	color: var(--utrecht-document-color, inherit);
}

.pq-progress-cards__head {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-block-end: 0.5rem;
}

.pq-progress-cards__avatar {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	inline-size: 3rem;
	block-size: 3rem;
	border-radius: 50%;
	background: var(
		--nldesign-color-primary-light,
		var(--utrecht-color-grey-90, transparent)
	);
	color: var(
		--nldesign-color-primary-hover,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 1.25rem;
	font-weight: 700;
}

.pq-progress-cards__subtitle {
	margin: 0;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
}

.pq-progress-cards__status {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	margin: 0 0 0.5rem;
}

.pq-progress-cards__soon {
	margin-block-start: 0.75rem;
	padding-block-start: 0.75rem;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-progress-cards__soon-label {
	margin: 0 0 0.25rem;
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.pq-progress-cards__soon-text {
	margin: 0;
}

.pq-progress-cards__title,
.pq-progress-cards__figure {
	margin: 0 0 0.25rem;
}

.pq-progress-cards__title {
	font-weight: bold;
}

.pq-progress-cards__bar {
	display: block;
	block-size: 0.375rem;
	border-radius: 0.25rem;
	background-color: var(--utrecht-color-grey-90, #e6e6e6);
	overflow: hidden;
}

.pq-progress-cards__bar > span {
	display: block;
	block-size: 100%;
	background-color: var(
		--utrecht-button-primary-action-background-color,
		currentcolor
	);
}
</style>
