// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Compiles only the script of a Vue single-file component that imports other
// single-file components, and hands back its options object: every imported
// `.vue` file becomes `{ name }`, every named import from a component library
// (`@nextcloud/*`, `@conduction/nextcloud-vue`, icons) becomes `{ name }`, and
// the app's own JavaScript modules load for real, JSON included. For the
// shell components (the site's App.vue) whose children node cannot load, so a
// test can call their computed getters and methods on a stand-in `this`
// (page-instance.mjs) without mounting.

import { compileScript, parse } from '@vue/compiler-sfc'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { register } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'sfc-options-loaded', String(process.pid))

register(
	'data:text/javascript,' + encodeURIComponent(
		'export async function load(url, context, next) {'
		+ " if (url.endsWith('.json')) { return next(url, { ...context, importAttributes: { type: 'json' } }) }"
		+ ' return next(url, context) }',
	),
)

let counter = 0

/**
 * The names an import clause binds.
 *
 * @param {string} clause What sits between `import` and `from`.
 * @return {Array<string>} The local names.
 */
function boundNames(clause) {
	return clause
		.replace(/[{}]/g, '')
		.split(',')
		.map((part) => part.trim().split(/\s+as\s+/).pop())
		.filter(Boolean)
}

/**
 * `const` declarations standing in for one import, or null to keep it.
 *
 * @param {string} clause What sits between `import` and `from`.
 * @param {string} spec The module imported.
 * @return {string|null} The replacement.
 */
function stubFor(clause, spec) {
	if (spec.endsWith('.vue') || (!spec.startsWith('.') && spec !== 'vue')) {
		return boundNames(clause).map((n) => `const ${n} = { name: '${n}' }`).join('\n')
	}
	return null
}

/**
 * The options object of a single-file component.
 *
 * @param {string} rel The `.vue` file, relative to the repository.
 * @return {Promise<object>} Its default export.
 */
export async function sfcOptionsLoaded(rel) {
	mkdirSync(OUT, { recursive: true })
	const file = join(ROOT, rel)
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	let code = compileScript(descriptor, { id: 'x' }).content
	code = code.replace(/import\s+([^'"]+?)\s+from\s+['"]([^'"]+)['"]/g, (match, clause, spec) => {
		const stub = stubFor(clause, spec)
		if (stub !== null) {
			return stub
		}
		if (spec.startsWith('.')) {
			return `import ${clause} from '${pathToFileURL(resolve(dirname(file), spec)).href}'`
		}
		return match
	})
	const out = join(OUT, `${rel.replace(/[^a-z]/gi, '_')}-${counter++}.mjs`)
	writeFileSync(out, code)
	return (await import(pathToFileURL(out).href)).default
}
