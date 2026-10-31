<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The page a form opens with (form-statements-intro-and-confirmation-mail
	REQ-FCI-001), drawn on the Zuiddrecht board FormulierStart: the lead, the
	"Voordat u begint" blocks and "Start", which opens step 1.
-->
<template>
	<div class="pq-form-intro" data-testid="form-intro">
		<h2 v-if="formName" class="utrecht-heading-2">
			{{ formName }}
		</h2>
		<p v-if="view.lead" class="utrecht-paragraph" data-testid="form-intro-lead">
			{{ view.lead }}
		</p>
		<h3 class="utrecht-heading-3">
			{{ words.before }}
		</h3>
		<section
			v-for="(block, index) in view.blocks"
			:key="index"
			class="pq-form-intro__block"
			data-testid="form-intro-block">
			<h4 v-if="block.title" class="utrecht-heading-4">
				{{ block.title }}
			</h4>
			<p v-if="block.text" class="utrecht-paragraph">
				{{ block.text }}
			</p>
			<ul v-if="block.items.length > 0">
				<li v-for="item in block.items" :key="item">
					{{ item }}
				</li>
			</ul>
		</section>
		<button
			type="button"
			class="utrecht-button utrecht-button--primary-action"
			data-testid="form-intro-start"
			@click="$emit('start')">
			{{ words.start }}
		</button>
	</div>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { introView } from './confirmation.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

const WORDS = {
	nl: { before: 'Voordat u begint', start: 'Start' },
	en: { before: 'Before you start', start: 'Start' },
}

/**
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t02
 */
export default {
	name: 'FormIntro',

	props: {
		/** The binding's `intro`: `{lead, blocks: [{title, text, items}]}`. */
		intro: { type: Object, default: null },
		/** The form's name, shown as the heading. */
		formName: { type: String, default: '' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['start'],

	computed: {
		/**
		 * @return {object} The intro as a lead and blocks.
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
		 */
		view() {
			return introView(this.intro) || { lead: '', blocks: [] }
		},

		/**
		 * @return {object} The words in the page language.
		 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
		 */
		words() {
			return WORDS[pageLocale(this.locale)] || WORDS.nl
		},
	},
}
</script>
