// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Compiles only the script of a Vue single-file component and hands back its
// options object, with EVERY import replaced: the module named in `stubs`
// gets that source, a component library (`@nextcloud/vue`,
// `@conduction/nextcloud-vue`) and an icon become `{ name }` objects,
// `@nextcloud/l10n`'s `translate` hands the text back, a CSS import is
// dropped, and any other relative import loads for real. For components whose
// API client a test has to record, which sfc-script-options.mjs loads for
// real. Drive the options with page-instance.mjs.
import { compileScript, parse } from '@vue/compiler-sfc'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'sfc-script-stubbed', String(process.pid))
const LIBRARIES = ['@nextcloud/vue', '@conduction/nextcloud-vue']
let counter = 0

/**
 * Names bound by an import clause (`a`, `{ b, c as d }`, `e, { f }`).
 *
 * @param {string} clause What sits between `import` and `from`.
 * @return {string[]} The local names.
 */
function localNames(clause) {
	const names = []
	const braces = /\{([^}]*)\}/.exec(clause)
	if (braces) {
		for (const part of braces[1].split(',')) {
			const name = part.trim().split(/\s+as\s+/).pop()
			if (name) {
				names.push(name)
			}
		}
	}
	const head = clause.replace(/\{[^}]*\}/, '').replace(/,/g, ' ').trim()
	if (head && !head.startsWith('*')) {
		names.push(head)
	}
	return names
}

/**
 * The options of one component, its imports stubbed.
 *
 * @param {string} rel Path of the `.vue` file, relative to the repository.
 * @param {object} stubs Import specifier (as written in the file) to module source.
 * @return {Promise<object>} The component options.
 */
export async function sfcScriptStubbed(rel, stubs = {}) {
	const file = join(ROOT, rel)
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	let code = compileScript(descriptor, { id: `s${counter}` }).content
	const dir = join(OUT, String(counter++))
	mkdirSync(dir, { recursive: true })

	code = code.replace(/^import\s+['"][^'"]+\.css['"];?\s*$/gm, '')
	code = code.replace(/^import\s+([\s\S]+?)\s+from\s+['"]([^'"]+)['"];?\s*$/gm, (match, clause, spec) => {
		if (Object.hasOwn(stubs, spec)) {
			const stubFile = join(dir, `stub-${spec.replace(/[^a-z0-9]/gi, '_')}.mjs`)
			writeFileSync(stubFile, stubs[spec])
			return `import ${clause} from '${pathToFileURL(stubFile).href}'`
		}
		if (spec === '@nextcloud/l10n') {
			return localNames(clause).map((name) => `const ${name} = (app, text, vars) => vars ? text + ' ' + JSON.stringify(vars) : text`).join('\n')
		}
		if (LIBRARIES.includes(spec) || spec.startsWith('vue-material-design-icons/')) {
			return localNames(clause).map((name) => `const ${name} = { name: '${name}' }`).join('\n')
		}
		if (spec.startsWith('.')) {
			return `import ${clause} from '${pathToFileURL(resolve(dirname(file), spec)).href}'`
		}
		return match
	})

	const out = join(dir, 'component.mjs')
	writeFileSync(out, code)
	return (await import(pathToFileURL(out).href)).default
}
