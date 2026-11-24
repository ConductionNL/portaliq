// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The article lifecycle as the interface reads it: the chip each stored
// status renders, the moves a button may offer from it, and how usage rows
// are grouped by the app that reported them (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	ARTICLE_STATUSES,
	chipForStatus,
	groupUsages,
	transitionsForStatus,
} from '../src/lib/articleStatus.js'

const LIFECYCLE = JSON.parse(
	readFileSync(new URL('../lib/Settings/portaliq_register.json', import.meta.url), 'utf8'),
)

test('the statuses are the lifecycle order the schema declares', () => {
	assert.deepEqual(ARTICLE_STATUSES, ['draft', 'review', 'published', 'archived'])
})

test('each declared status renders its own chip', () => {
	assert.deepEqual(chipForStatus('draft'), { label: 'Draft', color: 'var(--color-text-maxcontrast)' })
	assert.deepEqual(chipForStatus('review'), { label: 'In review', color: 'var(--color-warning)' })
	assert.deepEqual(chipForStatus('published'), { label: 'Published', color: 'var(--color-success)' })
	assert.deepEqual(chipForStatus('archived'), { label: 'Archived', color: 'var(--color-text-maxcontrast)' })
})

test('every chip colour is a Nextcloud CSS variable', () => {
	for (const status of [...ARTICLE_STATUSES, 'something-new']) {
		assert.match(chipForStatus(status).color, /^var\(--color-[a-z-]+\)$/, status)
	}
})

test('a status this build has never heard of renders a neutral Unknown chip', () => {
	assert.deepEqual(chipForStatus('scheduled'), { label: 'Unknown', color: 'var(--color-text-maxcontrast)' })
	assert.deepEqual(chipForStatus(undefined), { label: 'Unknown', color: 'var(--color-text-maxcontrast)' })
	assert.deepEqual(chipForStatus(''), { label: 'Unknown', color: 'var(--color-text-maxcontrast)' })
})

test('a returned chip is a copy, so a caller cannot repaint the next one', () => {
	const chip = chipForStatus('published')
	chip.label = 'Changed'
	chip.color = 'red'
	assert.deepEqual(chipForStatus('published'), { label: 'Published', color: 'var(--color-success)' })
})

test('a draft can go to review or be published at once', () => {
	assert.deepEqual(transitionsForStatus('draft'), [
		{ id: 'submitForReview', label: 'Submit for review', endpoint: 'transition' },
		{ id: 'publish', label: 'Publish', endpoint: 'publish' },
	])
})

test('an article in review goes back to draft or is published', () => {
	assert.deepEqual(transitionsForStatus('review'), [
		{ id: 'returnToDraft', label: 'Return to draft', endpoint: 'transition' },
		{ id: 'publish', label: 'Publish', endpoint: 'publish' },
	])
})

test('a published article can only be archived, through its own route', () => {
	assert.deepEqual(transitionsForStatus('published'), [
		{ id: 'archive', label: 'Archive', endpoint: 'archive' },
	])
})

test('an archived article can only be restored as a draft', () => {
	assert.deepEqual(transitionsForStatus('archived'), [
		{ id: 'restore', label: 'Restore as draft', endpoint: 'transition' },
	])
})

test('an unknown status offers no move at all', () => {
	assert.deepEqual(transitionsForStatus('scheduled'), [])
	assert.deepEqual(transitionsForStatus(undefined), [])
})

test('the moves returned are copies, so a caller cannot rewrite the table', () => {
	const moves = transitionsForStatus('draft')
	moves[0].endpoint = 'publish'
	moves.pop()
	assert.equal(transitionsForStatus('draft').length, 2)
	assert.equal(transitionsForStatus('draft')[0].endpoint, 'transition')
})

test('only publish and archive use their own routes; the rest ride the transition route', () => {
	for (const status of ARTICLE_STATUSES) {
		for (const move of transitionsForStatus(status)) {
			const expected = ['publish', 'archive'].includes(move.id) ? move.id : 'transition'
			assert.equal(move.endpoint, expected, `${status} -> ${move.id}`)
		}
	}
})

test('every move offered is declared by the article schema, from that status', () => {
	const schema = findSchema(LIFECYCLE, 'article')
	assert.ok(schema, 'the register declares the article schema')
	const transitions = schema['x-openregister-lifecycle'].transitions
	for (const status of ARTICLE_STATUSES) {
		for (const move of transitionsForStatus(status)) {
			assert.ok(Object.hasOwn(transitions, move.id), `${status} offers ${move.id}, which the schema does not declare`)
			assert.ok(transitions[move.id].from.includes(status), `${move.id} is not declared from ${status}`)
		}
	}
})

test('every move leads where the schema says it does', () => {
	const transitions = findSchema(LIFECYCLE, 'article')['x-openregister-lifecycle'].transitions
	assert.equal(transitions.submitForReview.to, 'review')
	assert.equal(transitions.returnToDraft.to, 'draft')
	assert.equal(transitions.publish.to, 'published')
	assert.equal(transitions.archive.to, 'archived')
	assert.equal(transitions.restore.to, 'draft')
})

test('usage rows are grouped by app, portaliq first, then the other apps by name', () => {
	const groups = groupUsages([
		{ id: 'u1', title: 'Nieuwsbrief maart', kind: 'mailing', app: 'pipelinq', url: '/a' },
		{ id: 'u2', title: 'Home', kind: 'page', app: 'portaliq', url: '/b' },
		{ id: 'u3', title: 'Campagne', kind: 'campaign', app: 'decidiq', url: '/c' },
		{ id: 'u4', title: 'Over ons', kind: 'page', app: 'portaliq', url: '/d' },
	])

	assert.deepEqual(groups.map((group) => group.app), ['portaliq', 'decidiq', 'pipelinq'])
	assert.deepEqual(groups[0].items.map((item) => item.id), ['u2', 'u4'])
	assert.deepEqual(groups[1].items.map((item) => item.id), ['u3'])
	assert.deepEqual(groups[2].items.map((item) => item.id), ['u1'])
})

test('rows keep the order they arrived in within their app', () => {
	const groups = groupUsages([
		{ id: 'z', app: 'portaliq' },
		{ id: 'a', app: 'portaliq' },
		{ id: 'm', app: 'portaliq' },
	])
	assert.deepEqual(groups, [{ app: 'portaliq', items: [{ id: 'z', app: 'portaliq' }, { id: 'a', app: 'portaliq' }, { id: 'm', app: 'portaliq' }] }])
})

test('a row without an id, or that is no object, is left out', () => {
	const groups = groupUsages([
		null,
		'portaliq',
		{ app: 'portaliq', title: 'No id' },
		{ id: '', app: 'portaliq' },
		{ id: 'kept', app: 'portaliq' },
	])
	assert.deepEqual(groups, [{ app: 'portaliq', items: [{ id: 'kept', app: 'portaliq' }] }])
})

test('a row without an app is grouped under the empty app name, after portaliq', () => {
	const groups = groupUsages([
		{ id: 'n1' },
		{ id: 'p1', app: 'portaliq' },
	])
	assert.deepEqual(groups.map((group) => group.app), ['portaliq', ''])
	assert.deepEqual(groups[1].items, [{ id: 'n1' }])
})

test('no rows, or something that is no list, gives no groups', () => {
	assert.deepEqual(groupUsages([]), [])
	assert.deepEqual(groupUsages(undefined), [])
	assert.deepEqual(groupUsages(null), [])
	assert.deepEqual(groupUsages({ id: 'x', app: 'portaliq' }), [])
})

test('no group is ever empty', () => {
	const groups = groupUsages([{ id: 'a', app: 'b' }, { app: 'c' }, { id: 'd', app: 'e' }])
	assert.ok(groups.every((group) => group.items.length > 0))
	assert.deepEqual(groups.map((group) => group.app), ['b', 'e'])
})

/**
 * The schema with this slug in a register descriptor, or null.
 *
 * @param {object} register The descriptor.
 * @param {string} slug The schema slug.
 * @return {object|null} The schema.
 */
function findSchema(register, slug) {
	const schemas = register?.components?.schemas || {}
	for (const [key, schema] of Object.entries(schemas)) {
		if (key === slug || schema?.slug === slug) {
			return schema
		}
	}
	return null
}
