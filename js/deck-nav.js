(function () {
	const IMPORT_LABELS = ['Import board', 'Importer un tableau']

	function importUrl() {
		if (window.OC && typeof OC.generateUrl === 'function') {
			return OC.generateUrl('/apps/deck_trello/')
		}
		return '/apps/deck_trello/'
	}

	function labelFor(sourceText) {
		return sourceText === 'Importer un tableau' ? 'Importer Trello' : 'Import Trello'
	}

	function findImportItem() {
		const names = document.querySelectorAll('#content-vue.app-deck .app-navigation-entry__name')
		for (const name of names) {
			const text = name.textContent.trim()
			if (IMPORT_LABELS.indexOf(text) !== -1) {
				return name.closest('li')
			}
		}
		return null
	}

	function insertLink() {
		const item = findImportItem()
		if (!item || !item.parentElement) {
			return
		}
		const existing = document.getElementById('deck-trello-nav')
		if (existing && existing.previousElementSibling === item) {
			return
		}
		if (existing) {
			existing.remove()
		}

		const sourceText = item.textContent.trim()
		const label = labelFor(sourceText)
		const entry = document.createElement('li')
		entry.id = 'deck-trello-nav'
		entry.className = 'app-navigation-entry-wrapper'
		entry.innerHTML = '<div class="app-navigation-entry"><a class="app-navigation-entry-link" href="' + importUrl() + '"><div class="app-navigation-entry-icon icon-upload"></div><span class="app-navigation-entry__name"></span></a></div>'
		const link = entry.querySelector('a')
		const name = entry.querySelector('.app-navigation-entry__name')
		link.title = label
		name.textContent = label
		copyVueScope(item, entry)
		item.parentElement.insertBefore(entry, item.nextSibling)
	}

	function copyVueScope(source, entry) {
		const names = []
		for (const attr of source.attributes) {
			if (attr.name.indexOf('data-v-') === 0) {
				names.push(attr.name)
			}
		}
		if (names.length === 0) {
			return
		}
		entry.querySelectorAll('*').forEach(function (node) {
			names.forEach(function (name) {
				node.setAttribute(name, '')
			})
		})
		names.forEach(function (name) {
			entry.setAttribute(name, '')
		})
	}

	const observer = new MutationObserver(insertLink)
	observer.observe(document.body, { childList: true, subtree: true })
	insertLink()
})()
