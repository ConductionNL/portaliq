import assert from 'node:assert/strict'
// When a calendar item is, as a person reads it, pinned before `when` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const block = await loadSfc('src/site/components/collections/CalendarBlock.vue')

function whenOf(item, locale = 'nl') {
	const vm = {
		locale,
		format: (date, options) => `D(${date.toISOString().slice(0, 10)},${Object.keys(options).join('+')})`,
		t: (key, vars) => `${key}|${vars.from}|${vars.to}`,
	}
	return block.methods.when.call(vm, item)
}
const at = (iso) => new Date(iso)

test('an all-day item on one day, and over several days', () => {
	assert.match(whenOf({ allDay: true, start: at('2026-11-10T00:00:00'), end: at('2026-11-10T00:00:00') }), /^D\([^)]*,weekday\+day\+month\)$/)
	assert.match(whenOf({ allDay: true, start: at('2026-11-10T00:00:00'), end: at('2026-11-12T00:00:00') }), /^\{from\} to \{to\}\|D\(.*\)\|D\(.*\)$/)
})

test('a timed item on one day: from–to, or just from when it does not end later', () => {
	const start = at('2026-11-10T09:00:00')
	const range = whenOf({ allDay: false, start, end: at('2026-11-10T10:30:00') })
	assert.match(range, /^D\(.*\) 09:00–10:30$/)
	assert.match(whenOf({ allDay: false, start, end: start }), /^D\(.*\) 09:00$/)
	assert.match(whenOf({ allDay: false, start, end: at('2026-11-10T08:00:00') }), /^D\(.*\) 09:00$/)
})

test('a timed item over days names both moments', () => {
	const text = whenOf({ allDay: false, start: at('2026-11-10T09:00:00'), end: at('2026-11-11T17:00:00') })
	assert.match(text, /^\{from\} to \{to\}\|D\(.*\) 09:00\|D\(.*\) 17:00$/)
})

test('a language the clock cannot use leaves the time out, and no language reads Dutch', () => {
	assert.match(whenOf({ allDay: false, start: at('2026-11-10T09:00:00'), end: at('2026-11-10T10:00:00') }, 'not a locale!'), /^D\(.*\) –$/)
	assert.match(whenOf({ allDay: false, start: at('2026-11-10T09:00:00'), end: at('2026-11-10T10:00:00') }, ''), /^D\(.*\) 09:00–10:00$/)
})
