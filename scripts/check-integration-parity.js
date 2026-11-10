#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App-side integration parity gate (hydra gate-24, `integration-parity`).
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * A leaf has TWO faces (ADR-019 AD-11/AD-13, ADR-066 decisions 4 and 7):
 *
 *   a SERVER face — either a `LeafDescriptor` contributed through
 *     `RegisterLeafProvidersEvent`, or an `IntegrationProvider` registered on
 *     OpenRegister's `IntegrationRegistry`. This face is what the
 *     `openregister.integrations.leaves` capability advertises.
 *   a JS face — a `registerIntegration({ id, … })` call that mounts the
 *     render pair on `window.OCA.OpenRegister.integrations`.
 *
 * The two are correlated ONLY by a shared `id`. Nothing at runtime notices when
 * one half is missing or when the two halves disagree: a descriptor with no JS
 * registration advertises a tab that renders nothing (a PHANTOM render
 * surface), and a JS registration with no server face mounts a widget that no
 * capability consumer can discover (an ORPHAN registration). A renderMode
 * disagreement is worse still — the host hands a `mount` leaf an SFC slot, or
 * asks a `component` leaf for a `mount()` it does not have, and the surface is
 * blank with no error.
 *
 * The canonical Node check that ships in `@conduction/nextcloud-vue`
 * (`scripts/check-integration-parity.js`) validates THAT library's own built-in
 * descriptors, and its ADR-066 cross-reference over `process.cwd()` is
 * WARN-only and self-disables in a repo with no `new LeafDescriptor(` at all.
 * Neither property is usable as an app-side gate: the library's `scripts/` dir
 * is not published to npm, so in CI the resolution fails and the historic
 * wrapper exits 0 having checked nothing. This check is therefore SELF-CONTAINED
 * — it reads only this repo's own sources, needs no node_modules, and FAILS.
 *
 * RULES (all hard; any violation exits 1)
 * ---------------------------------------
 *   R1  render pair       a JS registration declaring its own id must ship a
 *                         COMPLETE render pair for its renderMode:
 *                         `mount` + `unmount` for renderMode 'mount',
 *                         `tab` + `widget` for 'component' (the default).
 *   R2  id correlation    every server-side render-surface leaf id has a JS
 *                         registration, and every JS registration id has a
 *                         server-side face (descriptor OR provider).
 *   R3  renderMode        for a shared id, both faces declare the same
 *                         renderMode.
 *   R4  metadata          for a shared id, every field BOTH faces declare
 *                         statically (label, icon, group, requiredApp,
 *                         referenceType, surfaces) agrees.
 *   R5  spread source     a registration that inherits its identity by
 *                         spreading a descriptor (`...fooIntegration`) must
 *                         import that symbol from `@conduction/nextcloud-vue`
 *                         — the owning package. A spread of a local or
 *                         undefined binding registers a leaf with no id.
 *   R6  offlineConfig     an `offlineConfig` naming schemas/properties must
 *                         name ones this repo actually declares in its
 *                         OpenRegister register/schema JSON under
 *                         `lib/Settings/**`.
 *
 * A rule with no subject matter in this repo reports "0 checked" by name, so
 * the difference between "verified" and "there was nothing to verify" is
 * visible in the output rather than inferred from silence.
 *
 * Exit codes:
 *   0 — every rule that had subject matter passed
 *   1 — at least one parity violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * Constants owned by OpenRegister's `LeafDescriptor`, which is not in this
 * repo's tree. Resolving them by name is the only way a static reader can
 * follow `renderMode: LeafDescriptor::RENDER_MODE_MOUNT`.
 *
 * @type {Object<string, string>}
 */
const FOREIGN_CONSTANTS = {
	'LeafDescriptor::RENDER_MODE_MOUNT': 'mount',
	'LeafDescriptor::RENDER_MODE_COMPONENT': 'component',
	'LeafDescriptor::KIND_RENDER_SURFACE': 'render-surface',
	'LeafDescriptor::KIND_DATA_PROVIDER': 'data-provider',
	'LeafDescriptor::KIND_AGENT_RUNNER': 'agent-runner',
}

/**
 * `offlineConfig` keys that name an OpenRegister schema slug, and those that
 * name a property on the planned-item schema. Mirrors
 * `DEFAULT_FIELD_INSPECTION_CONFIG` in `@conduction/nextcloud-vue`.
 *
 * @type {{schemas: string[], plannedFields: string[]}}
 */
const OFFLINE_CONFIG_CONTRACT = {
	schemas: ['plannedSchema', 'referenceSchema', 'resultSchema'],
	plannedFields: ['templateRefField', 'assigneeField', 'dateField', 'titleField'],
}

/**
 * Directory names `collectFiles` never descends into.
 *
 * @type {Set<string>}
 */
const SKIPPED_DIRECTORIES = new Set([
	'node_modules',
	'vendor',
	'.git',
	'dist',
	'build',
	'js',
	'coverage',
])

/**
 * How deep `collectFiles` walks below its root.
 *
 * @type {number}
 */
const MAX_WALK_DEPTH = 10

/**
 * Recursively collect files under `root` matching `test(filename)`.
 *
 * @param {string} root Directory to walk.
 * @param {(name: string) => boolean} test Filename predicate.
 *
 * @return {string[]} Absolute paths of matching files.
 */
function collectFiles(root, test) {
	const walk = { test, out: [] }
	if (fs.existsSync(root) === true) {
		walkDirectory(walk, { dir: root, depth: 0 })
	}
	return walk.out
}

/**
 * Add the matching files of one directory, and of the directories below it.
 *
 * @param {{test: (name: string) => boolean, out: string[]}} walk The predicate and the files found so far.
 * @param {{dir: string, depth: number}} at The directory and its depth below the root.
 *
 * @return {void}
 */
function walkDirectory(walk, at) {
	if (at.depth > MAX_WALK_DEPTH) {
		return
	}
	for (const ent of directoryEntries(at.dir)) {
		if (ent.isDirectory() === true) {
			if (SKIPPED_DIRECTORIES.has(ent.name) === false) {
				walkDirectory(walk, { dir: path.join(at.dir, ent.name), depth: at.depth + 1 })
			}
		} else if (ent.isFile() === true && walk.test(ent.name) === true) {
			walk.out.push(path.join(at.dir, ent.name))
		}
	}
}

/**
 * The entries of a directory, or none when it cannot be read.
 *
 * @param {string} dir The directory.
 *
 * @return {fs.Dirent[]} Its entries.
 */
function directoryEntries(dir) {
	try {
		return fs.readdirSync(dir, { withFileTypes: true })
	} catch {
		return []
	}
}

/**
 * Read every file that can be read; unreadable files are left out.
 *
 * @param {string[]} files Absolute paths.
 *
 * @return {Map<string, string>} Path to source text.
 */
function readSources(files) {
	const sources = new Map()
	for (const file of files) {
		try {
			sources.set(file, fs.readFileSync(file, 'utf8'))
		} catch {
			continue
		}
	}
	return sources
}

/**
 * Characters that open a string literal.
 *
 * @type {Set<string>}
 */
const QUOTES = new Set(["'", '"', '`'])

/**
 * Comment openers, what ends each, and how far past the closer's first
 * character the scan resumes. `#` only counts where the caller says so.
 *
 * @type {Array<{opener: string, closer: string, past: number, hash: boolean}>}
 */
const COMMENTS = [
	{ opener: '//', closer: '\n', past: 0, hash: false },
	{ opener: '/*', closer: '*/', past: 1, hash: false },
	{ opener: '#', closer: '\n', past: 0, hash: true },
]

/**
 * Walk `src` from `options.from`, yielding each code character on its own and
 * each string literal whole, and skipping comments.
 *
 * @param {string} src Source text.
 * @param {{from: number, hashComments: boolean}} options Where to start, and whether `#` opens a comment.
 *
 * @yields {{i: number, text: string}} The token and the index it starts at.
 */
function* codeTokens(src, options) {
	const scan = { src, hashComments: options.hashComments, i: options.from }
	for (; scan.i < src.length; scan.i++) {
		const start = scan.i
		if (QUOTES.has(src[start]) === true) {
			scan.i = quoteEnd(scan)
			yield { i: start, text: src.slice(start, scan.i + 1) }
			continue
		}
		if (skipComment(scan) === true) {
			continue
		}
		yield { i: start, text: src[start] }
	}
}

/**
 * Index of the quote that closes the literal opening at `scan.i`, or the last
 * index when it never closes. A quote preceded by a backslash does not close.
 *
 * @param {{src: string, i: number}} scan The source and the opening index.
 *
 * @return {number} The closing index.
 */
function quoteEnd(scan) {
	const { src, i } = scan
	for (let j = i + 1; j < src.length; j++) {
		if (src[j] === src[i] && src[j - 1] !== '\\') {
			return j
		}
	}
	return src.length - 1
}

/**
 * When a comment opens at `scan.i`, move `scan.i` to its end.
 *
 * @param {{src: string, hashComments: boolean, i: number}} scan The scan position.
 *
 * @return {boolean} Whether a comment was skipped.
 */
function skipComment(scan) {
	const comment = COMMENTS.find(
		(c) =>
			scan.src.startsWith(c.opener, scan.i) === true
			&& (c.hash === false || scan.hashComments === true),
	)
	if (comment === undefined) {
		return false
	}
	const end = scan.src.indexOf(comment.closer, scan.i)
	scan.i = end === -1 ? scan.src.length : end + comment.past
	return true
}

/**
 * Closing delimiter per opening one.
 *
 * @type {Object<string, string>}
 */
const CLOSERS = { '(': ')', '{': '}', '[': ']' }

/**
 * Extract the balanced text that follows `open` at `src[from]`, honouring
 * string literals and comments so a brace inside a string never unbalances it.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {string} The text between the delimiters (exclusive), '' when unbalanced.
 */
function balanced(src, from) {
	const open = src[from]
	const close = CLOSERS[open]
	let depth = 0
	for (const token of codeTokens(src, { from, hashComments: open === '(' })) {
		if (token.text === open) {
			depth++
		} else if (token.text === close) {
			depth--
			if (depth === 0) {
				return src.slice(from + 1, token.i)
			}
		}
	}
	return ''
}

/**
 * Nesting change per delimiter, for `splitTopLevel`.
 *
 * @type {Object<string, number>}
 */
const DEPTH_STEPS = { '(': 1, '[': 1, '{': 1, ')': -1, ']': -1, '}': -1 }

/**
 * How a token changes the nesting depth.
 *
 * @param {string} text The token.
 *
 * @return {number} +1, -1 or 0.
 */
function depthStep(text) {
	return Object.hasOwn(DEPTH_STEPS, text) === true ? DEPTH_STEPS[text] : 0
}

/**
 * Split an argument/member list on top-level commas.
 *
 * @param {string} body The text between the delimiters.
 *
 * @return {string[]} Trimmed top-level members.
 */
function splitTopLevel(body) {
	const parts = []
	let depth = 0
	let current = ''
	for (const token of codeTokens(body, { from: 0, hashComments: true })) {
		depth += depthStep(token.text)
		if (token.text === ',' && depth === 0) {
			parts.push(current.trim())
			current = ''
			continue
		}
		current += token.text
	}
	if (current.trim() !== '') {
		parts.push(current.trim())
	}
	return parts
}

/**
 * The first match of any pattern, tried in order.
 *
 * @param {string} text The text to match.
 * @param {RegExp[]} patterns The patterns.
 *
 * @return {RegExpExecArray|null} The match.
 */
function firstMatch(text, patterns) {
	for (const pattern of patterns) {
		const m = pattern.exec(text)
		if (m !== null) {
			return m
		}
	}
	return null
}

/**
 * The content of a string literal with its escaped quotes unescaped, or
 * undefined when `expr` is not one of the given literal shapes.
 *
 * @param {string} expr The trimmed expression.
 * @param {RegExp[]} patterns The literal shapes.
 *
 * @return {string|undefined} The string.
 */
function unquoted(expr, patterns) {
	const m = firstMatch(expr, patterns)
	return m === null ? undefined : m[1].replace(/\\'/g, "'").replace(/\\"/g, '"')
}

/**
 * The literal inside a translation call, or undefined when `expr` is not one.
 *
 * @param {string} expr The trimmed expression.
 * @param {RegExp[]} patterns The translation call shapes.
 *
 * @return {string|undefined} The translated literal.
 */
function translated(expr, patterns) {
	const m = firstMatch(expr, patterns)
	return m === null ? undefined : m[1]
}

/**
 * Resolve every member of an array literal, or null when one is not a string.
 *
 * @param {string} expr The array literal.
 * @param {(member: string) => (string|string[]|null)} resolve Resolves one member.
 *
 * @return {string[]|null} The members.
 */
function resolvedMembers(expr, resolve) {
	const out = []
	for (const member of splitTopLevel(balanced(expr, 0))) {
		const v = resolve(member)
		if (typeof v !== 'string') {
			return null
		}
		out.push(v)
	}
	return out
}

/**
 * Try each reader in order; the first that recognises the expression decides.
 *
 * @param {Array<(expr: string, consts: object) => (string|string[]|null|undefined)>} readers Each returns undefined when it does not apply.
 * @param {{expr: string, consts: object}} subject The trimmed expression and its constants.
 *
 * @return {string|string[]|null} The resolved value, null when none applies.
 */
function firstReading(readers, subject) {
	for (const read of readers) {
		const value = read(subject.expr, subject.consts)
		if (value !== undefined) {
			return value
		}
	}
	return null
}

/**
 * PHP string literal shapes.
 *
 * @type {RegExp[]}
 */
const PHP_LITERALS = [/^'((?:[^'\\]|\\.)*)'$/, /^"((?:[^"\\]|\\.)*)"$/]

/**
 * `$this->l10n->t('X')` / `$this->l->t('X', […])` — the translated literal.
 *
 * @type {RegExp[]}
 */
const PHP_TRANSLATIONS = [
	/^\$this->[A-Za-z0-9_]+->t\(\s*'((?:[^'\\]|\\.)*)'/,
	/^\$this->[A-Za-z0-9_]+->t\(\s*"((?:[^"\\]|\\.)*)"/,
]

/**
 * `self::NAME` / `static::NAME` from the same file's constants.
 *
 * @param {string} expr The trimmed expression.
 * @param {{local: object, global: object}} consts The constant tables.
 *
 * @return {string|string[]|null|undefined} The value, null when unknown, undefined when not this shape.
 */
function phpOwnConstant(expr, consts) {
	const m = /^(?:self|static)::([A-Z0-9_]+)$/.exec(expr)
	if (m === null) {
		return undefined
	}
	return Object.hasOwn(consts.local, m[1]) ? consts.local[m[1]] : null
}

/**
 * `Class::NAME` from OpenRegister's known constants or this repo's classes.
 *
 * @param {string} expr The trimmed expression.
 * @param {{local: object, global: object}} consts The constant tables.
 *
 * @return {string|string[]|null|undefined} The value, null when unknown, undefined when not this shape.
 */
function phpClassConstant(expr, consts) {
	const m = /^([A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)$/.exec(expr)
	if (m === null) {
		return undefined
	}
	const key = `${m[1]}::${m[2]}`
	if (Object.hasOwn(FOREIGN_CONSTANTS, key) === true) {
		return FOREIGN_CONSTANTS[key]
	}
	return Object.hasOwn(consts.global, key) ? consts.global[key] : null
}

/**
 * A PHP array literal whose members all resolve to strings.
 *
 * @param {string} expr The trimmed expression.
 * @param {{local: object, global: object}} consts The constant tables.
 *
 * @return {string[]|null|undefined} The members, null when one is unknown, undefined when not an array.
 */
function phpArray(expr, consts) {
	if (expr.startsWith('[') === false) {
		return undefined
	}
	return resolvedMembers(expr, (member) => resolvePhp(member, consts))
}

/**
 * The PHP expression shapes `resolvePhp` understands, in the order it tries them.
 *
 * @type {Array<(expr: string, consts: object) => (string|string[]|null|undefined)>}
 */
const PHP_READERS = [
	(expr) => unquoted(expr, PHP_LITERALS),
	(expr) => translated(expr, PHP_TRANSLATIONS),
	phpOwnConstant,
	phpClassConstant,
	phpArray,
]

/**
 * Resolve a PHP expression to a string or array of strings, or null when it is
 * not statically knowable (which is never a failure — unknown values are simply
 * not compared).
 *
 * @param {string} expr The expression text.
 * @param {{local: Object<string, (string|string[])>, global: Object<string, (string|string[])>}} consts
 *   The same-file const table and the `Class::CONST` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolvePhp(expr, consts) {
	return firstReading(PHP_READERS, { expr: expr.trim(), consts })
}

/**
 * The text of the array literal that opens at `start`, brackets included.
 *
 * @param {string} src Source text.
 * @param {number} start Index of the `[`.
 *
 * @return {string} The literal.
 */
function arrayLiteralAt(src, start) {
	return src.slice(start, start + balanced(src, start).length + 2)
}

/**
 * Build a same-file `const NAME = …` table: each declaration's expression is
 * resolved against the constants declared before it, and unknown ones are
 * left out.
 *
 * @param {string} src Source text.
 * @param {{declaration: RegExp, scalarAt: (src: string, start: number) => string, resolve: (expr: string, table: object) => (string|string[]|null)}} dialect
 *   The declaration pattern, how a non-array expression ends, and the resolver.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function constTable(src, dialect) {
	const table = {}
	const re = new RegExp(dialect.declaration.source, 'g')
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		const expr =
			src[start] === '[' ? arrayLiteralAt(src, start) : dialect.scalarAt(src, start)
		const v = dialect.resolve(expr, table)
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * How PHP constants are declared and read.
 *
 * @type {{declaration: RegExp, scalarAt: (src: string, start: number) => string, resolve: (expr: string, table: object) => (string|string[]|null)}}
 */
const PHP_CONSTS = {
	declaration: /\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*/,
	scalarAt: (src, start) => {
		const semi = src.indexOf(';', start)
		return semi === -1 ? '' : src.slice(start, semi)
	},
	resolve: (expr, table) => resolvePhp(expr, { local: table, global: {} }),
}

/**
 * Build the same-file `const NAME = …` table for a PHP source.
 *
 * @param {string} src PHP source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function phpLocalConsts(src) {
	return constTable(src, PHP_CONSTS)
}

/**
 * The `Class::CONST` table over every PHP source that declares a class, so
 * `Application::APP_ID` resolves.
 *
 * @param {Map<string, string>} sources Path to PHP source.
 *
 * @return {Object<string, (string|string[])>} `Class::NAME` to resolved value.
 */
function classConstants(sources) {
	const globalConsts = {}
	for (const src of sources.values()) {
		const cls =
			/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/.exec(src)
		if (cls === null) {
			continue
		}
		for (const [name, value] of Object.entries(phpLocalConsts(src))) {
			globalConsts[`${cls[1]}::${name}`] = value
		}
	}
	return globalConsts
}

/**
 * A value when it is a string, else null.
 *
 * @param {unknown} value The value.
 *
 * @return {string|null} The string.
 */
function stringOrNull(value) {
	return typeof value === 'string' ? value : null
}

/**
 * Collect the server-side leaf faces declared in this repo's PHP.
 *
 * Two shapes count as a server face, because both are how a leaf reaches the
 * `openregister.integrations.leaves` capability:
 *   `new LeafDescriptor(…)` — the ADR-066 collect-event contribution;
 *   an `IntegrationProvider` (extends `AbstractIntegrationProvider` or
 *     implements `IntegrationProviderInterface`) whose `getId()` returns a
 *     literal — the `IntegrationRegistry::addProvider()` path.
 *
 * @return {Array<object>} The discovered server faces.
 */
function collectServerFaces() {
	const sources = readSources(
		collectFiles(path.join(REPO_ROOT, 'lib'), (n) => n.endsWith('.php')),
	)
	const globalConsts = classConstants(sources)
	const faces = []
	for (const [file, src] of sources) {
		const php = {
			rel: path.relative(REPO_ROOT, file),
			src,
			consts: { local: phpLocalConsts(src), global: globalConsts },
		}
		faces.push(...descriptorFaces(php))
		const provider = providerFace(php)
		if (provider !== null) {
			faces.push(provider)
		}
	}
	return faces
}

/**
 * Shape 1: every `new LeafDescriptor(` in one file, read from its named arguments.
 *
 * @param {{rel: string, src: string, consts: object}} php The file.
 *
 * @return {Array<object>} Its descriptor faces.
 */
function descriptorFaces(php) {
	const faces = []
	let idx = 0
	while ((idx = php.src.indexOf('new LeafDescriptor(', idx)) !== -1) {
		const open = php.src.indexOf('(', idx)
		faces.push(descriptorFace(php, open))
		idx = open + 1
	}
	return faces
}

/**
 * One `new LeafDescriptor(…)` call as a server face.
 *
 * @param {{rel: string, src: string, consts: object}} php The file.
 * @param {number} open Index of the call's `(`.
 *
 * @return {object} The face.
 */
function descriptorFace(php, open) {
	const face = { kind: 'LeafDescriptor', file: php.rel, fields: namedArguments(php, open) }
	face.id = stringOrNull(face.fields.id)
	const kinds = Array.isArray(face.fields.kinds) ? face.fields.kinds : []
	face.renderSurface = kinds.includes('render-surface')
	face.renderMode = stringOrNull(face.fields.renderMode)
	return face
}

/**
 * The statically-known named arguments of the call opening at `open`.
 *
 * @param {{rel: string, src: string, consts: object}} php The file.
 * @param {number} open Index of the call's `(`.
 *
 * @return {Object<string, (string|string[])>} Argument name to value.
 */
function namedArguments(php, open) {
	const fields = {}
	for (const arg of splitTopLevel(balanced(php.src, open))) {
		const m = /^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([\s\S]+)$/.exec(arg)
		if (m === null) {
			continue
		}
		const value = resolvePhp(m[2], php.consts)
		if (value !== null) {
			fields[m[1]] = value
		}
	}
	return fields
}

/**
 * Provider getters and the leaf field each one declares.
 *
 * @type {Object<string, string>}
 */
const PROVIDER_GETTERS = {
	getId: 'id',
	getLabel: 'label',
	getIcon: 'icon',
	getGroup: 'group',
	getRequiredApp: 'requiredApp',
	getReferenceType: 'referenceType',
}

/**
 * Whether a PHP source declares an integration provider.
 *
 * @param {string} src PHP source text.
 *
 * @return {boolean} True for a provider class.
 */
function isIntegrationProvider(src) {
	return (
		/extends\s+AbstractIntegrationProvider\b/.test(src)
		|| /implements\s+[^{]*\bIntegrationProviderInterface\b/.test(src)
	)
}

/**
 * Shape 2: the file's IntegrationProvider, when it has one with a readable id.
 *
 * @param {{rel: string, src: string, consts: object}} php The file.
 *
 * @return {object|null} The face.
 */
function providerFace(php) {
	if (isIntegrationProvider(php.src) === false) {
		return null
	}
	const face = { kind: 'IntegrationProvider', file: php.rel, fields: {} }
	for (const [method, field] of Object.entries(PROVIDER_GETTERS)) {
		const value = getterReturn(php, method)
		if (value !== null) {
			face.fields[field] = value
		}
	}
	if (typeof face.fields.id !== 'string') {
		return null
	}
	face.id = face.fields.id
	// A provider is a data face, not a render surface: it is discoverable
	// through the capability and therefore MUST have a JS registration,
	// but it does not itself declare a render pair.
	face.renderSurface = false
	face.renderMode = stringOrNull(face.fields.renderMode)
	return face
}

/**
 * The statically-known value a getter's first `return` gives.
 *
 * @param {{rel: string, src: string, consts: object}} php The file.
 * @param {string} method The getter name.
 *
 * @return {string|string[]|null} The value.
 */
function getterReturn(php, method) {
	const sig = new RegExp(`function\\s+${method}\\s*\\([^)]*\\)[^{;]*\\{`).exec(php.src)
	if (sig === null) {
		return null
	}
	const body = balanced(php.src, sig.index + sig[0].length - 1)
	const ret = /\breturn\s+([^;]+);/.exec(body)
	if (ret === null) {
		return null
	}
	return resolvePhp(ret[1], php.consts)
}

/**
 * JS string literal shapes, a template literal without substitutions included.
 *
 * @type {RegExp[]}
 */
const JS_LITERALS = [/^'((?:[^'\\]|\\.)*)'$/, /^"((?:[^"\\]|\\.)*)"$/, /^`([^`$]*)`$/]

/**
 * `t('appid', 'Label')` / `t('Label')` — the translated literal.
 *
 * @type {RegExp[]}
 */
const JS_TRANSLATIONS = [
	/^t\(\s*'[^']*'\s*,\s*'((?:[^'\\]|\\.)*)'/,
	/^t\(\s*'((?:[^'\\]|\\.)*)'\s*\)/,
]

/**
 * A JS array literal whose members all resolve to strings.
 *
 * @param {string} expr The trimmed expression.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string[]|null|undefined} The members, null when one is unknown, undefined when not an array.
 */
function jsArray(expr, locals) {
	if (expr.startsWith('[') === false) {
		return undefined
	}
	return resolvedMembers(expr, (member) => resolveJs(member, locals))
}

/**
 * A bare name of a same-file constant.
 *
 * @param {string} expr The trimmed expression.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string|string[]|undefined} The value, undefined when not a known name.
 */
function jsLocalName(expr, locals) {
	if (
		/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(expr) === true
		&& Object.hasOwn(locals, expr) === true
	) {
		return locals[expr]
	}
	return undefined
}

/**
 * The JS expression shapes `resolveJs` understands, in the order it tries them.
 *
 * @type {Array<(expr: string, consts: object) => (string|string[]|null|undefined)>}
 */
const JS_READERS = [
	(expr) => unquoted(expr, JS_LITERALS),
	(expr) => translated(expr, JS_TRANSLATIONS),
	jsArray,
	jsLocalName,
]

/**
 * Resolve a JS expression to a string or array of strings, or null when it is
 * not statically knowable.
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolveJs(expr, locals) {
	return firstReading(JS_READERS, { expr: expr.trim(), consts: locals })
}

/**
 * Build the same-file `const NAME = { … }` table, keyed to the OBJECT LITERAL
 * TEXT rather than a resolved scalar.
 *
 * `jsLocalConsts` below resolves constants to VALUES, and for a `{` it only
 * reads to end-of-line, so a multi-line descriptor object never lands in its
 * table. That is fine for `id: SOME_CONST`, but it cannot serve a registration
 * whose whole descriptor is passed by name:
 *
 *   export const decisionsLeafDescriptor = { id: …, renderMode: 'mount', … }
 *   …
 *   OCA.OpenRegister.integrations.register(decisionsLeafDescriptor)
 *
 * The member parser in `collectJsRegistrations` needs the literal's TEXT, so
 * this table keeps the balanced `{ … }` body verbatim for that one purpose.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, string>} Constant name to object-literal inner text.
 */
function jsLocalObjectLiterals(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\{/g
	let m
	while ((m = re.exec(src)) !== null) {
		const brace = m.index + m[0].length - 1
		table[m[1]] = balanced(src, brace)
	}
	return table
}

/**
 * How JS constants are declared and read: a non-array expression runs to the
 * end of its line, without a trailing comma.
 *
 * @type {{declaration: RegExp, scalarAt: (src: string, start: number) => string, resolve: (expr: string, table: object) => (string|string[]|null)}}
 */
const JS_CONSTS = {
	declaration: /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/,
	scalarAt: (src, start) => {
		const nl = src.indexOf('\n', start)
		return nl === -1 ? src.slice(start) : src.slice(start, nl).replace(/,\s*$/, '')
	},
	resolve: resolveJs,
}

/**
 * Build the same-file `const NAME = …` table for a JS/Vue source.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function jsLocalConsts(src) {
	return constTable(src, JS_CONSTS)
}

/**
 * Whether a file name is a JS, TS or Vue source.
 *
 * @param {string} name The file name.
 *
 * @return {boolean} True for a script source.
 */
function isScriptFile(name) {
	return name.endsWith('.js') || name.endsWith('.ts') || name.endsWith('.vue')
}

/**
 * Whether a source mentions either registration API.
 *
 * BOTH SUPPORTED REGISTRATION APIs, NOT ONE.
 *
 * `registerIntegration(descriptor)` is the convenience wrapper exported
 * from @conduction/nextcloud-vue. The registry object it wraps is
 * equally canonical and is called directly as
 * `OCA.OpenRegister.integrations.register(descriptor)` — including by
 * nextcloud-vue's OWN built-in leaves. Matching only the wrapper made
 * this checker report ZERO registrations for a repo that plainly has
 * one, which the wrapper script then correctly refused to call a pass.
 * gate-24's own selector probe was fixed for exactly this and reads
 * both forms; this checker was a generation behind it.
 *
 * @param {string} src JS source text.
 *
 * @return {boolean} True when the file may register a leaf.
 */
function mentionsRegistration(src) {
	return src.includes('registerIntegration') || src.includes('integrations.register')
}

/**
 * Collect the JS `registerIntegration({ … })` call sites in `src/**`.
 *
 * @return {Array<object>} The discovered registrations.
 */
function collectJsRegistrations() {
	const regs = []
	const files = collectFiles(path.join(REPO_ROOT, 'src'), isScriptFile)
	for (const [file, src] of readSources(files)) {
		if (mentionsRegistration(src) === false) {
			continue
		}
		regs.push(
			...registrationsIn({
				rel: path.relative(REPO_ROOT, file),
				src,
				locals: jsLocalConsts(src),
				objects: jsLocalObjectLiterals(src),
			}),
		)
	}
	return regs
}

/**
 * A registration call. `\s*\(` anchors both alternatives to a CALL, so neither
 * `registerIntegrationIcons(` nor `installIntegrationRegistry(` matches.
 *
 * @type {RegExp}
 */
const REGISTRATION_CALL = /(?:\bregisterIntegration|\bintegrations\s*\.\s*register)\s*\(/

/**
 * Every registration call in one file.
 *
 * @param {{rel: string, src: string, locals: object, objects: object}} js The file.
 *
 * @return {Array<object>} Its registrations.
 */
function registrationsIn(js) {
	const regs = []
	const re = new RegExp(REGISTRATION_CALL.source, 'g')
	let m
	while ((m = re.exec(js.src)) !== null) {
		const argText = registrationArgument(js, m.index)
		if (argText !== null) {
			regs.push(registration(js, argText))
		}
	}
	return regs
}

/**
 * The object literal a registration call at `index` passes, or null when the
 * match is the library's own declaration or an argument that cannot be read.
 *
 * @param {{rel: string, src: string, locals: object, objects: object}} js The file.
 * @param {number} index Where the call's name starts.
 *
 * @return {string|null} The literal, braces included.
 */
function registrationArgument(js, index) {
	const before = js.src.slice(Math.max(0, index - 20), index)
	if (/function\s+$/.test(before) === true) {
		return null // the library's own `export function registerIntegration(`
	}
	const argText = balanced(js.src, js.src.indexOf('(', index)).trim()
	if (argText.startsWith('{') === true) {
		return argText
	}
	// A descriptor passed BY NAME is the same registration as an
	// inline literal; resolve it to the literal's text or skip.
	const ident = /^([A-Za-z_$][A-Za-z0-9_$]*)$/.exec(argText)
	if (ident === null || js.objects[ident[1]] === undefined) {
		return null
	}
	return '{' + js.objects[ident[1]] + '}'
}

/**
 * One registration read from its object literal.
 *
 * @param {{rel: string, src: string, locals: object, objects: object}} js The file.
 * @param {string} argText The object literal, braces included.
 *
 * @return {object} The registration.
 */
function registration(js, argText) {
	const reg = { file: js.rel, fields: {}, keys: [], spreads: [] }
	for (const member of splitTopLevel(balanced(argText, 0))) {
		addMember({ reg, locals: js.locals }, member)
	}
	reg.id = stringOrNull(reg.fields.id)
	reg.renderMode = stringOrNull(reg.fields.renderMode)
	// Import source per spread symbol, for R5.
	reg.spreadSources = {}
	for (const symbol of reg.spreads) {
		reg.spreadSources[symbol] = importSourceOf(js.src, symbol)
	}
	return reg
}

/**
 * A `key: value` member, or a shorthand `key`, with a quoted or bare key.
 *
 * @type {RegExp}
 */
const KEYED_MEMBER =
	/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*(?::\s*([\s\S]+))?$/

/**
 * An `offlineConfig` entry: a quoted or bare key with a value.
 *
 * @type {RegExp}
 */
const CONFIG_ENTRY = /^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*:\s*([\s\S]+)$/

/**
 * The key of a matched member, whichever quoting it used.
 *
 * @param {RegExpExecArray} kv The match.
 *
 * @return {string} The key.
 */
function memberKey(kv) {
	return kv[1] || kv[2] || kv[3]
}

/**
 * Record one member of a registration literal: a spread, or a keyed member.
 *
 * @param {{reg: object, locals: object}} build The registration being read and the file's constants.
 * @param {string} member The member text.
 *
 * @return {void}
 */
function addMember(build, member) {
	const spread = /^\.\.\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/.exec(member)
	if (spread !== null) {
		build.reg.spreads.push(spread[1])
		return
	}
	const kv = KEYED_MEMBER.exec(member)
	if (kv !== null) {
		addKeyedMember(build, kv)
	}
}

/**
 * Record a keyed member: its key, its value when known, and an inline
 * `offlineConfig` object.
 *
 * @param {{reg: object, locals: object}} build The registration being read and the file's constants.
 * @param {RegExpExecArray} kv The member match.
 *
 * @return {void}
 */
function addKeyedMember(build, kv) {
	const key = memberKey(kv)
	build.reg.keys.push(key)
	// A shorthand (`mount,`) carries the key without a value.
	const value = kv[4] === undefined ? null : resolveJs(kv[4], build.locals)
	if (value !== null) {
		build.reg.fields[key] = value
	}
	if (key === 'offlineConfig') {
		const literal = inlineObject(kv[4])
		if (literal !== null) {
			build.reg.offlineConfig = offlineConfig(literal, build.locals)
		}
	}
}

/**
 * A member value when it is an inline object literal.
 *
 * @param {string|undefined} value The member value text.
 *
 * @return {string|null} The trimmed literal.
 */
function inlineObject(value) {
	if (value === undefined) {
		return null
	}
	const text = value.trim()
	return text.startsWith('{') === true ? text : null
}

/**
 * The string-valued entries of an inline `offlineConfig` literal.
 *
 * @param {string} literal The object literal, braces included.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {Object<string, string>} Key to value.
 */
function offlineConfig(literal, locals) {
	const config = {}
	for (const entry of splitTopLevel(balanced(literal, 0))) {
		const ckv = CONFIG_ENTRY.exec(entry)
		if (ckv === null) {
			continue
		}
		const cvalue = resolveJs(ckv[4], locals)
		if (typeof cvalue === 'string') {
			config[memberKey(ckv)] = cvalue
		}
	}
	return config
}

/**
 * Find the module a symbol is imported from.
 *
 * @param {string} src JS source text.
 * @param {string} symbol The imported binding name.
 *
 * @return {string|null} The module specifier, or null when not imported.
 */
function importSourceOf(src, symbol) {
	const re = /import\s+([\s\S]*?)\s+from\s+'([^']+)'/g
	let m
	while ((m = re.exec(src)) !== null) {
		const clause = m[1]
		if (namedBindings(clause).includes(symbol) === true || defaultBinding(clause) === symbol) {
			return m[2]
		}
	}
	return null
}

/**
 * The local names an import clause's `{ … }` part binds (`b as c` binds `c`).
 *
 * @param {string} clause The text between `import` and `from`.
 *
 * @return {string[]} The bound names.
 */
function namedBindings(clause) {
	const named =
		clause.indexOf('{') === -1 ? '' : balanced(clause, clause.indexOf('{'))
	return splitTopLevel(named).map((b) =>
		b
			.split(/\s+as\s+/)
			.pop()
			.trim(),
	)
}

/**
 * The default binding of an import clause, '' when it has none.
 *
 * @param {string} clause The text between `import` and `from`.
 *
 * @return {string} The bound name.
 */
function defaultBinding(clause) {
	return clause
		.replace(/\{[\s\S]*\}/, '')
		.replace(/,/g, '')
		.trim()
}

/**
 * Collect the schema slugs and their property names from this repo's
 * OpenRegister register/schema declarations under `lib/Settings/**`.
 *
 * @return {{schemas: Object<string, string[]>, files: number}} Slug to property
 *   names, and how many declaration files were read.
 */
function collectOrSchemas() {
	const schemas = {}
	let files = 0
	for (const file of collectFiles(path.join(REPO_ROOT, 'lib', 'Settings'), (n) =>
		n.endsWith('.json'),
	)) {
		const declared = declaredSchemas(readJson(file))
		if (declared === null) {
			continue
		}
		files++
		addSchemaProperties(schemas, declared)
	}
	return { schemas, files }
}

/**
 * A JSON file's content, or undefined when it cannot be read or parsed.
 *
 * @param {string} file Absolute path.
 *
 * @return {unknown} The parsed document.
 */
function readJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch {
		return undefined
	}
}

/**
 * The `components.schemas` object of a register/schema document, or null when
 * the document declares none.
 *
 * @param {unknown} doc The parsed document.
 *
 * @return {object|null} Schema key to schema.
 */
function declaredSchemas(doc) {
	const declared = doc && doc.components && doc.components.schemas
	return typeof declared === 'object' ? declared : null
}

/**
 * Add each declared schema's property names under its slug (its key when it
 * has no slug); slugs declared twice collect both property lists.
 *
 * @param {Object<string, string[]>} schemas Slug to property names so far.
 * @param {object} declared Schema key to schema.
 *
 * @return {void}
 */
function addSchemaProperties(schemas, declared) {
	for (const [key, schema] of Object.entries(declared)) {
		const slug = schema && typeof schema.slug === 'string' ? schema.slug : key
		schemas[slug] = (schemas[slug] || []).concat(propertyNames(schema))
	}
}

/**
 * The property names a schema declares.
 *
 * @param {unknown} schema The schema.
 *
 * @return {string[]} Its property names.
 */
function propertyNames(schema) {
	return schema && schema.properties && typeof schema.properties === 'object'
		? Object.keys(schema.properties)
		: []
}

/**
 * Compare two statically-resolved values for parity.
 *
 * @param {string|string[]} a Left value.
 * @param {string|string[]} b Right value.
 *
 * @return {boolean} True when equal (arrays compare as sets).
 */
function sameValue(a, b) {
	if (Array.isArray(a) === true && Array.isArray(b) === true) {
		const left = [...a].sort()
		const right = [...b].sort()
		return left.length === right.length && left.every((v, i) => v === right[i])
	}
	return a === b
}

/**
 * Leaf fields both faces may declare statically, compared by R4.
 *
 * @type {string[]}
 */
const COMPARED_FIELDS = [
	'label',
	'icon',
	'group',
	'requiredApp',
	'referenceType',
	'surfaces',
]

/**
 * Items keyed by their statically-read id; items without one are left out.
 *
 * @param {Array<{id: (string|null)}>} items The faces or registrations.
 *
 * @return {Map<string, object>} The items by id.
 */
function byId(items) {
	const map = new Map()
	for (const item of items) {
		if (item.id !== null) {
			map.set(item.id, item)
		}
	}
	return map
}

/**
 * Everything the rules read, collected once, and the failure list they fill.
 *
 * @return {object} The run.
 */
function parityRun() {
	const faces = collectServerFaces()
	const regs = collectJsRegistrations()
	const { schemas, files: schemaFiles } = collectOrSchemas()
	return {
		faces,
		regs,
		schemas,
		schemaFiles,
		jsById: byId(regs),
		faceById: byId(faces),
		failures: [],
	}
}

/**
 * R1: a registration with its own id ships the complete render pair for its renderMode.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkRenderPairs(run) {
	let count = 0
	for (const r of run.regs) {
		if (r.id === null) {
			continue // identity inherited from a spread — covered by R5
		}
		count++
		checkRenderPairOf(run, r)
	}
	return count
}

/**
 * R1 for one registration with its own id.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {void}
 */
function checkRenderPairOf(run, r) {
	const mode = r.renderMode === null ? 'component' : r.renderMode
	const required = mode === 'mount' ? ['mount', 'unmount'] : ['tab', 'widget']
	for (const key of required) {
		if (r.keys.includes(key) === false) {
			run.failures.push(
				`✗ [R1 render-pair] registerIntegration id "${r.id}" (${r.file}) declares `
					+ `renderMode "${mode}" but is missing the required \`${key}\` key — an `
					+ `incomplete render pair renders nothing on its surface (ADR-019 AD-11/AD-13, `
					+ `ADR-066 decision 7).`,
			)
		}
	}
}

/**
 * R2: server and JS faces correlate by id, both ways.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkIdCorrelation(run) {
	const count = checkServerFacesMount(run)
	return count + checkRegistrationsAdvertised(run)
}

/**
 * R2, server side: every face has a readable id, and every render-surface face
 * has a JS registration.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions run.
 */
function checkServerFacesMount(run) {
	let count = 0
	for (const f of run.faces) {
		count += checkServerFaceMounts(run, f)
	}
	return count
}

/**
 * R2 for one server face.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} f The server face.
 *
 * @return {number} 1 when its id was readable, else 0.
 */
function checkServerFaceMounts(run, f) {
	if (f.id === null) {
		run.failures.push(
			`✗ [R2 id-correlation] a ${f.kind} in ${f.file} declares no statically-readable `
				+ `\`id\` — a leaf whose id cannot be read cannot be correlated with its JS half.`,
		)
		return 0
	}
	// R2 as documented above: a RENDER-SURFACE leaf needs a JS half. A
	// data-provider-only descriptor (portaliq-change-proposals) is served by
	// OpenRegister's integrations route and never mounts, so it has none.
	if (f.kind === 'LeafDescriptor' && f.renderSurface === false) {
		return 1
	}
	if (run.jsById.has(f.id) === false) {
		run.failures.push(
			`✗ [R2 id-correlation] server leaf "${f.id}" (${f.kind}, ${f.file}) has NO matching `
				+ `registerIntegration({ id: '${f.id}' }) in src/** — phantom leaf: the `
				+ `openregister.integrations.leaves capability advertises a surface that never mounts.`,
		)
	}
	return 1
}

/**
 * R2, JS side: every registration with its own id has a server face.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions run.
 */
function checkRegistrationsAdvertised(run) {
	const { regs, faceById, failures } = run
	let count = 0
	for (const r of regs) {
		if (r.id === null) {
			continue
		}
		count++
		if (faceById.has(r.id) === false) {
			failures.push(
				`✗ [R2 id-correlation] registerIntegration id "${r.id}" (${r.file}) has NO matching `
					+ `server-side face in lib/** (neither a \`new LeafDescriptor(id: '${r.id}')\` nor an `
					+ `IntegrationProvider whose getId() returns '${r.id}') — orphan registration: it `
					+ `mounts on window.OCA.OpenRegister.integrations but is invisible to the `
					+ `openregister.integrations.leaves capability.`,
			)
		}
	}
	return count
}

/**
 * R3: a shared id declares the same renderMode on both faces.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkRenderModes(run) {
	const { jsById, faceById, failures } = run
	let count = 0
	for (const [id, f] of faceById) {
		const r = jsById.get(id)
		if (r === undefined || f.renderMode === null || r.renderMode === null) {
			continue
		}
		count++
		if (f.renderMode !== r.renderMode) {
			failures.push(
				`✗ [R3 renderMode] leaf "${id}" declares renderMode "${f.renderMode}" server-side `
					+ `(${f.file}) but "${r.renderMode}" in its JS registration (${r.file}) — a `
					+ `renderMode mismatch blanks the surface (ADR-066 decision 7).`,
			)
		}
	}
	return count
}

/**
 * R4: a shared id's statically-declared metadata agrees across faces.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkMetadata(run) {
	let count = 0
	for (const [id, f] of run.faceById) {
		const r = run.jsById.get(id)
		if (r !== undefined) {
			count += checkFieldsAgree(run, { id, f, r })
		}
	}
	return count
}

/**
 * R4 for one id both faces share.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {{id: string, f: object, r: object}} pair The id, its server face and its registration.
 *
 * @return {number} The assertions run.
 */
function checkFieldsAgree(run, pair) {
	const { id, f, r } = pair
	let count = 0
	for (const field of COMPARED_FIELDS) {
		const left = f.fields[field]
		const right = r.fields[field]
		if (left === undefined || right === undefined) {
			continue
		}
		count++
		if (sameValue(left, right) === false) {
			run.failures.push(
				`✗ [R4 metadata] leaf "${id}" field \`${field}\` mismatch across layers: `
					+ `server (${f.file}) says ${JSON.stringify(left)}, JS (${r.file}) says `
					+ `${JSON.stringify(right)} — the two halves describe different leaves.`,
			)
		}
	}
	return count
}

/**
 * R5: a spread registration spreads the owning package's descriptor.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkSpreadSources(run) {
	let count = 0
	for (const r of run.regs) {
		count += checkSpreadsOf(run, r)
	}
	return count
}

/**
 * R5 for one registration: each spread's source, and that it has an identity.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {number} The assertions run.
 */
function checkSpreadsOf(run, r) {
	for (const symbol of r.spreads) {
		const failure = spreadSourceFailure(r, symbol)
		if (failure !== null) {
			run.failures.push(failure)
		}
	}
	if (r.id === null && r.spreads.length === 0) {
		run.failures.push(
			`✗ [R5 spread-source] registerIntegration in ${r.file} declares neither an \`id\` `
				+ `nor a spread of a descriptor — it registers a leaf with no identity.`,
		)
	}
	return r.spreads.length
}

/**
 * Why a spread symbol does not come from the leaf-owning package, or null when it does.
 *
 * @param {object} r The registration.
 * @param {string} symbol The spread symbol.
 *
 * @return {string|null} The failure line.
 */
function spreadSourceFailure(r, symbol) {
	const source = r.spreadSources[symbol]
	if (source === null) {
		return (
			`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\`, `
			+ `which is not imported in that file — the spread of an undefined binding `
			+ `registers a leaf with no id.`
		)
	}
	if (source !== '@conduction/nextcloud-vue') {
		return (
			`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\` `
			+ `imported from "${source}", not from the leaf-owning package `
			+ `@conduction/nextcloud-vue — an override must extend the descriptor it `
			+ `overrides, or the id it registers under is not the one it thinks.`
		)
	}
	return null
}

/**
 * R6 for one registration that supplies an offlineConfig.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {number} The assertions run for it.
 */
function checkOfflineConfigOf(run, r) {
	if (run.schemaFiles === 0) {
		run.failures.push(
			`✗ [R6 offlineConfig] registerIntegration in ${r.file} supplies an \`offlineConfig\` `
				+ `naming OpenRegister schemas, but this repo declares no register/schema JSON under `
				+ `lib/Settings/** to check it against — the mapping cannot be verified.`,
		)
		return 0
	}
	const count = checkOfflineSchemas(run, r)
	return count + checkPlannedFields(run, r)
}

/**
 * R6, schemas: every schema slug the offlineConfig names is declared.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {number} The assertions run.
 */
function checkOfflineSchemas(run, r) {
	const { schemas, failures } = run
	let count = 0
	for (const key of OFFLINE_CONFIG_CONTRACT.schemas) {
		const slug = r.offlineConfig[key]
		if (slug === undefined) {
			continue
		}
		count++
		if (Object.hasOwn(schemas, slug) === false) {
			failures.push(
				`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${slug}" (${r.file}) names a `
					+ `schema this repo does not declare in lib/Settings/** — the leaf would query a `
					+ `schema that does not exist. Declared slugs: ${Object.keys(schemas).sort().join(', ')}`,
			)
		}
	}
	return count
}

/**
 * The declared properties of the offlineConfig's planned schema, or undefined
 * when it names none or names one that is not declared (reported by
 * `checkOfflineSchemas`).
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {string[]|undefined} The property names.
 */
function plannedProperties(run, r) {
	const planned = r.offlineConfig.plannedSchema
	return planned === undefined ? undefined : run.schemas[planned]
}

/**
 * R6, fields: every planned-item field the offlineConfig names is a property
 * of its planned schema.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {object} r The registration.
 *
 * @return {number} The assertions run.
 */
function checkPlannedFields(run, r) {
	const props = plannedProperties(run, r)
	if (props === undefined) {
		return 0
	}
	let count = 0
	for (const key of OFFLINE_CONFIG_CONTRACT.plannedFields) {
		if (r.offlineConfig[key] !== undefined) {
			count++
			checkPlannedField(run, { r, key, props })
		}
	}
	return count
}

/**
 * R6 for one planned-item field the offlineConfig names.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 * @param {{r: object, key: string, props: string[]}} field The registration, the
 *   offlineConfig key, and the planned schema's declared properties.
 *
 * @return {void}
 */
function checkPlannedField(run, field) {
	const { r, key, props } = field
	const prop = r.offlineConfig[key]
	if (props.includes(prop) === true) {
		return
	}
	run.failures.push(
		`✗ [R6 offlineConfig] leaf offlineConfig.${key} = "${prop}" (${r.file}) is not a `
			+ `property of schema "${r.offlineConfig.plannedSchema}" as declared in lib/Settings/** — the leaf `
			+ `would filter/display on a property that does not exist. Declared properties: `
			+ `${[...new Set(props)].sort().join(', ')}`,
	)
}

/**
 * R6: an offlineConfig names schemas and properties this repo declares.
 *
 * @param {object} run The faces, registrations and schemas, and the failures so far.
 *
 * @return {number} The assertions this rule ran.
 */
function checkOfflineConfigs(run) {
	let count = 0
	for (const r of run.regs) {
		if (r.offlineConfig !== undefined) {
			count += checkOfflineConfigOf(run, r)
		}
	}
	return count
}

/**
 * Print the verdict and exit: 0 when every rule passed over real subject
 * matter, 1 otherwise.
 *
 * @param {object} run The run, with its failures.
 * @param {Object<string, number>} counts The assertions run per rule.
 *
 * @return {void}
 */
function report(run, counts) {
	const summary = {
		scope:
			`${run.faces.length} server leaf face(s), ${run.regs.length} JS registration(s), `
			+ `${run.schemaFiles} OpenRegister schema declaration file(s)`,
		perRule: Object.entries(counts)
			.map(([rule, n]) => `${rule}:${n}`)
			.join(' '),
		counts,
	}
	if (run.failures.length === 0) {
		reportPass(summary)
	}
	reportFailures(run, summary)
}

/**
 * Print the pass line and exit 0, or exit 1 when no rule had anything to check.
 *
 * @param {{scope: string, perRule: string, counts: Object<string, number>}} summary What was read and checked.
 *
 * @return {void}
 */
function reportPass(summary) {
	console.log(
		`✓ integration parity: ${summary.scope} — all rules pass (assertions run per rule: ${summary.perRule})`,
	)
	if (Object.values(summary.counts).every((n) => n === 0) === true) {
		console.error(
			'✗ integration parity: every rule had ZERO subject matter, yet gate-24 selected this '
				+ 'repo as one that registers leaves. That contradiction means this checker failed to '
				+ 'read what the gate can see — a pass here would be a pass over nothing.',
		)
		process.exit(1)
	}
	process.exit(0)
}

/**
 * Print every violation and exit 1.
 *
 * @param {object} run The run, with its failures.
 * @param {{scope: string, perRule: string, counts: Object<string, number>}} summary What was read and checked.
 *
 * @return {void}
 */
function reportFailures(run, summary) {
	// The header carries no `✗` on purpose: gate-24 counts violations by
	// grepping `^✗` in this log, so every violation — and only a violation —
	// starts a line with it.

	console.error(
		`integration parity gate FAILED — ${run.failures.length} violation(s) over ${summary.scope}:`,
	)
	for (const f of run.failures) {
		console.error(f)
	}

	console.error(`\nAssertions run per rule: ${summary.perRule}`)
	process.exit(1)
}

/**
 * Run every rule and report.
 *
 * @return {void}
 */
function main() {
	const run = parityRun()
	report(run, {
		R1: checkRenderPairs(run),
		R2: checkIdCorrelation(run),
		R3: checkRenderModes(run),
		R4: checkMetadata(run),
		R5: checkSpreadSources(run),
		R6: checkOfflineConfigs(run),
	})
}

if (require.main === module) {
	main()
}

module.exports = {
	collectServerFaces,
	collectJsRegistrations,
	collectOrSchemas,
}
