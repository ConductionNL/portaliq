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
		<p
			v-else-if="!definition"
			class="utrecht-paragraph"
			data-testid="nl-finder-missing">
			{{ say('missing') }}
		</p>

		<template v-else>
			<header class="nl-product-finder__intro">
				<h2 class="utrecht-heading-2">{{ definition.title }}</h2>
				<p v-if="definition.intro" class="utrecht-paragraph">
					{{ definition.intro }}
				</p>
			</header>

			<div class="nl-product-finder__layout">
				<div class="nl-product-finder__ask">
					<!-- Where the resident stands: the words carry it, the bar
					     only draws it. -->
					<div v-if="asking" class="nl-product-finder__progress">
						<p
							class="utrecht-paragraph nl-product-finder__position"
							data-testid="nl-finder-position">
							<strong>{{ position }}</strong>
							<span v-if="!editing">{{ timeLeft }}</span>
						</p>
						<div class="nl-product-finder__bar" aria-hidden="true">
							<span :style="{ inlineSize: progressShare }" />
						</div>
					</div>

					<div v-if="answered.length > 0" data-testid="nl-finder-chips">
						<p class="utrecht-paragraph nl-product-finder__label">
							{{ say('answersSoFar') }}
						</p>
						<ul class="nl-product-finder__chips">
							<li v-for="step in answered" :key="step.question.id">
								<button
									type="button"
									class="nl-product-finder__chip"
									:aria-label="`${say('change')}: ${step.question.text} ${answerLabel(step.answer)}`"
									data-testid="nl-finder-chip"
									@click="edit(step.question.id)">
									{{ step.question.text }}
									<strong>{{ answerLabel(step.answer) }}</strong>
									<svg
										class="nl-product-finder__glyph"
										viewBox="0 0 24 24"
										aria-hidden="true"
										focusable="false">
										<path d="M6 6l12 12M18 6L6 18" />
									</svg>
								</button>
							</li>
						</ul>
					</div>

					<form
						v-if="asking"
						class="nl-product-finder__question"
						data-testid="nl-finder-question"
						@submit.prevent="next">
						<fieldset class="nl-product-finder__fieldset">
							<legend class="nl-product-finder__legend">
								{{ asking.question.text }}
							</legend>
							<p v-if="asking.question.help" class="utrecht-paragraph">
								{{ asking.question.help }}
							</p>
							<div class="nl-product-finder__options">
								<label
									v-for="option in ['yes', 'no']"
									:key="option"
									class="nl-product-finder__option"
									:class="{ 'nl-product-finder__option--chosen': choice === option }">
									<input
										v-model="choice"
										type="radio"
										:name="`nl-finder-${asking.question.id}`"
										:value="option"
										:data-testid="`nl-finder-${option}`" />
									{{ answerLabel(option) }}
								</label>
							</div>
						</fieldset>
						<div class="nl-product-finder__buttons">
							<button
								v-if="answered.length > 0"
								type="button"
								class="utrecht-button utrecht-button--secondary-action"
								data-testid="nl-finder-previous"
								@click="previous">
								<svg
									class="nl-product-finder__glyph"
									viewBox="0 0 24 24"
									aria-hidden="true"
									focusable="false">
									<path d="M15 5l-7 7 7 7" />
								</svg>
								{{ say('previous') }}
							</button>
							<button
								type="submit"
								class="utrecht-button utrecht-button--primary-action"
								:disabled="choice === ''"
								data-testid="nl-finder-next">
								{{ say('next') }}
								<svg
									class="nl-product-finder__glyph"
									viewBox="0 0 24 24"
									aria-hidden="true"
									focusable="false">
									<path d="M9 5l7 7-7 7" />
								</svg>
							</button>
							<button
								v-if="answered.length > 0"
								type="button"
								class="utrecht-button utrecht-button--subtle nl-product-finder__restart"
								data-testid="nl-finder-restart"
								@click="restart">
								{{ say('restart') }}
							</button>
						</div>
					</form>
					<template v-else>
						<p class="utrecht-paragraph" data-testid="nl-finder-done">
							{{ say('doneNote') }}
						</p>
						<div class="nl-product-finder__buttons">
							<button
								type="button"
								class="utrecht-button utrecht-button--secondary-action"
								data-testid="nl-finder-previous"
								@click="previous">
								{{ say('previous') }}
							</button>
							<button
								type="button"
								class="utrecht-button utrecht-button--subtle nl-product-finder__restart"
								data-testid="nl-finder-restart"
								@click="restart">
								{{ say('restart') }}
							</button>
						</div>
					</template>
					<p
						class="utrecht-paragraph nl-product-finder__note"
						data-testid="nl-finder-not-kept">
						{{ say('notKept') }}
					</p>
				</div>

				<aside class="nl-product-finder__result" aria-live="polite">
					<h2 class="utrecht-heading-3">{{ say('products') }}</h2>
					<p class="utrecht-paragraph" data-testid="nl-finder-count">
						{{ countText }}
					</p>
					<div class="nl-product-finder__segments" aria-hidden="true">
						<span
							v-for="(product, index) in allProducts"
							:key="product.route"
							:class="{ 'nl-product-finder__segment--left': index < plan.remaining.length }" />
					</div>
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
								class="nl-product-finder__product"
								:href="linkOf(product).href"
								@click="open($event, linkOf(product))">
								<span class="nl-product-finder__product-text">
									<span class="utrecht-link nl-product-finder__product-title">{{
										product.title
									}}</span>
									<span v-if="product.summary">{{ product.summary }}</span>
								</span>
								<svg
									class="nl-product-finder__glyph"
									viewBox="0 0 24 24"
									aria-hidden="true"
									focusable="false">
									<path d="M9 5l7 7-7 7" />
								</svg>
							</a>
						</li>
					</ul>
					<div
						v-if="plan.excluded.length > 0"
						class="nl-product-finder__fallen"
						data-testid="nl-finder-excluded">
						<h3 class="nl-product-finder__fallen-title">
							{{ fallenText }}
						</h3>
						<ul class="nl-product-finder__fallen-list">
							<li
								v-for="product in plan.excluded"
								:key="product.route">
								<s>{{ product.title }}</s>
							</li>
						</ul>
					</div>
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
			choice: '',
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
			return (
				this.editingId !== ''
				&& this.plan.steps.some(
					(step) => step.question.id === this.editingId,
				)
			)
		},

		/**
		 * @return {object|null} The step on screen: the one being changed, else the first unanswered.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		asking() {
			if (this.editing) {
				return this.plan.steps.find(
					(step) => step.question.id === this.editingId,
				)
			}
			return this.plan.current >= 0 ? this.plan.steps[this.plan.current] : null
		},

		/**
		 * @return {string} "Vraag 3 van 5".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		position() {
			const index = this.plan.steps.findIndex(
				(step) => step.question.id === this.asking.question.id,
			)
			return this.say('position')
				.replace('{n}', String(index + 1))
				.replace('{total}', String(this.plan.steps.length))
		},

		/**
		 * @return {string} "Nog ongeveer 1 minuut".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		timeLeft() {
			const left = this.plan.steps.filter(
				(step) => step.answer === null,
			).length
			const minutes = minutesLeft(left)
			return this.say(minutes === 1 ? 'minutes' : 'minutesMany').replace(
				'{n}',
				String(minutes),
			)
		},

		/**
		 * @return {string} How far along the questions the resident is, as a width.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		progressShare() {
			const total = this.plan.steps.length
			return total === 0
				? '0%'
				: `${Math.round((this.answered.length / total) * 100)}%`
		},

		/**
		 * @return {Array<object>} Every product, remaining and ruled out.
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		allProducts() {
			return [...this.plan.remaining, ...this.plan.excluded]
		},

		/**
		 * @return {string} "Nog 4 van de 12 producten passen bij uw antwoorden".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		countText() {
			const total = this.plan.remaining.length + this.plan.excluded.length
			return this.say('count')
				.replace('{n}', String(this.plan.remaining.length))
				.replace('{total}', String(total))
		},

		/**
		 * @return {string} "Vallen af door uw antwoorden (8)".
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		fallenText() {
			return this.say('fallenAway').replace(
				'{n}',
				String(this.plan.excluded.length),
			)
		},
	},

	watch: {
		asking: {
			/**
			 * A question on screen starts from its own answer, if it has one.
			 *
			 * @param {object|null} step The question on screen.
			 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
			 */
			handler(step) {
				this.choice = step?.answer || ''
			},

			immediate: true,
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
		 * Record the chosen answer and move on.
		 *
		 * @return {void}
		 * @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04
		 */
		next() {
			if (this.choice === 'yes' || this.choice === 'no') {
				this.answer(this.choice)
			}
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
/* Board Productzoeker. Tokens only. */
.nl-product-finder {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.nl-product-finder__layout {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 2.5rem;
}

.nl-product-finder__ask {
	flex: 999 1 30rem;
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
	min-inline-size: 0;
}

.nl-product-finder__label,
.nl-product-finder__note {
	margin: 0;
}

.nl-product-finder__label {
	margin-block-end: 0.75rem;
	font-weight: 700;
}

.nl-product-finder__position {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 0.5rem;
	margin: 0 0 0.5rem;
}

.nl-product-finder__bar {
	block-size: 0.5rem;
	border-radius: 0.25rem;
	background: var(--nldesign-color-border, var(--utrecht-color-grey-90, Canvas));
	overflow: hidden;
}

.nl-product-finder__bar span {
	display: block;
	block-size: 100%;
	background: var(--nldesign-color-primary, currentcolor);
}

.nl-product-finder__chips,
.nl-product-finder__products,
.nl-product-finder__fallen-list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nl-product-finder__chip {
	display: inline-flex;
	align-items: center;
	gap: 0.375rem;
	min-block-size: 2.5rem;
	padding: 0 1rem;
	border: 0;
	border-radius: 1.25rem;
	background: var(--nldesign-color-primary-light, transparent);
	color: var(--nldesign-color-primary, inherit);
	font: inherit;
	cursor: pointer;
}

.nl-product-finder__glyph {
	flex: none;
	inline-size: 1rem;
	block-size: 1rem;
	fill: none;
	stroke: currentcolor;
	stroke-width: 2.4;
	stroke-linecap: round;
}

.nl-product-finder__question {
	display: flex;
	flex-direction: column;
	gap: 1.25rem;
	padding: 2rem;
	border: 1px solid
		var(
			--nldesign-website-card-border-color,
			var(--nldesign-color-border, currentcolor)
		);
	border-radius: var(--nldesign-website-border-radius-large, 0.375rem);
}

.nl-product-finder__fieldset {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	border: 0;
}

.nl-product-finder__legend {
	margin-block-end: 0.5rem;
	padding: 0;
	font-size: 1.875rem;
	font-weight: 700;
	line-height: 1.2;
}

.nl-product-finder__fieldset .utrecht-paragraph {
	margin: 0;
}

.nl-product-finder__options {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
	gap: 0.75rem;
	margin-block-start: 0.75rem;
}

.nl-product-finder__option {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	min-block-size: 4rem;
	padding: 0 1.5rem;
	border: 2px solid var(--nldesign-color-text, currentcolor);
	border-radius: var(--nldesign-website-border-radius, 0.25rem);
	font-size: 1.25rem;
	font-weight: 600;
	cursor: pointer;
}

.nl-product-finder__option input {
	inline-size: 1.5rem;
	block-size: 1.5rem;
	margin: 0;
	accent-color: var(--nldesign-color-primary, currentcolor);
}

.nl-product-finder__option--chosen {
	border-color: var(--nldesign-color-primary, currentcolor);
	background: var(--nldesign-color-primary-light, transparent);
}

.nl-product-finder__option:focus-within {
	outline: 3px solid var(--nldesign-color-focus, currentcolor);
	outline-offset: 2px;
}

.nl-product-finder__buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 1.25rem;
}

.nl-product-finder__buttons .utrecht-button {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
}

.nl-product-finder__restart {
	margin-inline-start: auto;
	text-decoration: underline;
}

.nl-product-finder__result {
	flex: 1 1 20rem;
	max-inline-size: 24.25rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1.5rem;
	border-radius: var(--nldesign-website-border-radius-large, 0.375rem);
	background: var(
		--nldesign-component-content-surface-background-color,
		var(--nldesign-color-background-hover, transparent)
	);
}

.nl-product-finder__result > * {
	margin: 0;
}

.nl-product-finder__segments {
	display: flex;
	gap: 0.25rem;
}

.nl-product-finder__segments span {
	flex: 1;
	block-size: 0.5rem;
	border-radius: 0.125rem;
	background: var(--nldesign-color-border, var(--utrecht-color-grey-90, Canvas));
}

.nl-product-finder__segments .nl-product-finder__segment--left {
	background: var(--nldesign-color-primary, currentcolor);
}

.nl-product-finder__products {
	flex-direction: column;
}

.nl-product-finder__product {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	padding: 1rem;
	border: 1px solid
		var(
			--nldesign-website-card-border-color,
			var(--nldesign-color-border, currentcolor)
		);
	border-radius: var(--nldesign-website-border-radius, 0.25rem);
	background: var(--nldesign-color-background, Canvas);
	color: var(--nldesign-color-text, inherit);
	text-decoration: none;
}

.nl-product-finder__product-text {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.9375rem;
}

.nl-product-finder__product-title {
	font-size: 1.125rem;
	font-weight: 600;
	text-decoration: underline;
}

.nl-product-finder__fallen {
	padding-block-start: 1rem;
	border-block-start: 1px solid
		var(
			--nldesign-website-card-border-color,
			var(--nldesign-color-border, currentcolor)
		);
}

.nl-product-finder__fallen-title {
	margin: 0 0 0.5rem;
	font-size: 1.0625rem;
	font-weight: 600;
}

.nl-product-finder__fallen-list {
	column-gap: 1rem;
	row-gap: 0.25rem;
	color: var(--nldesign-color-text-muted, inherit);
	font-size: 0.9375rem;
}
</style>
