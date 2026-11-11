#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shell-title-and-side-menu.spec.mjs: the site shell (src/site/App.vue)
// decides when the side region renders as a menu column and what the browser
// tab is titled, pinned on every branch before both were split into named
// steps.
//
// Usage:
//   node --test tests/site-shell-title-and-side-menu.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { sfcOptionsLoaded } from './support/sfc-options-loaded.mjs'

const App = await sfcOptionsLoaded('src/site/App.vue')

/**
 * A stand-in `this`: the given state, and every other computed getter and
 * method of the shell bound to it.
 *
 * @param {object} state The state the code under test reads.
 * @return {object} The stand-in.
 */
function shellWith(state) {
	const self = { ...state }
	for (const [name, getter] of Object.entries(App.computed)) {
		if (!Object.hasOwn(self, name)) {
			Object.defineProperty(self, name, { get: getter.bind(self) })
		}
	}
	for (const [name, method] of Object.entries(App.methods)) {
		if (!Object.hasOwn(self, name)) {
			self[name] = method.bind(self)
		}
	}
	return self
}

/**
 * The shell's state as the side menu reads it, with the menu block placed
 * on an ordinary CMS page.
 *
 * @param {object} over The state to change.
 * @return {object} The stand-in `this`.
 */
function shell(over = {}) {
	return shellWith({
		regions: { aside: [{ widgetKey: 'siteNavigation' }] },
		guestLink: null,
		wayInLink: null,
		sharedDossierRoute: false,
		statementRoute: false,
		error: null,
		editMode: false,
		editing: null,
		page: { title: 'Afval' },
		accountRoute: false,
		...over,
	})
}

const showSideMenu = (over) => shell(over).showSideMenu

test('the side menu shows on a CMS page whose side region holds the menu block', () => {
	assert.equal(showSideMenu({}), true)
	assert.equal(showSideMenu({ editMode: true }), true, 'edit mode without a page being edited')
	assert.equal(showSideMenu({ editMode: true, editing: {} }), true)
	assert.equal(showSideMenu({ editing: { pageId: 'p' } }), true, 'an editing context outside edit mode')
})

test('the side menu stays out without the block, on a one-off screen, in the editor and in the account area', () => {
	assert.equal(showSideMenu({ regions: { aside: [{ widgetKey: 'markdown' }] } }), false)
	assert.equal(showSideMenu({ regions: null }), false)
	assert.equal(showSideMenu({ guestLink: { token: 't' } }), false)
	assert.equal(showSideMenu({ wayInLink: { kind: 'activate' } }), false)
	assert.equal(showSideMenu({ sharedDossierRoute: true }), false)
	assert.equal(showSideMenu({ statementRoute: true }), false)
	assert.equal(showSideMenu({ error: { status: 404 } }), false)
	assert.equal(showSideMenu({ editMode: true, editing: { pageId: 'p' } }), false)
	assert.equal(showSideMenu({ page: null }), false)
	assert.equal(showSideMenu({ page: undefined }), true, 'only null counts as no page')
	assert.equal(showSideMenu({ accountRoute: true }), false)
})

/**
 * The tab title the shell sets for a state.
 *
 * @param {object} over The state the title reads.
 * @return {string} The document title.
 */
function titleFor(over) {
	globalThis.document = { title: 'unchanged' }
	shellWith({
		site: { title: 'Gemeente X' },
		t: (text) => `«${text}»`,
		accountRoute: false,
		accountEntry: null,
		page: null,
		sharedDossierRoute: false,
		sharedDossierTitle: '',
		statementRoute: false,
		subject: null,
		...over,
	}).applyDocumentTitle()
	return globalThis.document.title
}

test('the tab names the page, then the portal', () => {
	assert.equal(titleFor({ site: {} }), 'unchanged', 'no portal name, no title')
	assert.equal(titleFor({ site: { title: '' } }), 'unchanged')
	assert.equal(titleFor({}), 'Gemeente X')
	assert.equal(titleFor({ page: { title: 'Afval' } }), 'Afval - Gemeente X')
	assert.equal(titleFor({ page: { title: 'Afval', seo: { title: 'Afval scheiden' } } }), 'Afval scheiden - Gemeente X')
	assert.equal(titleFor({ page: { title: 'Afval', seo: { title: '' } } }), 'Afval - Gemeente X')
	assert.equal(titleFor({ page: { title: 'Gemeente X' } }), 'Gemeente X', 'a page named as the portal is not repeated')
	assert.equal(titleFor({ accountRoute: true, page: { title: 'Afval' } }), '«My area» - Gemeente X')
	assert.equal(titleFor({ accountRoute: true, accountEntry: { label: 'Mijn zaken' } }), 'Mijn zaken - Gemeente X')
	assert.equal(titleFor({ accountRoute: true, accountEntry: { label: '' } }), '«My area» - Gemeente X')
})

test('a shared dossier, the statement and a subject each name the tab, the subject last', () => {
	const page = { title: 'Afval' }
	assert.equal(titleFor({ page, sharedDossierRoute: true }), '«Shared dossier» - Gemeente X')
	assert.equal(titleFor({ page, sharedDossierRoute: true, sharedDossierTitle: 'Dossier 7' }), 'Dossier 7 - Gemeente X')
	assert.equal(titleFor({ page, sharedDossierRoute: true, statementRoute: true }), '«Accessibility statement» - Gemeente X')
	assert.equal(titleFor({ page, statementRoute: true, subject: { title: 'Wonen' } }), 'Wonen - Gemeente X')
	assert.equal(titleFor({ page, subject: { title: '' } }), 'Gemeente X', 'a subject without a title leaves only the portal')
	assert.equal(titleFor({ accountRoute: true, subject: { title: 'Wonen' } }), 'Wonen - Gemeente X')
})
