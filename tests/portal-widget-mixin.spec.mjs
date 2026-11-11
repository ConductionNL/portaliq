#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-widget-mixin.spec.mjs: the portal detail page's widgets each take
// the portal as `objectData` and read its slug (and the home page widget its title)
// from it, pinned before that shared part moved into one mixin. The
// component's own options and its mixins are read together, as Vue does.
//
// Usage:
//   node --test tests/portal-widget-mixin.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

globalThis.t = (app, text) => text
const STUBS = {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), put: async () => ({ data: {} }), post: async () => ({ data: {} }) }',
	'@nextcloud/router': 'export const generateUrl = (path) => path',
	'@nextcloud/vue': 'export const NcButton = {}, NcCheckboxRadioSwitch = {}, NcLoadingIcon = {}, NcNoteCard = {}, NcTextArea = {}, NcTextField = {}, NcPasswordField = {}',
}

/**
 * One option group of a component with its mixins', the component's own winning.
 *
 * @param {object} component The component options.
 * @param {string} key `props` or `computed`.
 * @return {object} The merged group.
 */
function merged(component, key) {
	const fromMixins = (component.mixins || []).reduce((all, mixin) => ({ ...all, ...(mixin[key] || {}) }), {})
	return { ...fromMixins, ...(component[key] || {}) }
}

for (const name of ['PortalAccessibility', 'PortalHomePage', 'PortalSignin', 'PortalTheme']) {
	test(`${name} takes the portal and reads its slug`, async () => {
		const component = await loadSfc(`src/widgets/${name}.vue`, STUBS)
		const props = merged(component, 'props')
		assert.equal(props.objectData.type, Object)
		assert.equal(props.objectData.default, null)
		const computed = merged(component, 'computed')
		assert.equal(computed.slug.call({ objectData: { slug: 'school' } }), 'school')
		assert.equal(computed.slug.call({ objectData: null }), '')
		assert.equal(computed.slug.call({ objectData: { slug: 5 } }), '5')
		if (name === 'PortalHomePage') {
			assert.equal(computed.portalTitle.call({ objectData: { title: 'De Linde' }, slug: 'school' }), 'De Linde')
			assert.equal(computed.portalTitle.call({ objectData: {}, slug: 'school' }), 'school')
		}
	})
}
