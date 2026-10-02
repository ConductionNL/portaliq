// SPDX-License-Identifier: EUPL-1.2
//
// A timed task in the portal (portal-take-assessment): the tests a pupil can
// start, their attempts, the question screen with a countdown, and the
// result once it is released. Every step is one of the collection's five
// endpoint actions, forwarded by portaliq to the leaf app, which keeps every
// rule; the countdown shows the server's deadline, extra time included.

import { useEffect, useRef, useState } from 'react'
import {
	createSaveQueue,
	formatClock,
	initialResponse,
	isAnswered,
	secondsLeft,
	startAttempt,
	submitAttempt,
} from '../../shared/timedTask.js'
import Loading from './Loading.jsx'
import TimedTaskItem from './TimedTaskItem.jsx'

/**
 * A translator that only interpolates, for a caller that passes none.
 *
 * @param {string} key The English source string.
 * @param {Record<string, string|number>} [vars] Placeholder values.
 * @return {string} The interpolated string.
 */
function untranslated(key, vars) {
	let text = key
	for (const [name, value] of Object.entries(vars || {})) {
		text = text.replace(`{${name}}`, String(value))
	}
	return text
}

/**
 * The released result of one attempt, read-only.
 *
 * @param {object} props Props.
 * @param {object|null} props.result The `result` response, or null while loading.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @return {object} The element.
 */
function ResultView({ result, t }) {
	if (!result) {
		return <Loading t={t} />
	}
	if (result.released !== true) {
		return <p>{t('Your result is not available yet.')}</p>
	}
	return (
		<div className="portaliq-timedtask-result">
			<h4>{t('Your result')}</h4>
			{typeof result.score === 'number' && (
				<p>{t('Score: {score} of {maxScore}', { score: result.score, maxScore: result.maxScore ?? '' })}</p>
			)}
			{typeof result.passed === 'boolean' && <p>{result.passed ? t('Passed') : t('Not passed')}</p>}
			{typeof result.feedback === 'string' && result.feedback !== '' && <p>{result.feedback}</p>}
			<ol>
				{(Array.isArray(result.items) ? result.items : []).map((item) => (
					<li key={item.itemId}>
						<p>{item.prompt}</p>
						<p>{t('Your answer')}: {typeof item.response === 'string' ? item.response : JSON.stringify(item.response ?? '')}</p>
						{typeof item.score === 'number' && <p>{t('Score: {score} of {maxScore}', { score: item.score, maxScore: item.maxScore ?? '' })}</p>}
					</li>
				))}
			</ol>
		</div>
	)
}

/**
 * @param {object} props Props.
 * @param {object} props.collection The normalised `timedTask` collection.
 * @param {string} props.app The contributing app.
 * @param {Array<object>} props.attempts The subject's attempts (the collection rows).
 * @param {object} props.api The portal api adapter.
 * @param {(key: string, vars?: object) => string} [props.t] The translator.
 * @param {() => void} [props.onChanged] Reloads the attempts after a submit.
 * @return {object} The element.
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-the-portal-must-let-a-subject-take-a-timed-task
 */
export default function TimedTaskView({ collection, app, attempts = [], api, t, onChanged }) {
	const translate = t || untranslated
	const block = collection.timedTask
	const [tasks, setTasks] = useState(null)
	const [codes, setCodes] = useState({})
	const [error, setError] = useState(null)
	const [attempt, setAttempt] = useState(null)
	const [index, setIndex] = useState(0)
	const [answers, setAnswers] = useState({})
	const [saveState, setSaveState] = useState({ unsaved: [], failed: [], closed: false })
	const [confirming, setConfirming] = useState(false)
	const [now, setNow] = useState(() => Date.now())
	const [result, setResult] = useState(undefined)
	const [notice, setNotice] = useState(null)
	const queueRef = useRef(null)
	const receivedAtRef = useRef(0)
	const submittingRef = useRef(false)

	useEffect(() => {
		let current = true
		api.forwardAction(app, block.available, {}).then((answer) => {
			if (current) {
				setTasks(answer.ok && Array.isArray(answer.body?.tasks) ? answer.body.tasks : [])
			}
		})
		return () => { current = false }
	}, [api, app, block.available, attempt])

	const left = attempt ? secondsLeft(attempt.deadlineAt, attempt.serverNow, receivedAtRef.current, now) : null

	useEffect(() => {
		if (!attempt || !attempt.deadlineAt) {
			return undefined
		}
		const timer = setInterval(() => setNow(Date.now()), 1000)
		return () => clearInterval(timer)
	}, [attempt])

	useEffect(() => {
		if (attempt && (left === 0 || saveState.closed)) {
			handIn(true)
		}
	}, [left, saveState.closed])

	/**
	 * Start or resume a task.
	 *
	 * @param {object} task The task from `available`.
	 */
	async function start(task) {
		setError(null)
		const outcome = await startAttempt(api, app, block, task.taskId, codes[task.taskId] || '')
		if (!outcome.ok) {
			setError(outcome.message || translate('This test could not be started.'))
			return
		}
		const started = outcome.attempt
		receivedAtRef.current = Date.now()
		setNow(receivedAtRef.current)
		const initial = {}
		for (const item of started.items) {
			initial[item.itemId] = initialResponse(item, started.responses[item.itemId])
		}
		queueRef.current = createSaveQueue(
			(itemId, value) => api.forwardAction(app, block.answer, { attemptId: started.attemptId, itemId, response: value }),
			setSaveState,
		)
		submittingRef.current = false
		setAnswers(initial)
		setIndex(0)
		setConfirming(false)
		setNotice(null)
		setResult(undefined)
		setAttempt(started)
	}

	/**
	 * Keep and queue one answer.
	 *
	 * @param {string} itemId The question.
	 * @param {string|Array<string>|Object<string, string>} value The answer.
	 */
	function answer(itemId, value) {
		setAnswers((a) => ({ ...a, [itemId]: value }))
		queueRef.current.set(itemId, value)
	}

	/**
	 * Go to another question, saving on the way.
	 *
	 * @param {number} next The question index.
	 */
	async function go(next) {
		setIndex(next)
		await queueRef.current.flush()
	}

	/**
	 * Hand the attempt in, by the pupil or because time is up.
	 *
	 * @param {boolean} byClock Whether the deadline triggered it.
	 */
	async function handIn(byClock) {
		if (submittingRef.current || !attempt) {
			return
		}
		submittingRef.current = true
		const outcome = await submitAttempt(api, app, block, attempt.attemptId, queueRef.current)
		if (!outcome.allSaved) {
			setNotice(translate('Some answers could not be saved. Check your connection and try again.'))
		} else {
			setNotice(byClock ? translate('Time is up. Your test has been handed in.') : translate('Your test is handed in.'))
		}
		const finished = attempt.attemptId
		setAttempt(null)
		setConfirming(false)
		if (onChanged) {
			onChanged()
		}
		showResult(finished)
	}

	/**
	 * Load and show one attempt's result.
	 *
	 * @param {string} attemptId The attempt.
	 */
	async function showResult(attemptId) {
		setResult(null)
		const answerBody = await api.forwardAction(app, block.result, { attemptId })
		setResult(answerBody.ok ? answerBody.body : { released: false })
	}

	if (attempt) {
		const items = attempt.items
		const item = items[index]
		const answered = items.filter((i) => isAnswered(i, answers[i.itemId])).length
		const unsaved = item ? saveState.unsaved.includes(item.itemId) : false
		return (
			<section className="portaliq-timedtask">
				<h3>{attempt.title}</h3>
				<p className="portaliq-timedtask-clock" aria-hidden="true">
					{left === null ? translate('No time limit') : translate('Time left: {time}', { time: formatClock(left) })}
				</p>
				<p className="portaliq-sr-only" aria-live="polite">
					{left === null ? '' : translate('Time left: {time}', { time: formatClock(left - (left % 60)) })}
				</p>
				<p>{translate('Question {current} of {total}', { current: index + 1, total: items.length })}</p>
				{item && (
					<TimedTaskItem item={item} value={answers[item.itemId]} onChange={(v) => answer(item.itemId, v)} t={translate} />
				)}
				<p className="portaliq-help" aria-live="polite">{unsaved ? translate('Not saved yet') : translate('Saved')}</p>
				<div className="portaliq-form-actions">
					<button type="button" disabled={index === 0} onClick={() => go(index - 1)}>{translate('Previous')}</button>
					<button type="button" disabled={index >= items.length - 1} onClick={() => go(index + 1)}>{translate('Next')}</button>
					<button type="button" onClick={() => setConfirming(true)}>{translate('Hand in')}</button>
				</div>
				{confirming && (
					<div role="alertdialog" aria-live="assertive" className="portaliq-timedtask-confirm">
						<p>{translate('Hand in your test? You have answered {answered} of {total} questions.', { answered, total: items.length })}</p>
						<button type="button" onClick={() => handIn(false)}>{translate('Yes, hand in')}</button>
						<button type="button" onClick={() => setConfirming(false)}>{translate('Keep working')}</button>
					</div>
				)}
			</section>
		)
	}

	return (
		<section className="portaliq-timedtask">
			{collection.label && <h3>{collection.label}</h3>}
			{notice && <p className="portaliq-success" role="status">{notice}</p>}
			{result !== undefined && (
				<>
					<ResultView result={result} t={translate} />
					<button type="button" onClick={() => setResult(undefined)}>{translate('Back to tests')}</button>
				</>
			)}
			<h4>{translate('Tests you can take')}</h4>
			{tasks === null && <Loading t={translate} />}
			{tasks !== null && tasks.length === 0 && <p>{translate('No tests are open for you right now.')}</p>}
			<ul className="portaliq-timedtask-tasks">
				{(tasks || []).map((task) => (
					<li key={task.taskId}>
						<strong>{task.title}</strong>
						{typeof task.timeLimitMinutes === 'number' && (
							<span> {translate('Time limit: {minutes} minutes', { minutes: task.timeLimitMinutes })}</span>
						)}
						{typeof task.extraTimeMinutes === 'number' && task.extraTimeMinutes > 0 && (
							<span> {translate('Including {minutes} minutes of extra time', { minutes: task.extraTimeMinutes })}</span>
						)}
						{task.needsAccessCode === true && task.state !== 'in-progress' && (
							<span className="portaliq-field">
								<label htmlFor={`tt-code-${task.taskId}`}>{translate('Access code')}</label>
								<input
									id={`tt-code-${task.taskId}`}
									type="text"
									autoComplete="off"
									value={codes[task.taskId] || ''}
									onChange={(e) => setCodes((c) => ({ ...c, [task.taskId]: e.target.value }))}
								/>
							</span>
						)}
						<button type="button" onClick={() => start(task)}>
							{task.state === 'in-progress' ? translate('Continue') : translate('Start')}
						</button>
					</li>
				))}
			</ul>
			{error && <p className="portaliq-error" role="alert">{error}</p>}
			{attempts.length > 0 && (
				<>
					<h4>{translate('Your attempts')}</h4>
					<ul className="portaliq-timedtask-attempts">
						{attempts.map((row) => {
							const attemptId = row.id || row['@self']?.id
							return (
								<li key={attemptId}>
									<span>{row.assessmentTitle || row.assessmentId}</span>
									{row.lifecycle && <span> {row.lifecycle}</span>}
									{row.lifecycle !== 'in-progress' && attemptId && (
										<button type="button" onClick={() => showResult(attemptId)}>{translate('View result')}</button>
									)}
								</li>
							)
						})}
					</ul>
				</>
			)}
		</section>
	)
}
