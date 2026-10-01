// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Mounts a Vue single-file component in plain node, with no DOM: a tiny custom
// renderer keeps the element tree as plain objects, so a test can read the
// text, find an element by `data-testid`, fire an event on it and await what
// the component does. Lifecycle hooks run (unlike in render-sfc.mjs, which
// renders to a string and never mounts), so a form's submit or a dialog's
// fetch is exercised for real.
//
// Relative `.vue` imports, static and dynamic, are compiled recursively. A
// side-effect CSS import is dropped: node cannot load it and no test reads it.

import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
// Inside the repository so the compiled modules resolve `vue` from node_modules.
const OUT = join(ROOT, 'node_modules', '.cache', 'mount-sfc')

let counter = 0

// The element that last took focus, across every mounted tree.
let focused = null

/**
 * Compile one SFC (and the `.vue` files it imports) into an ES module.
 *
 * @param {string} file Absolute path of the `.vue` file.
 * @param {string} dir The output directory for this load.
 * @param {Map<string, string>} done Already compiled files.
 * @return {string} The compiled module's path.
 */
function compile(file, dir, done) {
	if (done.has(file)) {
		return done.get(file)
	}
	const out = join(dir, `${basename(file, '.vue')}-${counter++}.mjs`)
	done.set(file, out)

	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	const id = `m${counter}`
	const script = compileScript(descriptor, { id, inlineTemplate: false })
	let code = script.content.replace('export default', 'const __sfc__ =')
	const template = compileTemplate({
		source: descriptor.template.content,
		filename: file,
		id,
		compilerOptions: { mode: 'module', comments: false },
	})
	code += `\n${template.code}\n__sfc__.render = render\nexport default __sfc__\n`

	code = code.replace(/^import\s+['"][^'"]+\.css['"];?\s*$/gm, '')
	code = code.replace(/import\(\s*['"](\.[^'"]+\.vue)['"]\s*\)/g, (match, spec) =>
		`import('${pathToFileURL(compile(resolve(dirname(file), spec), dir, done)).href}')`)
	code = code.replace(/from\s+['"]([^'"]+)['"]/g, (match, spec) => {
		if (spec.endsWith('.vue') && spec.startsWith('.')) {
			return `from '${pathToFileURL(compile(resolve(dirname(file), spec), dir, done)).href}'`
		}
		if (spec.startsWith('.')) {
			return `from '${pathToFileURL(resolve(dirname(file), spec)).href}'`
		}
		return match
	})

	writeFileSync(out, code)
	return out
}

/**
 * The renderer's node operations, on plain objects.
 */
const nodeOps = {
	createElement(tag) {
		const listeners = {}
		return {
			tag,
			props: {},
			children: [],
			parent: null,
			listeners,
			// The native v-model directives listen here.
			addEventListener(name, fn) {
				listeners[name] = [...(listeners[name] || []), fn]
			},
			removeEventListener(name, fn) {
				listeners[name] = (listeners[name] || []).filter((f) => f !== fn)
			},
			focus() {
				focused = this
			},
		}
	},
	createText: (text) => ({ text, parent: null }),
	createComment: (text) => ({ comment: text, parent: null }),
	setText: (node, text) => { node.text = text },
	setElementText: (node, text) => { node.children = [{ text, parent: node }] },
	insert(child, parent, anchor) {
		if (child.parent) {
			nodeOps.remove(child)
		}
		const at = anchor ? parent.children.indexOf(anchor) : -1
		if (at < 0) {
			parent.children.push(child)
		} else {
			parent.children.splice(at, 0, child)
		}
		child.parent = parent
	},
	remove(child) {
		if (child.parent) {
			child.parent.children = child.parent.children.filter((c) => c !== child)
			child.parent = null
		}
	},
	parentNode: (node) => node.parent,
	nextSibling(node) {
		if (!node.parent) {
			return null
		}
		const siblings = node.parent.children
		return siblings[siblings.indexOf(node) + 1] || null
	},
	querySelector: () => null,
	setScopeId() {},
	patchProp(el, key, prev, next) {
		if (next === null || next === undefined) {
			delete el.props[key]
		} else {
			el.props[key] = next
		}
		if (key === 'value') {
			el.value = next
		}
		if (key === 'checked') {
			el.checked = next
		}
	},
}

/**
 * Mount a component.
 *
 * @param {string} file Path of the `.vue` file, relative to the repository.
 * @param {object} props The props.
 * @return {Promise<object>} `{vm, root, text(), find(testid), findAll(pred), fire(el, name, target), flush(), focused(), emitted}`.
 */
export async function mountSfc(file, props = {}) {
	const dir = join(OUT, `${process.pid}-${counter++}`)
	mkdirSync(dir, { recursive: true })
	const compiled = compile(join(ROOT, file), dir, new Map())
	const component = (await import(pathToFileURL(compiled).href)).default
	const { createRenderer, h } = await import('vue')

	const emitted = {}
	const listeners = {}
	for (const name of component.emits || []) {
		const key = `on${name.charAt(0).toUpperCase()}${name.slice(1)}`
		listeners[key] = (...args) => {
			emitted[name] = [...(emitted[name] || []), args]
		}
	}

	const root = { tag: 'root', props: {}, children: [], parent: null }
	const { createApp } = createRenderer(nodeOps)
	const app = createApp({ render: () => h(component, { ...listeners, ...props, ref: 'subject' }) })
	app.config.warnHandler = () => {}
	const host = app.mount(root)

	const walk = (node, out = []) => {
		out.push(node)
		for (const child of node.children || []) {
			walk(child, out)
		}
		return out
	}
	const textOf = (node) => walk(node).filter((n) => typeof n.text === 'string').map((n) => n.text).join(' ').replace(/\s+/g, ' ').trim()
	const flush = async () => {
		for (let i = 0; i < 10; i++) {
			await new Promise((r) => setTimeout(r, 0))
		}
	}

	return {
		vm: host.$refs.subject,
		focused: () => focused,
		root,
		emitted,
		flush,
		text: () => textOf(root),
		textOf,
		findAll: (predicate) => walk(root).filter((n) => n.tag && predicate(n)),
		find: (testid) => walk(root).find((n) => n.props && n.props['data-testid'] === testid) || null,
		/**
		 * Call an element's listener with a fake event.
		 *
		 * @param {object} el The element.
		 * @param {string} name The event, e.g. `click`, `input`, `submit`.
		 * @param {object} [target] What `event.target` holds (`value`, `files`, `checked`).
		 * @return {Promise<void>}
		 */
		async fire(el, name, target = {}) {
			Object.assign(el, target)
			const handler = el.props[`on${name.charAt(0).toUpperCase()}${name.slice(1)}`]
			const event = { target: el, preventDefault() {}, stopPropagation() {} }
			el.select = () => {}
			for (const fn of [...[].concat(handler || []), ...((el.listeners || {})[name] || [])]) {
				await fn(event)
			}
			await flush()
		},
	}
}
