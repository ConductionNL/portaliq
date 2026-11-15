// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Renders a Vue single-file component to HTML in plain node, with no DOM and
// no Nextcloud globals. The site's blocks must mount at a public origin, so a
// test that renders one here also proves it reaches for nothing Nextcloud
// provides (portal-theme-blocks-and-contributed-pages REQ-PTB-007).
//
// Relative `.vue` imports are compiled recursively. A bare import can be
// replaced with `stubs`: `{ '@conduction/nextcloud-vue/public': 'export const
// CnSiteIcon = {...}' }`, because the library's public entry imports `.vue`
// files node cannot load.

import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
// Inside the repository so the compiled modules resolve `vue` from node_modules.
const OUT = join(ROOT, 'node_modules', '.cache', 'render-sfc')

let counter = 0

/**
 * Compile one SFC (and the `.vue` files it imports) into an ES module.
 *
 * @param {string} file  Absolute path of the `.vue` file.
 * @param {object} stubs Bare specifier to module source.
 * @param {string} dir   The output directory for this load.
 * @return {string} The compiled module's path.
 */
function compile(file, stubs, dir) {
	const source = readFileSync(file, 'utf8')
	const { descriptor } = parse(source, { filename: file })
	const id = `sfc${counter++}`
	const script = compileScript(descriptor, { id, inlineTemplate: false })
	let code = script.content.replace('export default', 'const __sfc__ =')

	const template = compileTemplate({
		source: descriptor.template.content,
		filename: file,
		id,
		ssr: true,
		compilerOptions: { mode: 'module', comments: false },
	})

	code += `\n${template.code}\n__sfc__.ssrRender = ssrRender\nexport default __sfc__\n`
	// A side-effect CSS import is dropped, as in mount-sfc.mjs: node cannot
	// load it and no test reads it (the forms layer imports Utrecht CSS).
	code = code.replace(/^import\s+['"][^'"]+\.css['"];?\s*$/gm, '')

	code = code.replace(/from\s+['"]([^'"]+)['"]/g, (match, spec) => {
		if (spec.endsWith('.vue') && spec.startsWith('.')) {
			return `from '${pathToFileURL(compile(resolve(dirname(file), spec), stubs, dir)).href}'`
		}
		if (Object.hasOwn(stubs, spec)) {
			const stubFile = join(
				dir,
				`stub-${spec.replace(/[^a-z0-9]/gi, '_')}.mjs`,
			)
			writeFileSync(stubFile, stubs[spec])
			return `from '${pathToFileURL(stubFile).href}'`
		}
		if (spec.startsWith('.')) {
			return `from '${pathToFileURL(resolve(dirname(file), spec)).href}'`
		}
		return match
	})

	const out = join(dir, `${basename(file, '.vue')}-${id}.mjs`)
	writeFileSync(out, code)
	return out
}

/**
 * Render a component to an HTML string.
 *
 * @param {string} file  Path of the `.vue` file, relative to the repository.
 * @param {object} props The props to render with.
 * @param {object} stubs Bare specifier to module source.
 * @return {Promise<string>} The rendered HTML.
 */
export async function renderSfc(file, props = {}, stubs = {}) {
	const dir = join(OUT, `${process.pid}-${counter++}`)
	mkdirSync(dir, { recursive: true })
	const compiled = compile(join(ROOT, file), stubs, dir)
	const component = (await import(pathToFileURL(compiled).href)).default
	const { createSSRApp, h } = await import('vue')
	const { renderToString } = await import('vue/server-renderer')
	return renderToString(createSSRApp({ render: () => h(component, props) }))
}

/**
 * Load a component's options without rendering it, so a test can call its
 * methods and computed getters with a stand-in `this`.
 *
 * @param {string} file  Path of the `.vue` file, relative to the repository.
 * @param {object} stubs Bare specifier to module source.
 * @return {Promise<object>} The component options.
 */
export async function loadSfc(file, stubs = {}) {
	const dir = join(OUT, `${process.pid}-${counter++}`)
	mkdirSync(dir, { recursive: true })
	const compiled = compile(join(ROOT, file), stubs, dir)
	return (await import(pathToFileURL(compiled).href)).default
}

/**
 * Render compiled component options to an HTML string.
 *
 * @param {object} component The component options.
 * @param {object} props     The props to render with.
 * @return {Promise<string>} The rendered HTML.
 */
export async function renderComponent(component, props = {}) {
	const { createSSRApp, h } = await import('vue')
	const { renderToString } = await import('vue/server-renderer')
	return renderToString(createSSRApp({ render: () => h(component, props) }))
}
