/**
 * Actions in the Files app:
 * - "Mount with VeraCrypt" on the volume files (.hc, .vc… and those added by hand);
 * - "Unmount" on a mounted volume: on its folder inside "VeraCrypt" and on its file;
 * - "Open mounted volume" on the file of a mounted volume.
 *
 * Plain script, loaded before the Files app starts. The actions are put in the
 * registries that the Files app reads, the same ones used by @nextcloud/files:
 * v3 (Nextcloud 30-32, the action gets node, view, dir) and v4 (the next ones,
 * the action gets one {nodes, view, folder, contents} object).
 */
(function () {
	'use strict'

	/* ---------- Nextcloud globals ---------- */

	function t(app, text, vars) {
		if (window.OC && window.OC.L10N) {
			return window.OC.L10N.translate(app, text, vars)
		}
		return text.replace(/{(\w+)}/g, (all, key) => (vars && key in vars ? String(vars[key]) : all))
	}

	function loadState(app, key, fallback) {
		const input = document.getElementById('initial-state-' + app + '-' + key)
		if (!input) {
			return fallback
		}
		try {
			const bytes = Uint8Array.from(atob(input.value), (c) => c.charCodeAt(0))
			return JSON.parse(new TextDecoder().decode(bytes))
		} catch (e) {
			return fallback
		}
	}

	function generateUrl(path) {
		if (window.OC && window.OC.generateUrl) {
			return window.OC.generateUrl(path)
		}
		return ((window.OC && window.OC.webroot) || '') + '/index.php' + path
	}

	function getRequestToken() {
		return (window.OC && window.OC.requestToken) || document.head.dataset.requesttoken || ''
	}

	function emit(name, payload) {
		if (window._nc_event_bus) {
			window._nc_event_bus.emit(name, payload)
		}
	}

	const APP = 'veracryptbridge'

	const LOCK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>'
	const UNLOCK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 17c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-9h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6h1.9c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm0 12H6V10h12v10z"/></svg>'
	const FOLDER = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>'

	/** mountName, extensions, extra (paths added by hand), volumes [{path, dir, readonly}] */
	let state = loadState(APP, 'files', { mountName: 'VeraCrypt', extensions: ['hc', 'vc'], extra: [], volumes: [] })

	/* ---------- Arguments of v3 and v4 ---------- */

	function nodesOf(arg) {
		if (Array.isArray(arg)) {
			return arg
		}
		if (arg && Array.isArray(arg.nodes)) {
			return arg.nodes
		}
		return arg ? [arg] : []
	}

	function single(arg) {
		const nodes = nodesOf(arg)
		return nodes.length === 1 ? nodes[0] : null
	}

	function isFolder(node) {
		return node.type === 'folder'
	}

	/** Path in the user's Files, e.g. "/Documents/archive.hc" */
	function pathOf(node) {
		return node.path
	}

	function rootOf() {
		return '/' + state.mountName
	}

	/** The mounted volume a node stands for: its file, or its folder inside "VeraCrypt" */
	function volumeOf(node) {
		const path = pathOf(node)
		return state.volumes.find((v) => isFolder(node) ? path === rootOf() + '/' + v.dir : path === v.path) || null
	}

	function isVolumeFile(node) {
		if (isFolder(node)) {
			return false
		}
		const path = pathOf(node)
		if (path === rootOf() || path.startsWith(rootOf() + '/')) {
			return false
		}
		const ext = (node.basename.split('.').pop() || '').toLowerCase()
		return state.extensions.includes(ext) || state.extra.includes(path)
	}

	/* ---------- Requests to the app ---------- */

	async function post(route, data) {
		const response = await fetch(generateUrl('/apps/' + APP + route), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() },
			body: JSON.stringify(data),
		})
		let body = null
		try {
			body = await response.json()
		} catch (e) {
			body = { ok: false, message: t(APP, 'Unexpected answer from the server ({status}).', { status: response.status }) }
		}
		if (body.state) {
			state = body.state
		}
		return body
	}

	function toast(type, message) {
		const toasts = window.OCP && window.OCP.Toast
		if (toasts && toasts[type]) {
			toasts[type](message)
		}
	}

	function goTo(dir) {
		const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
		if (router) {
			router.goToRoute(null, { view: 'files' }, { dir })
		} else {
			window.location.href = generateUrl('/apps/files/?dir=' + encodeURIComponent(dir))
		}
	}

	function currentDir() {
		const query = new URLSearchParams(window.location.search)
		return query.get('dir') || '/'
	}

	/* ---------- Dialog ---------- */

	function el(tag, attrs = {}, children = []) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'text') {
				node.textContent = value
			} else {
				node.setAttribute(key, value)
			}
		}
		children.forEach((child) => node.appendChild(child))
		return node
	}

	/**
	 * A small modal dialog. fields: elements of the form; onSubmit(form, setBusy, setError)
	 * returns true to close the dialog.
	 */
	function dialog({ title, fields, submitLabel, busyLabel, onSubmit }) {
		const previous = document.activeElement
		const error = el('p', { class: 'vcb-dialog-error', role: 'alert' })
		const cancel = el('button', { type: 'button', class: 'button', text: t(APP, 'Cancel') })
		const submit = el('button', { type: 'submit', class: 'button primary', text: submitLabel })
		const form = el('form', { class: 'vcb-dialog-form' }, [...fields, error, el('div', { class: 'vcb-dialog-buttons' }, [cancel, submit])])
		const heading = el('h2', { id: 'vcb-dialog-title', text: title })
		const box = el('div', { class: 'vcb-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'vcb-dialog-title' }, [heading, form])
		const overlay = el('div', { class: 'vcb-dialog-overlay' }, [box])
		let busy = false
		let timer = null

		function close() {
			if (busy) {
				return
			}
			clearInterval(timer)
			overlay.remove()
			document.removeEventListener('keydown', onKey, true)
			if (previous && previous.focus) {
				previous.focus()
			}
		}
		function onKey(event) {
			if (event.key === 'Escape') {
				event.stopPropagation()
				close()
			}
		}
		function setBusy(on) {
			busy = on
			form.querySelectorAll('input, textarea, button').forEach((input) => {
				input.disabled = on
			})
			clearInterval(timer)
			if (!on) {
				submit.textContent = submitLabel
				return
			}
			const began = Date.now()
			const label = el('span', { text: busyLabel })
			submit.textContent = ''
			submit.appendChild(el('span', { class: 'vcb-spinner', 'aria-hidden': 'true' }))
			submit.appendChild(label)
			timer = setInterval(() => {
				const seconds = Math.floor((Date.now() - began) / 1000)
				if (seconds >= 3) {
					label.textContent = busyLabel + ' ' + seconds + ' s'
				}
			}, 1000)
		}
		function setError(message) {
			error.textContent = message || ''
		}

		cancel.addEventListener('click', close)
		overlay.addEventListener('mousedown', (event) => {
			if (event.target === overlay) {
				close()
			}
		})
		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			if (busy) {
				return
			}
			setError('')
			setBusy(true)
			let done = false
			try {
				done = await onSubmit(form, setError)
			} catch (e) {
				setError(t(APP, 'The request failed: {error}', { error: String(e) }))
			}
			setBusy(false)
			if (done) {
				close()
			}
		})
		document.addEventListener('keydown', onKey, true)
		document.body.appendChild(overlay)
		const first = form.querySelector('input:not([type=checkbox]), button')
		if (first) {
			first.focus()
		}
	}

	function field(label, input) {
		return el('label', { class: 'vcb-dialog-field' }, [el('span', { text: label }), input])
	}

	/* ---------- Actions ---------- */

	function mountDialog(node) {
		const password = el('input', { type: 'password', name: 'password', autocomplete: 'off' })
		const pim = el('input', { type: 'text', name: 'pim', inputmode: 'numeric', pattern: '[0-9]*', autocomplete: 'off' })
		const keyfiles = el('textarea', { name: 'keyfiles', rows: '2', placeholder: '/Documents/key.jpg' })
		const readonly = el('input', { type: 'checkbox', name: 'readonly' })
		const more = el('details', { class: 'vcb-dialog-more' }, [
			el('summary', { text: t(APP, 'PIM, keyfiles, read-only') }),
			field(t(APP, 'PIM (only if you set one)'), pim),
			field(t(APP, 'Keyfiles (optional): paths in your Files, one per line'), keyfiles),
			el('label', { class: 'vcb-dialog-check' }, [readonly, el('span', { text: t(APP, 'Read-only') })]),
		])
		dialog({
			title: t(APP, 'Mount “{name}”', { name: node.basename }),
			fields: [
				field(t(APP, 'Password'), password),
				more,
				el('p', { class: 'vcb-dialog-hint', text: t(APP, 'Opening can take up to half a minute: VeraCrypt tries every algorithm. With a wrong password it takes longest.') }),
			],
			submitLabel: t(APP, 'Mount'),
			busyLabel: t(APP, 'Mounting…'),
			async onSubmit(form, setError) {
				const result = await post('/api/mount', {
					path: pathOf(node),
					password: password.value,
					pim: pim.value,
					keyfiles: keyfiles.value,
					readonly: readonly.checked,
				})
				if (!result.ok) {
					password.value = ''
					setError(result.message)
					setTimeout(() => password.focus(), 0)
					return false
				}
				toast('success', result.message)
				goTo(rootOf() + '/' + result.dir)
				return true
			},
		})
	}

	async function unmount(node, force = false) {
		const volume = volumeOf(node)
		if (!volume) {
			return null
		}
		toast('info', t(APP, 'Unmounting…'))
		const result = await post('/api/unmount', { path: volume.path, force })
		if (result.ok) {
			toast('success', result.message)
			const folder = rootOf() + '/' + volume.dir
			const dir = currentDir()
			if (dir === folder || dir.startsWith(folder + '/')) {
				goTo(rootOf())
			} else if (isFolder(node)) {
				// The folder of the volume is gone from the list
				emit('files:node:deleted', node)
			}
			return null
		}
		if (result.code === 'busy' && !force) {
			dialog({
				title: t(APP, 'Unmount “{name}”', { name: volume.dir }),
				fields: [el('p', { text: result.message + ' ' + t(APP, 'Unmount it by force? Files still being written may be incomplete.') })],
				submitLabel: t(APP, 'Unmount by force'),
				busyLabel: t(APP, 'Unmounting…'),
				async onSubmit() {
					await unmount(node, true)
					return true
				},
			})
			return null
		}
		toast('error', result.message)
		return null
	}

	const actions = [
		{
			id: 'veracryptbridge-mount',
			displayName: () => t(APP, 'Mount with VeraCrypt'),
			iconSvgInline: () => LOCK,
			order: 60,
			enabled(arg) {
				const node = single(arg)
				return node !== null && isVolumeFile(node) && volumeOf(node) === null
			},
			async exec(arg) {
				const node = single(arg)
				if (node) {
					mountDialog(node)
				}
				return null
			},
		},
		{
			id: 'veracryptbridge-open',
			displayName: () => t(APP, 'Open mounted volume'),
			iconSvgInline: () => FOLDER,
			order: 60,
			enabled(arg) {
				const node = single(arg)
				return node !== null && !isFolder(node) && volumeOf(node) !== null
			},
			async exec(arg) {
				const volume = volumeOf(single(arg))
				if (volume) {
					goTo(rootOf() + '/' + volume.dir)
				}
				return null
			},
		},
		{
			id: 'veracryptbridge-unmount',
			displayName: () => t(APP, 'Unmount VeraCrypt volume'),
			iconSvgInline: () => UNLOCK,
			order: 61,
			enabled(arg) {
				const node = single(arg)
				return node !== null && volumeOf(node) !== null
			},
			async exec(arg) {
				const node = single(arg)
				return node ? unmount(node) : null
			},
		},
	]

	/* ---------- Registration ---------- */

	// v3: an array of actions
	window._nc_fileactions = window._nc_fileactions || []
	// v4: a map of actions in the scope of version 4.0 of @nextcloud/files
	window._nc_files_scope = window._nc_files_scope || {}
	window._nc_files_scope.v4_0 = window._nc_files_scope.v4_0 || {}
	const scope = window._nc_files_scope.v4_0
	scope.fileActions = scope.fileActions || new Map()

	for (const action of actions) {
		if (!window._nc_fileactions.find((a) => a.id === action.id)) {
			window._nc_fileactions.push(action)
		}
		if (!scope.fileActions.has(action.id)) {
			scope.fileActions.set(action.id, action)
			if (scope.registry && scope.registry.dispatchEvent) {
				scope.registry.dispatchEvent(new CustomEvent('register:action', { detail: action }))
			}
		}
	}
})()
