#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-integration-parity-parsers.spec.mjs: pins the static readers inside
// scripts/check-integration-parity.js (balanced, splitTopLevel, resolvePhp,
// resolveJs, the const tables, importSourceOf) and the two collectors over
// awkward sources the golden record does not reach: quotes, escapes, line,
// block and hash comments, nested delimiters, providers and descriptors
// passed by name.
//
// Usage:
//   node --test tests/check-integration-parity-parsers.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SCRIPT = join(ROOT, 'scripts/check-integration-parity.js')
const INTERNALS = [
	'balanced',
	'splitTopLevel',
	'resolvePhp',
	'resolveJs',
	'phpLocalConsts',
	'jsLocalConsts',
	'jsLocalObjectLiterals',
	'importSourceOf',
	'collectServerFaces',
	'collectJsRegistrations',
	'collectOrSchemas',
]

/**
 * Load the script in a sandbox against a repository root and hand back its
 * module-scope functions.
 *
 * @param {string} root The repository root the script reads.
 * @return {object} Function name to function.
 */
function load(root) {
	const source =
		readFileSync(SCRIPT, 'utf8').replace(/^#!.*\n/, '')
		+ `\nmodule.exports.__internals = { ${INTERNALS.join(', ')} }\n`
	const module = { exports: {} }
	const context = vm.createContext({
		module,
		exports: module.exports,
		require: createRequire(SCRIPT),
		process: { ...process, argv: [process.argv[0], SCRIPT, root] },
		console,
	})
	vm.runInContext(source, context, { filename: SCRIPT })
	const plain = {}
	for (const [name, fn] of Object.entries(module.exports.__internals)) {
		plain[name] = (...args) => toPlain(fn(...args))
	}
	return plain
}

/**
 * Copy a sandbox value into this realm so deepEqual compares structure only.
 *
 * @param {*} value The value returned by the sandbox.
 * @return {*} The same value built from this realm's arrays and objects.
 */
function toPlain(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

/**
 * Write files to a scratch root, run `fn` against the loaded script, clean up.
 *
 * @param {object} files Relative path to content.
 * @param {Function} fn Receives the internals.
 * @return {*} What `fn` returns, as plain JSON.
 */
function withRepo(files, fn) {
	const root = mkdtempSync(join(tmpdir(), 'pq-parity-parsers-'))
	try {
		for (const [file, content] of Object.entries(files)) {
			mkdirSync(dirname(join(root, file)), { recursive: true })
			writeFileSync(join(root, file), content)
		}
		return fn(load(root))
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
}

const P = load(ROOT)

test('balanced honours quotes, escapes and comments', () => {
	assert.equal(P.balanced('(a, (b), c) tail', 0), 'a, (b), c')
	assert.equal(P.balanced("x('(' , \")\")", 1), "'(' , \")\"")
	assert.equal(P.balanced("('it\\'s )' )", 0), "'it\\'s )' ")
	assert.equal(P.balanced('(a // )\n b)', 0), 'a // )\n b')
	assert.equal(P.balanced('(a /* ) */ b)', 0), 'a /* ) */ b')
	assert.equal(P.balanced('(a # )\n b)', 0), 'a # )\n b')
	assert.equal(P.balanced('{a # }\n }', 0), 'a # ')
	assert.equal(P.balanced('[1, [2, 3], `]`]', 0), '1, [2, 3], `]`')
	assert.equal(P.balanced('(a, b', 0), '')
	assert.equal(P.balanced('(a // no newline', 0), '')
	assert.equal(P.balanced('(a /* no end', 0), '')
	assert.equal(P.balanced('(a # no newline', 0), '')
	assert.equal(P.balanced("('open", 0), '')
})

test('splitTopLevel splits on depth-zero commas only', () => {
	assert.deepEqual(P.splitTopLevel(' a , b(c, d), [e, f], {g, h} '), [
		'a',
		'b(c, d)',
		'[e, f]',
		'{g, h}',
	])
	assert.deepEqual(P.splitTopLevel("'a,b', \"c,d\", `e,f`, 'g\\',h'"), [
		"'a,b'",
		'"c,d"',
		'`e,f`',
		"'g\\',h'",
	])
	assert.deepEqual(P.splitTopLevel('a, // one, two\n b'), ['a', 'b'])
	assert.deepEqual(P.splitTopLevel('a /* x, y */, b'), ['a', 'b'])
	assert.deepEqual(P.splitTopLevel('a, # x, y\n b'), ['a', 'b'])
	assert.deepEqual(P.splitTopLevel('a, // trailing'), ['a'])
	assert.deepEqual(P.splitTopLevel('a, /* open'), ['a'])
	assert.deepEqual(P.splitTopLevel('a, # trailing'), ['a'])
	assert.deepEqual(P.splitTopLevel('a,,b,'), ['a', '', 'b'])
	assert.deepEqual(P.splitTopLevel(''), [])
	assert.deepEqual(P.splitTopLevel('   '), [])
	assert.deepEqual(P.splitTopLevel('a)), b'), ['a)), b'])
})

test('resolvePhp reads literals, translations, constants and arrays', () => {
	const consts = {
		local: { LABEL: 'Local', LIST: ['x'] },
		global: { 'Other::NAME': 'Global' },
	}
	assert.equal(P.resolvePhp(" 'it\\'s' ", consts), "it's")
	assert.equal(P.resolvePhp('"say \\"hi\\""', consts), 'say "hi"')
	assert.equal(P.resolvePhp("$this->l10n->t('Hello', [$a])", consts), 'Hello')
	assert.equal(P.resolvePhp('$this->l->t("Dq")', consts), 'Dq')
	assert.equal(P.resolvePhp('self::LABEL', consts), 'Local')
	assert.deepEqual(P.resolvePhp('static::LIST', consts), ['x'])
	assert.equal(P.resolvePhp('self::MISSING', consts), null)
	assert.equal(P.resolvePhp('Other::NAME', consts), 'Global')
	assert.equal(P.resolvePhp('Other::NONE', consts), null)
	assert.equal(
		P.resolvePhp('LeafDescriptor::RENDER_MODE_MOUNT', consts),
		'mount',
	)
	assert.deepEqual(P.resolvePhp("['a', self::LABEL]", consts), [
		'a',
		'Local',
	])
	assert.equal(P.resolvePhp("['a', $b]", consts), null)
	assert.equal(P.resolvePhp("['a', ['b']]", consts), null)
	assert.equal(P.resolvePhp('$dynamic', consts), null)
})

test('resolveJs reads literals, t() calls, arrays and local names', () => {
	const locals = { NAME: 'Local', LIST: ['y'] }
	assert.equal(P.resolveJs(" 'it\\'s' ", locals), "it's")
	assert.equal(P.resolveJs('"dq"', locals), 'dq')
	assert.equal(P.resolveJs('`tpl`', locals), 'tpl')
	assert.equal(P.resolveJs('`a${b}`', locals), null)
	assert.equal(P.resolveJs("t('app', 'Label')", locals), 'Label')
	assert.equal(P.resolveJs("t('Only')", locals), 'Only')
	assert.equal(P.resolveJs("t('app', name)", locals), null)
	assert.deepEqual(P.resolveJs("['a', NAME]", locals), ['a', 'Local'])
	assert.equal(P.resolveJs("['a', other]", locals), null)
	assert.equal(P.resolveJs("['a', LIST]", locals), null)
	assert.equal(P.resolveJs('NAME', locals), 'Local')
	assert.deepEqual(P.resolveJs('LIST', locals), ['y'])
	assert.equal(P.resolveJs('unknown', locals), null)
	assert.equal(P.resolveJs('a.b', locals), null)
})

test('const tables resolve what they can and skip the rest', () => {
	assert.deepEqual(
		P.phpLocalConsts(
			"<?php\nconst A = 'a';\nconst B = [self::A, 'b'];\nconst C = $x;\nconst D = self::A;\nconst E = 'no semicolon'",
		),
		{ A: 'a', B: ['a', 'b'], D: 'a' },
	)
	assert.deepEqual(
		P.jsLocalConsts(
			"const A = 'a'\nconst B = [A, 'b']\nconst C = foo()\nconst D = A,\nconst E = 'last'",
		),
		{ A: 'a', B: ['a', 'b'], D: 'a', E: 'last' },
	)
	assert.deepEqual(
		P.jsLocalObjectLiterals("const A = { id: 'a', n: { x: 1 } }\nconst B = 2\n"),
		{ A: " id: 'a', n: { x: 1 } " },
	)
})

test('importSourceOf finds named, aliased and default bindings', () => {
	const src =
		"import Def, { a, b as c } from 'pkg-one'\nimport { d } from 'pkg-two'\nimport e from 'pkg-three'\n"
	assert.equal(P.importSourceOf(src, 'a'), 'pkg-one')
	assert.equal(P.importSourceOf(src, 'c'), 'pkg-one')
	assert.equal(P.importSourceOf(src, 'b'), null)
	assert.equal(P.importSourceOf(src, 'Def'), 'pkg-one')
	assert.equal(P.importSourceOf(src, 'd'), 'pkg-two')
	assert.equal(P.importSourceOf(src, 'e'), 'pkg-three')
	assert.equal(P.importSourceOf(src, 'z'), null)
})

test('collectServerFaces reads descriptors and providers', () => {
	const faces = withRepo(
		{
			'lib/App.php': "<?php\nfinal class App {\n\tconst APP_ID = 'demo';\n}\n",
			'lib/Leaf.php':
				"<?php\nclass Leaf {\n\tconst KINDS = ['render-surface'];\n\tpublic function a() {\n\t\treturn new LeafDescriptor(id: App::APP_ID, kinds: self::KINDS, renderMode: 'mount', 'positional', label: $dyn);\n\t}\n\tpublic function b() {\n\t\treturn new LeafDescriptor(kinds: ['data-provider']);\n\t}\n}\n",
			'lib/Prov.php':
				"<?php\nclass Prov implements Foo, IntegrationProviderInterface {\n\tpublic function getId(): string { return 'prov'; }\n\tpublic function getLabel(): string { $x = 1; }\n\tpublic function getIcon(): string { return $this->icon; }\n\tabstract public function getGroup(): string;\n}\n",
			'lib/NoIdProv.php':
				"<?php\nclass NoIdProv extends AbstractIntegrationProvider {\n\tpublic function getId(): string { return $this->id; }\n}\n",
			'lib/NoClass.php': "<?php\nconst LOOSE = 'x';\n",
			'lib/readme.txt': 'not php',
		},
		(p) => p.collectServerFaces(),
	)
	assert.deepEqual(
		faces.sort((a, b) => a.file.localeCompare(b.file)),
		[
			{
				kind: 'LeafDescriptor',
				file: 'lib/Leaf.php',
				fields: { id: 'demo', kinds: ['render-surface'], renderMode: 'mount' },
				id: 'demo',
				renderSurface: true,
				renderMode: 'mount',
			},
			{
				kind: 'LeafDescriptor',
				file: 'lib/Leaf.php',
				fields: { kinds: ['data-provider'] },
				id: null,
				renderSurface: false,
				renderMode: null,
			},
			{
				kind: 'IntegrationProvider',
				file: 'lib/Prov.php',
				fields: { id: 'prov' },
				id: 'prov',
				renderSurface: false,
				renderMode: null,
			},
		],
	)
})

test('collectJsRegistrations reads inline, named and shorthand members', () => {
	const regs = withRepo(
		{
			'src/a.js':
				"import { base } from '@conduction/nextcloud-vue'\nconst ID = 'leaf-a'\nexport function registerIntegration(d) { return d }\nregisterIntegration({ ...base, id: ID, 'renderMode': 'mount', \"mount\": m, unmount, 42: x, offlineConfig: { plannedSchema: 'p', \"titleField\": 't', dateField: dyn, 7: 'n' } })\nregisterIntegrationIcons({ id: 'icons' })\n",
			'src/b.vue':
				"<script>\nconst DESC = {\n\tid: 'leaf-b',\n\ttab: T,\n\tofflineConfig: CONFIG,\n}\nOCA.OpenRegister.integrations . register (DESC)\nOCA.OpenRegister.integrations.register(unknownName)\nOCA.OpenRegister.integrations.register(make())\n</script>\n",
			'src/c.ts': "const x = 'registerIntegration is mentioned only'\n",
			'src/d.js': 'export default {}\n',
		},
		(p) => p.collectJsRegistrations(),
	)
	assert.deepEqual(
		regs.sort((a, b) => a.file.localeCompare(b.file)),
		[
			{
				file: 'src/a.js',
				fields: { id: 'leaf-a', renderMode: 'mount' },
				keys: ['id', 'renderMode', 'mount', 'unmount', 'offlineConfig'],
				spreads: ['base'],
				offlineConfig: { plannedSchema: 'p', titleField: 't' },
				id: 'leaf-a',
				renderMode: 'mount',
				spreadSources: { base: '@conduction/nextcloud-vue' },
			},
			{
				file: 'src/b.vue',
				fields: { id: 'leaf-b' },
				keys: ['id', 'tab', 'offlineConfig'],
				spreads: [],
				id: 'leaf-b',
				renderMode: null,
				spreadSources: {},
			},
		],
	)
})

test('collectOrSchemas reads slugs and properties from lib/Settings', () => {
	const out = withRepo(
		{
			'lib/Settings/a.json': JSON.stringify({
				components: {
					schemas: {
						one: { slug: 'one-slug', properties: { a: {}, b: {} } },
						two: { properties: { c: {} } },
						three: { slug: 'one-slug', properties: { d: {} } },
						four: null,
						five: { properties: 'nope' },
					},
				},
			}),
			'lib/Settings/b.json': JSON.stringify({ components: {} }),
			'lib/Settings/c.json': JSON.stringify({ components: { schemas: null } }),
			'lib/Settings/d.json': 'not json',
			'lib/Settings/e.json': 'null',
		},
		(p) => p.collectOrSchemas(),
	)
	assert.deepEqual(out, {
		schemas: {
			'one-slug': ['a', 'b', 'd'],
			two: ['c'],
			four: [],
			five: [],
		},
		files: 1,
	})
})
