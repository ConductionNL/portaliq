// SPDX-License-Identifier: EUPL-1.2
//
// One question of a timed task (portal-take-assessment), rendered by type:
// radio buttons for a choice, a select for an inline choice, an input for a
// text entry, a textarea for extended text, a list with move buttons for an
// order, a select per source for a match, and a text answer for any other
// type. The leaf app sent the prompt as plain text and the options in
// presentation order; nothing here can reveal an answer.

import { move, renderAs } from '../../shared/timedTask.js'

/**
 * @param {object} props Props.
 * @param {object} props.item The normalised item.
 * @param {string|Array<string>|Object<string, string>} props.value The current answer.
 * @param {(value: string|Array<string>|Object<string, string>) => void} props.onChange Receives the new answer.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {boolean} [props.disabled] Whether answering is closed.
 * @return {object} The element.
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task
 */
export default function TimedTaskItem({ item, value, onChange, t, disabled = false }) {
	const id = `tt-${item.itemId}`
	const as = renderAs(item)

	if (as === 'choice') {
		return (
			<fieldset className="portaliq-field portaliq-timedtask-item" disabled={disabled}>
				<legend>{item.prompt}</legend>
				{item.choices.map((choice) => (
					<label key={choice.id} className="portaliq-timedtask-choice">
						<input
							type="radio"
							name={id}
							value={choice.id}
							checked={value === choice.id}
							onChange={() => onChange(choice.id)}
						/>
						{choice.label}
					</label>
				))}
			</fieldset>
		)
	}

	if (as === 'inlineChoice') {
		return (
			<div className="portaliq-field portaliq-timedtask-item">
				<label htmlFor={id}>{item.prompt}</label>
				<select id={id} value={value || ''} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
					<option value="">{t('Choose')}</option>
					{item.choices.map((choice) => <option key={choice.id} value={choice.id}>{choice.label}</option>)}
				</select>
			</div>
		)
	}

	if (as === 'textEntry') {
		return (
			<div className="portaliq-field portaliq-timedtask-item">
				<label htmlFor={id}>{item.prompt}</label>
				<input id={id} type="text" value={value || ''} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
			</div>
		)
	}

	if (as === 'order') {
		const order = Array.isArray(value) ? value : []
		const labels = new Map(item.choices.map((c) => [c.id, c.label]))
		return (
			<div className="portaliq-field portaliq-timedtask-item">
				<p id={`${id}-prompt`}>{item.prompt}</p>
				<ol aria-labelledby={`${id}-prompt`} className="portaliq-timedtask-order">
					{order.map((optionId, index) => (
						<li key={optionId}>
							<span>{labels.get(optionId) || optionId}</span>
							<button type="button" disabled={disabled || index === 0} onClick={() => onChange(move(order, index, -1))}>
								{t('Move up')}
							</button>
							<button type="button" disabled={disabled || index === order.length - 1} onClick={() => onChange(move(order, index, 1))}>
								{t('Move down')}
							</button>
						</li>
					))}
				</ol>
			</div>
		)
	}

	if (as === 'match') {
		const pairs = value && typeof value === 'object' ? value : {}
		return (
			<fieldset className="portaliq-field portaliq-timedtask-item" disabled={disabled}>
				<legend>{item.prompt}</legend>
				{item.sources.map((source) => (
					<div key={source.id} className="portaliq-timedtask-match">
						<label htmlFor={`${id}-${source.id}`}>{source.label}</label>
						<select
							id={`${id}-${source.id}`}
							value={pairs[source.id] || ''}
							onChange={(e) => onChange({ ...pairs, [source.id]: e.target.value })}
						>
							<option value="">{t('Choose')}</option>
							{item.targets.map((target) => <option key={target.id} value={target.id}>{target.label}</option>)}
						</select>
					</div>
				))}
			</fieldset>
		)
	}

	// extendedText, and the text answer for every other type.
	return (
		<div className="portaliq-field portaliq-timedtask-item">
			<label htmlFor={id}>{item.prompt}</label>
			<textarea
				id={id}
				rows={as === 'extendedText' ? 10 : 4}
				value={typeof value === 'string' ? value : ''}
				placeholder={t('Type your answer')}
				disabled={disabled}
				onChange={(e) => onChange(e.target.value)}
			/>
			{as === 'extendedText' && <small className="portaliq-help">{t('Your teacher marks this question.')}</small>}
		</div>
	)
}
