<?php
script('deck_trello', 'import');
style('deck_trello', 'style');
?>

<div id="app-content">
	<div id="deck-trello" class="deck-trello"
		data-preview-url="<?php p($_['previewUrl']); ?>"
		data-import-url="<?php p($_['importUrl']); ?>"
		data-api-key="<?php p($_['apiKey']); ?>"
		data-labels="<?php p(json_encode([
			'board' => $l->t('Board'),
			'lists' => $l->t('Lists'),
			'cards' => $l->t('Cards'),
			'archivedCards' => $l->t('Archived cards'),
			'labels' => $l->t('Labels'),
			'checklists' => $l->t('Checklists'),
			'comments' => $l->t('Comments'),
			'attachments' => $l->t('Attachments'),
			'members' => $l->t('Members'),
			'skippedCards' => $l->t('Skipped cards'),
			'boardId' => $l->t('Deck board id'),
			'assignments' => $l->t('Assignments'),
			'attachmentsImported' => $l->t('Downloaded files'),
			'working' => $l->t('Working…'),
			'failed' => $l->t('Import failed.'),
			'matchMembers' => $l->t('Match Trello members'),
			'searchUsers' => $l->t('Search Nextcloud users'),
			'chooseUser' => $l->t('Choose a Nextcloud user'),
			'noUser' => $l->t('No Nextcloud user'),
			'clearUser' => $l->t('Clear'),
		], JSON_UNESCAPED_UNICODE)); ?>">
		<div class="deck-trello__title-row">
			<h2><?php p($l->t('Import a Trello board')); ?></h2>
			<a class="deck-trello__link-button" href="https://github.com/400-Interactive/deck_trello#readme" target="_blank" rel="noopener noreferrer"><?php p($l->t('GitHub / Docs')); ?></a>
		</div>
		<p class="deck-trello__intro">
			<?php p($l->t('Choose a Trello JSON export, or a board URL with an API key. The JSON export keeps only the latest 1000 actions. The API imports the full comment history.')); ?>
		</p>
		<form id="deck-trello-form">
			<label class="deck-trello__field">
				<span><?php p($l->t('Trello board URL')); ?></span>
				<input type="url" name="trelloBoard" placeholder="https://trello.com/b/abcdefgh" spellcheck="false">
			</label>
			<label class="deck-trello__field">
				<span><?php p($l->t('Or Trello JSON file')); ?></span>
				<input type="file" name="file" accept="application/json,.json">
			</label>
			<label class="deck-trello__check">
				<input type="checkbox" name="includeClosedLists" value="1">
				<span><?php p($l->t('Include closed lists')); ?></span>
			</label>
			<?php if (!empty($_['deckChecklist'])): ?>
			<label class="deck-trello__check">
				<input type="checkbox" name="useDeckChecklist" value="1" checked>
				<span><?php p($l->t('Import checklists into Deck Checklists instead of the card description')); ?></span>
			</label>
			<?php endif; ?>
			<fieldset class="deck-trello__files">
				<legend><?php p($l->t('Trello API')); ?></legend>
				<p class="deck-trello__intro">
					<?php p($l->t('Trello files are private. Create a read token to download them into Deck. This Nextcloud already has an API key. Without a token, the import keeps the links only.')); ?>
				</p>
				<div class="deck-trello__field">
					<span class="deck-trello__label-row">
						<span><?php p($l->t('Trello token')); ?></span>
						<a id="deck-trello-auth" class="deck-trello__link-button" href="https://trello.com/1/authorize?expiration=1day&amp;name=Nextcloud%20Deck&amp;scope=read&amp;response_type=token" target="_blank" rel="noopener noreferrer"><?php p($l->t('Create a read token')); ?></a>
					</span>
					<input type="password" name="trelloToken" autocomplete="off" spellcheck="false">
					<small><?php p($l->t('The token is used only for this import and is not stored.')); ?></small>
				</div>
				<details class="deck-trello__key">
					<summary><?php p($l->t('Use another API key')); ?></summary>
					<div class="deck-trello__field">
						<span class="deck-trello__label-row">
							<span><?php p($l->t('Trello API key')); ?></span>
							<a class="deck-trello__link-button" href="https://trello.com/power-ups/admin/new" target="_blank" rel="noopener noreferrer"><?php p($l->t('Create an API key')); ?></a>
						</span>
						<input type="text" name="trelloKey" autocomplete="off" spellcheck="false">
						<small><?php p($l->t('Power-Up features are not needed. Open trello.com/power-ups/{id}/edit/api-key and copy “API key”.')); ?></small>
					</div>
				</details>
				<label class="deck-trello__check">
					<input type="checkbox" name="attachExisting" value="1">
					<span><?php p($l->t('Add the files and comments to an existing Deck board instead of creating a new one')); ?></span>
				</label>
				<label class="deck-trello__field" id="deck-trello-board-field" hidden>
					<span><?php p($l->t('Deck board')); ?></span>
					<select name="boardId">
						<?php foreach ($_['boards'] as $board): ?>
							<option value="<?php p((string)$board['id']); ?>"><?php p($board['title']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</fieldset>
			<label class="deck-trello__field" hidden>
				<span><?php p($l->t('User mapping')); ?></span>
				<textarea name="userMap" rows="4" placeholder="kinj14=admin"></textarea>
			</label>
			<div class="deck-trello__actions">
				<button type="button" id="deck-trello-preview"><?php p($l->t('Preview')); ?></button>
				<button type="button" id="deck-trello-import" disabled><?php p($l->t('Import')); ?></button>
			</div>
		</form>
		<p id="deck-trello-status" class="deck-trello__status" hidden></p>
		<section id="deck-trello-preview-result" class="deck-trello__result" hidden>
			<h3><?php p($l->t('Preview')); ?></h3>
			<dl id="deck-trello-counts"></dl>
			<div id="deck-trello-matches" class="deck-trello__matches" hidden>
				<h3><?php p($l->t('Match Trello members')); ?></h3>
				<div id="deck-trello-match-list"></div>
			</div>
			<ul id="deck-trello-warnings"></ul>
		</section>
		<section id="deck-trello-import-result" class="deck-trello__result" hidden>
			<h3><?php p($l->t('Import finished')); ?></h3>
			<dl id="deck-trello-import-counts"></dl>
			<ul id="deck-trello-import-warnings"></ul>
		</section>
	</div>
</div>
