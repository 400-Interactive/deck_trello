(function () {
	const root = document.getElementById('deck-trello')
	if (!root) {
		return
	}

	const form = document.getElementById('deck-trello-form')
	const previewButton = document.getElementById('deck-trello-preview')
	const importButton = document.getElementById('deck-trello-import')
	const status = document.getElementById('deck-trello-status')
	const previewSection = document.getElementById('deck-trello-preview-result')
	const importSection = document.getElementById('deck-trello-import-result')
	const labels = JSON.parse(root.dataset.labels)

	function setStatus(message, isError) {
		status.hidden = false
		status.textContent = message
		status.classList.toggle('deck-trello__status--error', !!isError)
	}

	function fillCounts(list, data, fields) {
		list.replaceChildren()
		fields.forEach(function (field) {
			const term = document.createElement('dt')
			term.textContent = field.label
			const value = document.createElement('dd')
			const raw = data[field.key]
			value.textContent = Array.isArray(raw) ? (raw.join(', ') || '—') : String(raw)
			list.appendChild(term)
			list.appendChild(value)
		})
	}

	function fillWarnings(list, warnings) {
		list.replaceChildren()
		;(warnings || []).forEach(function (warning) {
			const item = document.createElement('li')
			item.textContent = warning
			list.appendChild(item)
		})
	}

	const keyInput = form.querySelector('input[name="trelloKey"]')
	const authLink = document.getElementById('deck-trello-auth')
	const attachBox = form.querySelector('input[name="attachExisting"]')
	const boardField = document.getElementById('deck-trello-board-field')

	function activeKey() {
		const typed = keyInput.value.trim()
		if (typed !== '') {
			return typed
		}
		return (root.dataset.apiKey || '').trim()
	}

	function refreshAuth() {
		const base = 'https://trello.com/1/authorize?expiration=1day&name=Nextcloud%20Deck&scope=read&response_type=token'
		const key = activeKey()
		authLink.href = key === '' ? base : base + '&key=' + encodeURIComponent(key)
	}

	function refreshBoard() {
		boardField.hidden = !attachBox.checked
	}

	keyInput.addEventListener('input', refreshAuth)
	attachBox.addEventListener('change', refreshBoard)
	refreshAuth()
	refreshBoard()

	function payload(includeSecrets) {
		const data = new FormData(form)
		if (!data.get('includeClosedLists')) {
			data.set('includeClosedLists', '0')
		}
		const boardUrl = (data.get('trelloBoard') || '').toString().trim()
		if (!includeSecrets && boardUrl === '') {
			data.delete('trelloKey')
			data.delete('trelloToken')
			data.delete('attachExisting')
			data.delete('boardId')
		}
		return data
	}

	function request(url, includeSecrets) {
		setStatus(labels.working, false)
		previewButton.disabled = true
		importButton.disabled = true
		return fetch(url, {
			method: 'POST',
			headers: {
				requesttoken: OC.requestToken,
			},
			body: payload(includeSecrets),
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					throw new Error(body.message || labels.failed)
				}
				return body
			})
		}).finally(function () {
			previewButton.disabled = false
		})
	}

	const previewFields = [
		{ key: 'board', label: labels.board },
		{ key: 'lists', label: labels.lists },
		{ key: 'cards', label: labels.cards },
		{ key: 'archivedCards', label: labels.archivedCards },
		{ key: 'labels', label: labels.labels },
		{ key: 'checklists', label: labels.checklists },
		{ key: 'comments', label: labels.comments },
		{ key: 'attachments', label: labels.attachments },
		{ key: 'members', label: labels.members },
		{ key: 'skippedCards', label: labels.skippedCards },
	]

	const matchBox = document.getElementById('deck-trello-matches')
	const matchList = document.getElementById('deck-trello-match-list')
	const userMap = form.querySelector('textarea[name="userMap"]')

	function avatarUrl(uid) {
		return OC.generateUrl('/avatar/{user}/32', { user: uid })
	}

	function syncUserMap() {
		const lines = []
		matchList.querySelectorAll('[data-trello-user]').forEach(function (row) {
			const uid = row.dataset.nextcloudUid || ''
			if (uid !== '') {
				lines.push(row.dataset.trelloUser + '=' + uid)
			}
		})
		userMap.value = lines.join('\n')
	}

	function closePickers(except) {
		matchList.querySelectorAll('.deck-trello__picker-panel').forEach(function (panel) {
			if (panel !== except) {
				panel.hidden = true
			}
		})
	}

	function renderSelected(toggle, uid, label) {
		toggle.replaceChildren()
		if (!uid) {
			toggle.textContent = labels.chooseUser
			return
		}
		const img = document.createElement('img')
		img.alt = ''
		img.src = avatarUrl(uid)
		const text = document.createElement('span')
		text.textContent = label || uid
		toggle.appendChild(img)
		toggle.appendChild(text)
	}

	function selectUser(row, toggle, uid, label) {
		row.dataset.nextcloudUid = uid
		renderSelected(toggle, uid, label)
		syncUserMap()
		closePickers(null)
	}

	function showUsers(list, row, toggle, users) {
		list.replaceChildren()
		if (!users.length) {
			const empty = document.createElement('li')
			empty.textContent = labels.noUser
			list.appendChild(empty)
			return
		}
		users.forEach(function (user) {
			const item = document.createElement('li')
			const button = document.createElement('button')
			button.type = 'button'
			button.className = 'deck-trello__user'
			const img = document.createElement('img')
			img.alt = ''
			img.src = avatarUrl(user.id)
			const text = document.createElement('span')
			text.textContent = user.label || user.id
			const uid = document.createElement('small')
			uid.textContent = user.id
			text.appendChild(uid)
			button.appendChild(img)
			button.appendChild(text)
			button.addEventListener('click', function () {
				selectUser(row, toggle, user.id, user.label || user.id)
			})
			item.appendChild(button)
			list.appendChild(item)
		})
	}

	function currentUser() {
		if (!OC.getCurrentUser) {
			return null
		}
		const user = OC.getCurrentUser()
		if (!user || !user.uid) {
			return null
		}
		return { id: user.uid, label: user.displayName || user.uid, source: 'users' }
	}

	function includeCurrentUser(users, query) {
		const me = currentUser()
		if (!me) {
			return users
		}
		const needle = query.toLowerCase()
		const matches = needle === '' || me.id.toLowerCase().indexOf(needle) !== -1 || (me.label || '').toLowerCase().indexOf(needle) !== -1
		if (!matches || users.some(function (user) { return user.id === me.id })) {
			return users
		}
		return [me].concat(users)
	}

	function searchUsers(query, list, row, toggle, done) {
		const url = OC.generateUrl('/ocs/v2.php/core/autocomplete/get')
			+ '?format=json&itemType=&shareTypes[]=0&limit=20&search=' + encodeURIComponent(query)
		fetch(url, {
			headers: {
				requesttoken: OC.requestToken,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		}).then(function (response) {
			return response.json()
		}).then(function (body) {
			const users = includeCurrentUser(((body && body.ocs && body.ocs.data) || []).filter(function (user) {
				return user && user.id && user.source !== 'remotes'
			}), query)
			showUsers(list, row, toggle, users)
			if (done) {
				done(users)
			}
		}).catch(function () {
			showUsers(list, row, toggle, [])
			if (done) {
				done([])
			}
		})
	}

	function renderMatches(members) {
		const previous = {}
		matchList.querySelectorAll('[data-trello-user]').forEach(function (row) {
			if (row.dataset.nextcloudUid) {
				previous[row.dataset.trelloUser] = {
					uid: row.dataset.nextcloudUid,
					label: row.dataset.nextcloudLabel || row.dataset.nextcloudUid,
				}
			}
		})
		matchList.replaceChildren()
		if (!members.length) {
			matchBox.hidden = true
			userMap.value = ''
			return
		}
		matchBox.hidden = false
		members.forEach(function (member) {
			const username = member.username || member
			const fullName = member.fullName || ''
			const row = document.createElement('div')
			row.className = 'deck-trello__match'
			row.dataset.trelloUser = username
			const who = document.createElement('div')
			who.className = 'deck-trello__match-trello'
			const name = document.createElement('strong')
			name.textContent = fullName !== '' && fullName !== username ? fullName : username
			const handle = document.createElement('span')
			handle.textContent = '@' + username
			who.appendChild(name)
			who.appendChild(handle)

			const picker = document.createElement('div')
			picker.className = 'deck-trello__picker'
			const toggle = document.createElement('button')
			toggle.type = 'button'
			toggle.className = 'deck-trello__picker-toggle'
			renderSelected(toggle, '', '')
			const panel = document.createElement('div')
			panel.className = 'deck-trello__picker-panel'
			panel.hidden = true
			const search = document.createElement('input')
			search.type = 'search'
			search.placeholder = labels.searchUsers
			search.setAttribute('aria-label', labels.searchUsers)
			const results = document.createElement('ul')
			results.className = 'deck-trello__picker-results'
			const clear = document.createElement('button')
			clear.type = 'button'
			clear.className = 'deck-trello__user'
			clear.textContent = labels.clearUser
			clear.addEventListener('click', function () {
				selectUser(row, toggle, '', '')
			})
			panel.appendChild(search)
			panel.appendChild(results)
			panel.appendChild(clear)
			picker.appendChild(toggle)
			picker.appendChild(panel)
			row.appendChild(who)
			row.appendChild(picker)
			matchList.appendChild(row)

			let timer = 0
			toggle.addEventListener('click', function () {
				const open = panel.hidden
				closePickers(panel)
				panel.hidden = !open
				if (open) {
					search.focus()
					searchUsers(search.value.trim(), results, row, toggle)
				}
			})
			search.addEventListener('input', function () {
				clearTimeout(timer)
				timer = setTimeout(function () {
					searchUsers(search.value.trim(), results, row, toggle)
				}, 200)
			})

			const kept = previous[username]
			if (kept) {
				row.dataset.nextcloudLabel = kept.label
				selectUser(row, toggle, kept.uid, kept.label)
				return
			}
			searchUsers(username, results, row, toggle, function (users) {
				const exact = users.find(function (user) {
					return user.id.toLowerCase() === username.toLowerCase()
				})
				if (exact && !row.dataset.nextcloudUid) {
					row.dataset.nextcloudLabel = exact.label || exact.id
					selectUser(row, toggle, exact.id, exact.label || exact.id)
				}
			})
		})
		syncUserMap()
	}

	document.addEventListener('click', function (event) {
		if (!matchList.contains(event.target)) {
			closePickers(null)
		}
	})

	previewButton.addEventListener('click', function () {
		importSection.hidden = true
		request(root.dataset.previewUrl, false).then(function (body) {
			status.hidden = true
			previewSection.hidden = false
			fillCounts(document.getElementById('deck-trello-counts'), body, previewFields)
			fillWarnings(document.getElementById('deck-trello-warnings'), body.warnings)
			const members = Array.isArray(body.trelloMembers) && body.trelloMembers.length
				? body.trelloMembers
				: (body.members || []).map(function (username) { return { username: username, fullName: '' } })
			renderMatches(members)
			importButton.disabled = false
		}).catch(function (error) {
			previewSection.hidden = true
			importButton.disabled = true
			setStatus(error.message, true)
		})
	})

	importButton.addEventListener('click', function () {
		request(root.dataset.importUrl, true).then(function (body) {
			status.hidden = true
			importSection.hidden = false
			fillCounts(document.getElementById('deck-trello-import-counts'), body, [
				{ key: 'boardId', label: labels.boardId },
				{ key: 'boardTitle', label: labels.board },
				{ key: 'stacks', label: labels.lists },
				{ key: 'cards', label: labels.cards },
				{ key: 'archivedCards', label: labels.archivedCards },
				{ key: 'labels', label: labels.labels },
				{ key: 'comments', label: labels.comments },
				{ key: 'assignedUsers', label: labels.assignments },
				{ key: 'attachmentsImported', label: labels.attachmentsImported },
				{ key: 'skippedCards', label: labels.skippedCards },
			])
			fillWarnings(document.getElementById('deck-trello-import-warnings'), body.warnings)
			importButton.disabled = false
		}).catch(function (error) {
			importButton.disabled = false
			setStatus(error.message, true)
		})
	})
})()
