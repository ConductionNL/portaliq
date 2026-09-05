// SPDX-License-Identifier: EUPL-1.2
//
// "Mijn taken" (portal-task-delivery): the authenticated party's open portal
// tasks, read and completed through portaliq's bearer-guarded proxy — the
// browser never calls openregister and never holds an X-Portal-Subject
// assertion. The completion form honours the task's frozen upload constraints
// (metadata.upload: required / maxFiles / maxSizeBytes / acceptedTypes)
// client-side, and renders the proxy's named refusals in plain language; the
// server re-checks everything (defense in depth).

import { useCallback, useEffect, useState } from 'react'

/**
 * Format an ISO timestamp as a local date, or '' when absent/invalid.
 *
 * @param value The ISO timestamp.
 * @param locale The resolved locale.
 */
function formatDate(value, locale) {
	if (!value) {
		return ''
	}
	try {
		return new Date(value).toLocaleDateString(
			locale === 'en' ? 'en-GB' : 'nl-NL',
		)
	} catch {
		return String(value)
	}
}

/**
 * The frozen upload constraints of a task row, normalised.
 *
 * @param task The task row.
 */
function uploadRules(task) {
	const upload = (task && task.metadata && task.metadata.upload) || {}
	return {
		required: upload.required === true,
		maxFiles: Number(upload.maxFiles) > 0 ? Number(upload.maxFiles) : 1,
		maxSizeBytes:
			Number(upload.maxSizeBytes) > 0 ? Number(upload.maxSizeBytes) : 0,
		acceptedTypes: Array.isArray(upload.acceptedTypes)
			? upload.acceptedTypes.map(String)
			: [],
	}
}

/**
 * Whether one file matches an accepted-type entry: exact media type, a
 * `type/*` wildcard, or an extension (`pdf` / `.pdf`) — mirrors the server's
 * matcher so a refusal is seen before the upload, not after.
 *
 * @param file The chosen File.
 * @param accepted The accepted-type entries.
 */
function typeAccepted(file, accepted) {
	if (accepted.length === 0) {
		return true
	}
	const type = String(file.type || '').toLowerCase()
	const extension = String(file.name || '')
		.split('.')
		.pop()
		.toLowerCase()
	return accepted.some((entry) => {
		const wanted = String(entry).toLowerCase()
		if (wanted.endsWith('/*')) {
			return type.startsWith(wanted.slice(0, -1))
		}
		if (wanted.includes('/')) {
			return type === wanted
		}
		return extension === wanted.replace(/^\./, '')
	})
}

/**
 * Map the proxy's refusal code to a plain-language message key.
 *
 * @param code The refusal code.
 */
function refusalKey(code) {
	if (code === 'no-such-task') {
		return 'This task does not exist or is not yours.'
	}
	if (code === 'task-closed') {
		return 'This task is already closed.'
	}
	if (code === 'upload-constraint') {
		return 'The upload was refused. Check the file rules above.'
	}
	return 'The tasks are not available right now. Please try again later.'
}

/**
 * The "Mijn taken" page: list, detail and completion form.
 *
 * @param root0 The props.
 * @param root0.api The portal API adapter.
 * @param root0.t The translator.
 * @param root0.locale The resolved locale.
 * @param root0.initialTaskUuid A task uuid to open on mount (the inbox deep link), or null.
 * @param root0.onTaskCompleted Called after a successful completion (lets the shell refresh).
 */
export default function TasksPage({
	api,
	t,
	locale,
	initialTaskUuid = null,
	onTaskCompleted,
}) {
	const [list, setList] = useState({ loading: true, results: [] })
	const [detail, setDetail] = useState(null) // {task} | {refusal} | null (list view)
	const [comment, setComment] = useState('')
	const [files, setFiles] = useState([])
	const [formError, setFormError] = useState(null)
	const [busy, setBusy] = useState(false)
	const [completed, setCompleted] = useState(false)

	const loadList = useCallback(async () => {
		setList((s) => ({ ...s, loading: true }))
		const page = await api.fetchTasks()
		setList({ loading: false, results: page.results })
	}, [api])

	useEffect(() => {
		loadList()
	}, [loadList])

	const openTask = useCallback(
		async (uuid) => {
			setComment('')
			setFiles([])
			setFormError(null)
			setCompleted(false)
			setDetail({ loading: true })
			const res = await api.fetchTask(uuid)
			if (res.ok) {
				setDetail({ task: res.task })
			} else {
				setDetail({ refusal: refusalKey(res.code) })
			}
		},
		[api],
	)

	// The inbox deep link: open the named task once, on arrival.
	useEffect(() => {
		if (initialTaskUuid) {
			openTask(initialTaskUuid)
		}
	}, [initialTaskUuid, openTask])

	/**
	 * Validate the chosen files against the task's frozen rules; returns the
	 * first violated rule as a rendered message, or null when all pass.
	 *
	 * @param task The task row.
	 * @param chosen The chosen File list.
	 */
	function validateFiles(task, chosen) {
		const rules = uploadRules(task)
		if (rules.required && chosen.length === 0) {
			return t('A file is required for this task.')
		}
		if (chosen.length > rules.maxFiles) {
			return t('You can add at most {count} file(s).', {
				count: rules.maxFiles,
			})
		}
		for (const file of chosen) {
			if (rules.maxSizeBytes > 0 && file.size > rules.maxSizeBytes) {
				return t('This file is too large. The limit is {size} MB.', {
					size: Math.round(rules.maxSizeBytes / (1024 * 1024)),
				})
			}
			if (!typeAccepted(file, rules.acceptedTypes)) {
				return t('This file type is not accepted. Allowed: {types}.', {
					types: rules.acceptedTypes.join(', '),
				})
			}
		}
		return null
	}

	/**
	 * Submit the completion through the proxy.
	 *
	 * @param task The open task.
	 */
	async function submit(task) {
		const violation = validateFiles(task, files)
		if (violation) {
			setFormError(violation)
			return
		}
		setFormError(null)
		setBusy(true)
		const res = await api.completeTask(task.uuid, { comment, files })
		setBusy(false)
		if (res.ok) {
			setCompleted(true)
			loadList()
			if (onTaskCompleted) {
				onTaskCompleted()
			}
			return
		}
		setFormError(t(refusalKey(res.code)))
	}

	if (detail) {
		if (detail.loading) {
			return <p className="portaliq-loading">…</p>
		}

		const back = (
			<button
				type="button"
				className="portaliq-tasks-back"
				onClick={() => {
					setDetail(null)
					loadList()
				}}>
				{t('Back to the list')}
			</button>
		)

		if (detail.refusal) {
			return (
				<section className="portaliq-task-detail">
					<p className="portaliq-task-refusal">{t(detail.refusal)}</p>
					{back}
				</section>
			)
		}

		const task = detail.task
		const rules = uploadRules(task)
		const due = formatDate(task.dueAt, locale)

		return (
			<section className="portaliq-task-detail">
				<h1>{task.title || t('Task')}</h1>
				{task.description && (
					<p className="portaliq-task-description">{task.description}</p>
				)}
				{due && (
					<p className="portaliq-task-due">
						{t('Finish before {date}', { date: due })}
						{task.overdue === true && (
							<strong className="portaliq-task-overdue">
								{' '}
								· {t('Overdue')}
							</strong>
						)}
					</p>
				)}

				{completed && (
					<p className="portaliq-task-done" role="status">
						{t('Your task has been submitted. Thank you.')}
					</p>
				)}

				{!completed && (
					<form
						className="portaliq-task-form"
						onSubmit={(event) => {
							event.preventDefault()
							submit(task)
						}}>
						<div className="portaliq-task-upload">
							<label htmlFor="portaliq-task-files">
								{rules.required
									? t('Add a file (required)')
									: t('Add a file (optional)')}
							</label>
							<ul className="portaliq-task-upload-rules">
								{rules.maxFiles > 1 && (
									<li>
										{t('You can add at most {count} file(s).', {
											count: rules.maxFiles,
										})}
									</li>
								)}
								{rules.maxSizeBytes > 0 && (
									<li>
										{t('Maximum file size: {size} MB.', {
											size: Math.round(
												rules.maxSizeBytes / (1024 * 1024),
											),
										})}
									</li>
								)}
								{rules.acceptedTypes.length > 0 && (
									<li>
										{t('Allowed file types: {types}.', {
											types: rules.acceptedTypes.join(', '),
										})}
									</li>
								)}
							</ul>
							<input
								id="portaliq-task-files"
								type="file"
								multiple={rules.maxFiles > 1}
								accept={
									rules.acceptedTypes.length > 0
										? rules.acceptedTypes
												.map((e) =>
													e.includes('/')
														? e
														: `.${e.replace(/^\./, '')}`,
												)
												.join(',')
										: undefined
								}
								onChange={(event) =>
									setFiles(Array.from(event.target.files || []))
								}
							/>
						</div>

						<div className="portaliq-task-comment">
							<label htmlFor="portaliq-task-comment">
								{t('Comment (optional)')}
							</label>
							<textarea
								id="portaliq-task-comment"
								value={comment}
								rows={4}
								onChange={(event) => setComment(event.target.value)}
							/>
						</div>

						{formError && (
							<p className="portaliq-task-error" role="alert">
								{formError}
							</p>
						)}

						<button type="submit" disabled={busy}>
							{busy ? t('Sending…') : t('Submit task')}
						</button>
					</form>
				)}

				{back}
			</section>
		)
	}

	if (list.loading) {
		return <p className="portaliq-loading">…</p>
	}

	if (list.results.length === 0) {
		return (
			<p className="portaliq-empty">
				<em>{t('No open tasks.')}</em>
			</p>
		)
	}

	return (
		<ul className="portaliq-tasks">
			{list.results.map((task) => (
				<li key={task.uuid} className="portaliq-task-row">
					<button
						type="button"
						className="portaliq-task-open"
						onClick={() => openTask(task.uuid)}>
						<span className="portaliq-task-title">
							{task.displayTitle || task.title}
						</span>
						{task.dueAt && (
							<span className="portaliq-task-row-due">
								{t('Finish before {date}', {
									date: formatDate(task.dueAt, locale),
								})}
								{task.overdue === true && (
									<strong className="portaliq-task-overdue">
										{' '}
										· {t('Overdue')}
									</strong>
								)}
							</span>
						)}
					</button>
				</li>
			))}
		</ul>
	)
}
