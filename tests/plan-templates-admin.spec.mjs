#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// plan-templates-admin.spec.mjs — the admin page for plan templates (list,
// edit, publish) in the portal settings (shared-plans-with-a-caseworker T07,
// board PtPlansjablonen). It is manifest configuration over the object index
// and form, on the portalPlanTemplate schema the register declares.
//
// Usage:
//   node --test tests/plan-templates-admin.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

const read = (path) => JSON.parse(readFileSync(new URL(`../${path}`, import.meta.url), 'utf8'))
const manifest = read('src/manifest.json')
const register = read('lib/Settings/portaliq_register.json')
const page = manifest.pages.find((p) => p.id === 'PlanTemplates')
const schema = register.components.schemas.portalPlanTemplate

describe('the plan templates page', () => {
	it('lists the register\'s plan templates', () => {
		assert.equal(page.type, 'index')
		assert.equal(page.route, '/plan-templates')
		assert.equal(page.config.register, 'portaliq')
		assert.equal(page.config.schema, 'portalPlanTemplate')
	})

	it('shows only fields the schema declares, and whether a template is published', () => {
		const keys = page.config.columns.map((column) => column.key)
		assert.deepEqual(keys, ['title', 'summary', 'portal', 'durationDays', 'published'])
		for (const key of keys) {
			assert.ok(key in schema.properties, `${key} is a portalPlanTemplate property`)
		}
		assert.equal(schema.properties.published.type, 'boolean')
	})

	it('edits a template, its Published switch included, through the object form', () => {
		assert.deepEqual(page.config.actions, ['builtin:view', 'builtin:edit'])
	})

	it('is in the settings menu for administrators', () => {
		const item = manifest.menu.find((entry) => entry.id === 'PlanTemplatesMenu')
		assert.equal(item.route, 'PlanTemplates')
		assert.equal(item.section, 'settings')
		assert.deepEqual(item.visibleIf, { 'access.admin': true })
	})

	it('names its menu item in Dutch', () => {
		assert.equal(read('l10n/nl.json').translations['Plan templates'], 'Plansjablonen')
	})
})
