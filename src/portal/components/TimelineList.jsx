// SPDX-License-Identifier: EUPL-1.2
//
// The history of one object, as its contributing app declared it through a
// collection's `timeline` (portaliq#723): dossiq's "Wat er is gebeurd" on a
// resident's case. The entries arrive exactly as the app returned them; the
// app decided what is public, so nothing here filters or adds. This only
// orders them newest first and says so when there are none.

/**
 * The moment of an entry, for sorting; entries without one sort last.
 *
 * @param {object} entry A timeline entry.
 * @return {number} Milliseconds since the epoch, or -Infinity.
 */
function momentOf(entry) {
	const time = Date.parse(entry?.occurredAt || entry?.date || '')
	return Number.isNaN(time) ? -Infinity : time
}

/**
 * The entries newest first, without changing the list it was given.
 *
 * @param {Array<object>} entries The entries as returned.
 * @return {Array<object>} A newest-first copy.
 */
export function newestFirst(entries) {
	return (Array.isArray(entries) ? entries : [])
		.map((entry, index) => ({ entry, index }))
		.sort((a, b) => (momentOf(b.entry) - momentOf(a.entry)) || (a.index - b.index))
		.map(({ entry }) => entry)
}

/**
 * The words of one entry: its message, else its label or title.
 *
 * @param {object} entry A timeline entry.
 * @return {string} What happened.
 */
function textOf(entry) {
	return String(entry?.message || entry?.label || entry?.title || '')
}

/**
 * Render one object's declared history.
 *
 * @param {object} root0 The props.
 * @param {string} [root0.label] The heading the app declared.
 * @param {Array<object>|null} root0.entries The entries, or null while loading.
 * @return {object} The rendered history.
 */
export default function TimelineList({ label, entries }) {
	const heading = label || 'Wat er is gebeurd'
	if (entries === null || entries === undefined) {
		return (
			<section className="portaliq-timeline" aria-busy="true">
				<h4>{heading}</h4>
				<p className="portaliq-loading">…</p>
			</section>
		)
	}
	const ordered = newestFirst(entries)
	return (
		<section className="portaliq-timeline">
			<h4>{heading}</h4>
			{ordered.length === 0
				? <p className="portaliq-empty"><em>Er is nog niets gebeurd.</em></p>
				: (
					<ol>
						{ordered.map((entry, i) => {
							const moment = momentOf(entry)
							return (
								<li key={entry.id || i}>
									{moment !== -Infinity && <time dateTime={entry.occurredAt || entry.date}>{new Date(moment).toLocaleDateString('nl-NL')}</time>}
									{moment !== -Infinity && ' '}
									<span>{textOf(entry)}</span>
								</li>
							)
						})}
					</ol>
				)}
		</section>
	)
}
