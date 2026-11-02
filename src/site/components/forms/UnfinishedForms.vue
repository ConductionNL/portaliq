<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The resident's unfinished forms on Mijn zaken (board MijnZaken
	"Onvoltooid formulier", REQ-FSR-003): one card per live draft, naming the
	form and the day it was saved, with a link that opens the form again.
	Nothing shows while there is none, or when the list cannot be read.
-->
<template>
	<ul v-if="drafts.length" class="pq-unfinished" data-testid="unfinished-forms">
		<li
			v-for="draft in drafts"
			:key="`${draft.app}:${draft.action}`"
			class="pq-unfinished__card"
			data-testid="unfinished-form">
			<span class="pq-unfinished__kind">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M8,12V14H16V12H8M8,16V18H13V16H8Z" /></svg>
				{{ t('Unfinished form') }}
			</span>
			<span class="pq-unfinished__name">{{ draft.label || draft.action }}</span>
			<span class="pq-unfinished__when">
				{{ t('Saved on {date}. Not sent yet.', { date: day(draft.updatedAt) }) }}
			</span>
			<a
				class="utrecht-link pq-unfinished__open"
				:href="hrefFor(draft)"
				data-testid="unfinished-form-open">
				{{ t('Finish the form') }}
			</a>
		</li>
	</ul>
</template>

<script>
import { runtimeConfig } from '../../lib/contentApi.js'
import { addressOfRoute } from '../../lib/siteAddress.js'
import { fullDay } from '../mijn/documents.js'
import { formRoute } from './unfinished.js'

export default {
	name: 'UnfinishedForms',

	props: {
		/** The portal api; `listDrafts()` reads the live drafts. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The page language. */
		locale: { type: String, default: 'nl' },
		/** Drafts handed in by a test; null reads them. */
		initialDrafts: { type: Array, default: null },
	},

	data() {
		return { drafts: this.initialDrafts || [] }
	},

	async mounted() {
		if (this.initialDrafts !== null || typeof this.api.listDrafts !== 'function') {
			return
		}
		this.drafts = await this.api.listDrafts()
	},

	methods: {
		/**
		 * @param {string} value An ISO moment.
		 * @return {string} The day in words.
		 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
		 */
		day(value) {
			return fullDay(value, this.locale)
		},

		/**
		 * @param {{app: string, action: string}} draft A live draft.
		 * @return {string} The form's address on this site.
		 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
		 */
		hrefFor(draft) {
			const route = formRoute(draft)
			try {
				return addressOfRoute(route, window.location.href, runtimeConfig()).toString()
			} catch {
				return `?route=${encodeURIComponent(route)}`
			}
		},
	},
}
</script>

<style scoped>
.pq-unfinished {
	display: grid;
	gap: 0.75rem;
	margin: 0 0 1.5rem;
	padding: 0;
	list-style: none;
}

.pq-unfinished__card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 1rem 1.25rem;
	border: 1px solid var(--nldesign-website-attention-border-color, var(--nldesign-color-border, currentcolor));
	border-radius: var(--nldesign-website-border-radius-large, 6px);
	background: var(--nldesign-website-attention-background-color, transparent);
}

.pq-unfinished__kind {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.875rem;
	font-weight: 600;
}

.pq-unfinished__kind svg {
	inline-size: 18px;
	block-size: 18px;
	fill: currentcolor;
}

.pq-unfinished__name {
	font-size: 1.125rem;
	font-weight: 700;
}

.pq-unfinished__when {
	color: var(--thematiq-website-text-muted, var(--nldesign-color-text-muted, inherit));
	font-size: 0.9375rem;
}

.pq-unfinished__open {
	align-self: flex-start;
	font-weight: 600;
}
</style>
