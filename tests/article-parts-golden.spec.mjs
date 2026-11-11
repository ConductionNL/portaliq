// A news article's body in parts, pinned before `articleParts` is split.
import { test } from 'node:test'
import { articleParts } from '../src/site/widgets/nlNewsArticle/article.js'
import { golden } from './support/golden.mjs'

const pinned = golden('article-parts')

const BODIES = {
	plain: 'Een alinea.\n\nNog een alinea.',
	facts: 'Intro.\n\n- **Wanneer:** woensdag 14 oktober\n- **Waar**: Aula\n* **Voor wie:**  ouders \n\nSlot.',
	'mixed-list': '- **Wanneer:** woensdag\n- gewoon punt',
	'facts-first-and-last': '+ **Prijs:** gratis\n\n- **Duur:** 1 uur',
	'blank-blocks': '\n\n   \n\nTekst\n\n\n\nMeer',
	'two-markdown-around-facts': 'A\n\nB\n\n- **X:** y\n\nC\n\nD',
	empty: '',
}

for (const [name, body] of Object.entries(BODIES)) {
	test(`parts: ${name}`, () => pinned.check(name, articleParts(body)))
}
test('no body', () => {
	pinned.check('null', articleParts(null))
	pinned.check('undefined', articleParts(undefined))
})
test('save the golden', () => pinned.save())
