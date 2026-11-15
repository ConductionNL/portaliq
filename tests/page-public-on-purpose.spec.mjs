#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-public-on-purpose.spec.mjs — publishing is the act that makes a page
// public, the editor confirms it knowing who can read it and where, and a
// published page can be taken back to a draft from the page list.
//
// Usage:
//   node --test tests/page-public-on-purpose.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { draftStatusPayload, publishPayload } from '../src/editor/pageBody.js'
import { createPageVisibilityHandlers } from '../src/lib/pageVisibility.js'

function source (path) {
  return readFileSync(fileURLToPath(new URL(`../${path}`, import.meta.url)), 'utf8')
}

describe('publishing makes the page public (1.1)', () => {
	it('stores the published body with the status published, and drops the draft', () => {
		const page = { id: 'p1', title: 'Afval en milieu', route: '/afval', status: 'draft', body: 'old', draftBody: 'new' }
		const payload = publishPayload(page, 'new')
		assert.equal(payload.status, 'published')
		assert.equal(payload.body, 'new')
		assert.equal('draftBody' in payload, false)
		assert.equal(payload.route, '/afval')
	})

	it('the confirmation says who can read the page and where, and closes with the choice', () => {
		const dialog = source('src/dialogs/PublishPageDialog.vue')
		assert.match(dialog, /t\('portaliq', 'Anyone can read this page, without signing in\.'\)/)
		assert.match(dialog, /:href="address"/)
		assert.match(dialog, /data-testid="publish-page-confirm"\s+@click="\$emit\('close', true\)"/)
		assert.match(dialog, /data-testid="publish-page-cancel" @click="\$emit\('close', false\)"/)
		assert.match(dialog, /@closing="\$emit\('close', false\)"/)
	})

	it('the designer and the site edit mode publish only after the confirmation', () => {
		const designer = source('src/views/PageLayoutDesigner.vue')
		assert.match(designer, /data-testid="designer-publish"[\s\S]*?@click="isBlock \? editor\.publish\(\) : \(confirmingPublish = true\)"/)
		assert.match(designer, /<PublishPageDialog\s+v-if="confirmingPublish"\s+:address="siteUrl"\s+@close="publishConfirmed" \/>/)
		assert.match(designer, /if \(confirmed === true\) \{\s+await this\.editor\.publish\(\)/)

		const site = source('src/editor/SiteEditMode.vue')
		assert.match(site, /data-testid="site-edit-publish"[\s\S]*?@click="confirmingPublish = true"/)
		assert.match(site, /<PublishPageDialog\s+v-if="confirmingPublish"\s+:address="publicAddress"\s+@close="publish" \/>/)
		assert.match(site, /if \(confirmed !== true\) \{\s+return\s+\}\s+await this\.editor\.publish\(\)/)
	})
})

describe('the page list (1.2)', () => {
	const manifest = JSON.parse(source('src/manifest.json'))
	const pages = manifest.pages.find((page) => page.id === 'Pages')

	it('says in words whether a page is public or a draft', () => {
		const status = pages.config.columns.find((column) => column?.key === 'status')
		assert.deepEqual(status.enumLabels, { published: 'Public', draft: 'Draft' })
	})

	it('offers taking a published page back to draft', () => {
		const action = pages.config.actions.find((a) => a.id === 'take-page-back-to-draft')
		assert.equal(action.type, 'handler')
		assert.equal(action.handler, 'takePageBackToDraft')
		assert.deepEqual(action.visibleWhen, { field: 'status', op: 'eq', value: 'published' })
		assert.match(source('src/customComponents.js'), /\.\.\.pageVisibilityHandlers,/)
	})

	it('a page taken back keeps its body and draft, only its status changes', () => {
		const page = { id: 'p1', status: 'published', body: 'b', draftBody: 'd' }
		assert.deepEqual(draftStatusPayload(page), { id: 'p1', status: 'draft', body: 'b', draftBody: 'd' })
	})
})

/**
 * The handlers over fake collaborators.
 *
 * @param {object} setup How the saver answers.
 * @return {object} The handlers and what they did.
 */
function visibility(setup = {}) {
	const done = { loads: [], saves: [], notified: [], errors: [], reloads: 0 }
	const handlers = createPageVisibilityHandlers({
		saver: {
			load: async (id) => {
				done.loads.push(id)
				return { page: { id, title: 'Afval en milieu', status: 'published', body: 'b' }, version: 'v7' }
			},
			save: async (id, payload, version) => {
				done.saves.push([id, payload, version])
				if (setup.saveFails) {
					throw setup.saveFails
				}
			},
		},
		translate: (text) => `t:${text}`,
		notify: (message) => done.notified.push(message),
		notifyError: (message) => done.errors.push(message),
		reload: () => { done.reloads += 1 },
	})
	return { handlers, done }
}

describe('taking a page back to draft', () => {
	it('writes the status draft against the version it read, says so and shows the list again', async () => {
		const { handlers, done } = visibility()
		assert.equal(await handlers.takePageBackToDraft({ item: { '@self': { id: 'p1' } } }), true)
		assert.deepEqual(done.loads, ['p1'])
		assert.deepEqual(done.saves, [['p1', { id: 'p1', title: 'Afval en milieu', status: 'draft', body: 'b' }, 'v7']])
		assert.deepEqual(done.notified, ['t:The page is a draft again. Nobody can read it without signing in.'])
		assert.equal(done.reloads, 1)
	})

	it('a refused write leaves the list as it is and says so', async () => {
		const { handlers, done } = visibility({ saveFails: new Error('conflict') })
		assert.equal(await handlers.takePageBackToDraft({ item: { id: 'p1' } }), false)
		assert.deepEqual(done.errors, ['t:The page could not be taken back to a draft. Try again.'])
		assert.equal(done.reloads, 0)
	})

	it('a row without an id does nothing', async () => {
		const { handlers, done } = visibility()
		assert.equal(await handlers.takePageBackToDraft({ item: {} }), false)
		assert.deepEqual(done.loads, [])
	})
})
