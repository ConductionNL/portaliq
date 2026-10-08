<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The product finder (public-faq-and-product-finder).

	One yes-or-no question at a time; each answer rules out products, and the
	products that remain stand beside the question. The answers are held in
	this component and nowhere else: nothing is sent, nothing is stored, and
	the page says so.
-->
<template>
	<section class="nl-product-finder" data-testid="nl-product-finder">
		<p v-if="state === 'loading'" class="utrecht-paragraph" role="status">
			{{ say('loading') }}
		</p>
		<p v-else-if="state === 'failed'" class="utrecht-paragraph" role="status">
			{{ say('failed') }}
		</p>
		<p v-else-if="!definition" class="utrecht-paragraph" data-testid="nl-finder-missing">
			{{ say('missing') }}
		</p>

		<template v-else>
			<header class="nl-product-finder__intro">
				<h2 class="utrecht-heading-2">{{ definition.title }}</h2>
				<p v-if="definition.intro" class="utrecht-paragraph">{{ definition.intro }}</p>
			</header>

			<div class="nl-product-finder__layout">
				<div class="nl-product-finder__ask">
					<div v-if="answered.length > 0" data-testid="nl-finder-chips">
						<p class="utrecht-paragraph nl-product-finder__label">
							{{ say('answersSoFar') }}
						</p>
						<ul class="nl-product-finder__chips">
							<li v-for="step in answered" :key="step.question.id">
								<button
									type="button"
									class="nl-product-finder__chip"
									data-testid="nl-finder-chip"
									@click="edit(step.question.id)">
									{{ step.question.text }}: {{ answerLabel(step.answer) }}
								</button>
							</li>
						</ul>
					</div>

					<div v-if="asking" data-testid="nl-finder-question">
						<p class="utrecht-paragraph nl-product-finder__label" data-testid="nl-finder-position">
							{{ position }}<span v-if="!editing"> · {{ timeLeft }}</span>
						</p>
						<h3 class="utrecht-heading-3">{{ asking.question.text }}</h3>
						<p v-if="asking.question.help" class="utrecht-paragraph">
							{{ asking.question.help }}
						</p>
						<div class="nl-product-finder__buttons">
							<button
								type="button"
								class="utrecht-button utrecht-button--primary-action"
								:aria-pressed="asking.answer === 'yes' ? 'true' : 'false'"
								data-testid="nl-finder-yes"
								@click="answer('yes')">
								{{ say('yes') }}
							</button>
							<button
								type="button"
								class="utrecht-button utrecht-button--primary-action"
								:aria-pressed="asking.answer === 'no' ? 'true' : 'false'"
								data-testid="nl-finder-no"
								@click="answer('no')">
								{{ say('no') }}
							</button>
						</div>
					</div>
					<p v-else class="utrecht-paragraph" data-testid="nl-finder-done">
						{{ say('doneNote') }}
					</p>

					<div class="nl-product-finder__buttons">
						<button
							v-if="answered.length > 0"
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							data-testid="nl-finder-previous"
							@click="previous">
							{{ say('previous') }}
						</button>
						<button
							v-if="answered.length > 0"
							type="button"
							class="utrecht-button utrecht-button--secondary-action"
							data-testid="nl-finder-restart"
							@click="restart">
							{{ say('restart') }}
						</button>
					</div>
					<p class="utrecht-paragraph nl-product-finder__note" data-testid="nl-finder-not-kept">
						{{ say('notKept') }}
					</p>
				</div>

				<aside class="nl-product-finder__result" aria-live="polite">
					<h3 class="utrecht-heading-3">{{ say('products') }}</h3>
					<p class="utrecht-paragraph" data-testid="nl-finder-count">
						{{ countText }}
					</p>
					<p
						v-if="plan.remaining.length === 0"
						class="utrecht-paragraph"
						data-testid="nl-finder-none">
						{{ say('none') }}
					</p>
					<ul v-else class="nl-product-finder__products">
						<li
							v-for="product in plan.remaining"
							:key="product.route"
							data-testid="nl-finder-product">
							<a
								class="utrecht-link"
								:href="linkOf(product).href"
								@click="open($event, linkOf(product))">
								{{ product.title }}
							</a>
						</li>
					</ul>
					<details v-if="plan.excluded.length > 0" data-testid="nl-finder-excluded">
						<summary>{{ fallenText }}</summary>
						<ul class="nl-product-finder__products">
							<li v-for="product in plan.excluded" :key="product.route">
								{{ product.title }}
							</li>
						</ul>
					</details>
				</aside>
			</div>
		</template>
	</section>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'
import { minutesLeft, planFinder, pruneAnswers } from '../../lib/finderPlan.js'
import { fetchFinder } from '../../lib/publicFaq.js'
import { pageLocale } from '../../pages/inbox/translate.js'
import strings from './strings.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
 */
export default {
	name: 'NlProductFinder',

	props: {
		/** The finder's id; empty for the portal's first published one. */
		finder: { type: String, default: '' },
		/** The serving portal, from the host. */
		portal: { type: String, default: '' },
		/** A finder handed in directly (tests, previews); skips the read. */
		initialFinder: { type: Object, default: null },
	},

	emits: ['navigate'],

	data() {
		return {
			definition: this.initialFinder,
			state: this.initialFinder ? 'ready' : 'loading',
			answers: {},
			editingId: '',
		}
	},

	computed: {
		/**
		 * @return {object} Where the resident stands.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		plan() {
			return planFinder(this.definition, this.answers)
		},

		/**
		 * @return {Array<object>} The steps already answered.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		answered() {
			return this.plan.steps.filter((step) => step.answer !== null)
		},

		/**
		 * @return {boolean} Whether an earlier answer is being changed.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		editing() {
			return this.editingId !== '' && this.plan.steps.some((step) => step.question.id === this.editingId)
		},

		/**
		 * @return {object|null} The step on screen: the one being changed, else the first unanswered.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		asking() {
			if (this.editing) {
				return this.plan.steps.find((step) => step.question.id === this.editingId)
			}
			return this.plan.current >= 0 ? this.plan.steps[this.plan.current] : null
		},

		/**
		 * @return {string} "Vraag 3 van 5".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		position() {
			const index = this.plan.steps.findIndex((step) => step.question.id === this.asking.question.id)
			return this.say('position').replace('{n}', String(index + 1)).replace('{total}', String(this.plan.steps.length))
		},

		/**
		 * @return {string} "Nog ongeveer 1 minuut".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		timeLeft() {
			const left = this.plan.steps.filter((step) => step.answer === null).length
			const minutes = minutesLeft(left)
			return this.say(minutes === 1 ? 'minutes' : 'minutesMany').replace('{n}', String(minutes))
		},

		/**
		 * @return {string} "Nog 4 van de 12 producten passen bij uw antwoorden".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		countText() {
			const total = this.plan.remaining.length + this.plan.excluded.length
			return this.say('count').replace('{n}', String(this.plan.remaining.length)).replace('{total}', String(total))
		},

		/**
		 * @return {string} "Vallen af door uw antwoorden (8)".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		fallenText() {
			return this.say('fallenAway').replace('{n}', String(this.plan.excluded.length))
		},
	},

	/**
	 * Read the finder once the widget is on the page. The request names the
	 * portal and the finder, never an answer.
	 *
	 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
	 */
	async mounted() {
		if (this.initialFinder) {
			return
		}
		try {
			this.definition = await fetchFinder(this.portal, this.finder)
			this.state = 'ready'
		} catch {
			this.state = 'failed'
		}
	},

	methods: {
		/**
		 * @param {string} key A string key.
		 * @return {string} The words in the page language.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		say(key) {
			return (strings[pageLocale()] || strings.nl)[key]
		},

		/**
		 * @param {string} value `yes` or `no`.
		 * @return {string} The word on the button.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		answerLabel(value) {
			return this.say(value === 'yes' ? 'yes' : 'no')
		},

		/**
		 * Record an answer to the question on screen. An answer to a question
		 * that no longer counts is dropped.
		 *
		 * @param {string} value `yes` or `no`.
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		answer(value) {
			if (!this.asking) {
				return
			}
			const next = { ...this.answers, [this.asking.question.id]: value }
			this.answers = pruneAnswers(this.definition, next)
			this.editingId = ''
		},

		/**
		 * Go back to the question behind a chip.
		 *
		 * @param {string} id The question's id.
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		edit(id) {
			this.editingId = id
		},

		/**
		 * Take back the last answer.
		 *
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		previous() {
			const last = this.answered[this.answered.length - 1]
			if (!last) {
				return
			}
			const next = { ...this.answers }
			delete next[last.question.id]
			this.answers = pruneAnswers(this.definition, next)
			this.editingId = ''
		},

		/**
		 * Forget every answer.
		 *
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		restart() {
			this.answers = {}
			this.editingId = ''
		},

		/**
		 * @param {{route: string}} product The product.
		 * @return {object} Its link.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		linkOf(product) {
			return authoredLink(product.route) || { href: '#', route: '' }
		},

		/**
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>

<style scoped>
.nl-product-finder {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.nl-product-finder__layout {
	display: flex;
	flex-wrap: wrap;
	gap: 2rem;
}

.nl-product-finder__ask {
	flex: 1 1 20rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.nl-product-finder__result {
	flex: 1 1 18rem;
	padding: 1rem;
	border: 1px solid var(--utrecht-color-border, currentColor);
}

.nl-product-finder__label,
.nl-product-finder__note {
	margin: 0;
}

.nl-product-finder__chips,
.nl-product-finder__products {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-product-finder__products {
	flex-direction: column;
}

.nl-product-finder__chip {
	padding: 0.25rem 0.75rem;
	border: 1px solid var(--utrecht-color-border, currentColor);
	border-radius: 999px;
	background: transparent;
	color: inherit;
	font: inherit;
	cursor: pointer;
}

.nl-product-finder__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}
</style>
