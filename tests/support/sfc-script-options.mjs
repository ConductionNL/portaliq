// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Compiles only the script of a Vue single-file component and hands back its
// options object, with every component library stubbed: a named import from
// `@nextcloud/vue` or `@conduction/nextcloud-vue`, and a default import of a
// `vue-material-design-icons` icon, each become `{ name }`; `translate` from
// `@nextcloud/l10n` hands the text back. Relative imports load for real. For
// dialogs whose library components node cannot load, so a test can pin the
// options (props, state, computed, methods) without mounting.

import { compileScript, parse } from '@vue/compiler-sfc'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'sfc-script-options', String(process.pid))
const LIBRARIES = ['@nextcloud/vue', '@conduction/nextcloud-vue']

let counter = 0

/**
 * `const` declarations standing in for one library import.
 *
 * @param {string} clause What sits between `import` and `from`.
 * @param {string} spec The module imported.
 * @return {string|null} The replacement, or null to keep the import.
 */
function stubFor(clause, spec) {
	if (spec === '@nextcloud/l10n') {
		const local = /translate\s+as\s+(\w+)|\btranslate\b/.exec(clause)
		return `const ${local[1] || 'translate'} = (app, text) => text`
	}
	if (spec.startsWith('vue-material-design-icons/')) {
		return `const ${clause.trim()} = { name: '${clause.trim()}' }`
	}
	if (LIBRARIES.includes(spec)) {
		const names = clause.replace(/[{}]/g, '').split(',').map((n) => n.trim()).filter(Boolean)
		return names.map((n) => `const ${n} = { name: '${n}' }`).join('\n')
	}
	return null
}

/**
 * The options object of a single-file component.
 *
 * @param {string} rel The `.vue` file, relative to the repository.
 * @return {Promise<object>} Its default export.
 */
export async function sfcScriptOptions(rel) {
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
