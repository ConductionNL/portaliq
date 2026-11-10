// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The product finder's evaluation, in plain functions so the browser and the
// tests run the same code. Nothing here touches the network or storage: the
// answers live in the caller's memory and nowhere else.
//
// @spec openspec/changes/public-faq-and-product-finder/tasks.md#t04

/**
 * The routes a question rules out for an answer.
 *
 * @param {object} question The question.
 * @param {string} answer `yes` or `no`.
 * @return {Array<string>} The routes.
 */
function excludedBy(question, answer) {
	const list = answer === 'yes' ? question.excludesOnYes : question.excludesOnNo
	return Array.isArray(list) ? list : []
}

/**
 * Whether an answer to a question could change what remains.
 *
 * @param {object} question The question.
 * @param {Set<string>} remaining The routes still possible.
 * @return {boolean} False when neither answer would rule anything out.
 */
function canChange(question, remaining) {
	return ['yes', 'no'].some((answer) =>
		excludedBy(question, answer).some((route) => remaining.has(route)),
	)
}

/**
 * Work out where a resident stands.
 *
 * Walks the questions in order. A question counts only when an answer could
 * still change the remaining set; one that cannot is skipped, answered or not.
 * An answered question that counts rules out its products.
 *
 * @param {{products: Array<{route: string, title: string}>, questions: Array<object>}} finder The finder.
 * @param {Record<string, string>} answers The answers by question id (`yes` or `no`).
 * @return {{steps: Array<{question: object, answer: string|null}>, current: number, remaining: Array<object>, excluded: Array<object>, done: boolean}} The plan: the questions that count, the index of the first one unanswered (-1 when done), and the products that remain and that fell away.
 */
export function planFinder(finder, answers) {
	const products = Array.isArray(finder?.products) ? finder.products : []
	const remaining = new Set(products.map((product) => product.route))
	const steps = []
	for (const question of Array.isArray(finder?.questions)
		? finder.questions
		: []) {
		// A question neither answer could rule anything out with is skipped,
		// answered or not.
		if (canChange(question, remaining)) {
			steps.push(takeStep(question, { answers, remaining }))
		}
	}
	return planOf(steps, { products, remaining })
}

/**
 * One question that counts, as a step; an answer to it rules out its
 * products.
 *
 * @param {object} question The question.
 * @param {{answers: Record<string, string>, remaining: Set<string>}} walk The answers, and the routes still possible.
 * @return {{question: object, answer: string|null}} The step.
 */
function takeStep(question, walk) {
	const answer = walk.answers[question.id]
	if (answer !== 'yes' && answer !== 'no') {
		return { question, answer: null }
	}
	for (const route of excludedBy(question, answer)) {
		walk.remaining.delete(route)
	}
	return { question, answer }
}

/**
 * @param {Array<{question: object, answer: string|null}>} steps The questions that count.
 * @param {{products: Array<object>, remaining: Set<string>}} outcome The products and the routes that remain.
 * @return {object} The plan.
 */
function planOf(steps, outcome) {
	const { products, remaining } = outcome
	return {
		steps,
		current: steps.findIndex((step) => step.answer === null),
		remaining: products.filter((product) => remaining.has(product.route)),
		excluded: products.filter((product) => !remaining.has(product.route)),
		done: steps.every((step) => step.answer !== null),
	}
}

/**
 * The answers that still count after one changed: an answer to a question
 * that is skipped now is dropped, so a later change cannot revive it.
 *
 * @param {object} finder The finder.
 * @param {Record<string, string>} answers The answers.
 * @return {Record<string, string>} The answers that are steps of the plan.
 */
export function pruneAnswers(finder, answers) {
	const kept = {}
	for (const step of planFinder(finder, answers).steps) {
		if (step.answer !== null) {
			kept[step.question.id] = step.answer
		}
	}
	return kept
}

/**
 * About how long the questions left take, in whole minutes.
 *
 * @param {number} left How many questions are left.
 * @return {number} Minutes, at least 1; 20 seconds a question.
 */
export function minutesLeft(left) {
	return Math.max(1, Math.ceil((left * 20) / 60))
}
