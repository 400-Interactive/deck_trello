# Deck Trello Import

Nextcloud app that imports a Trello board into [Deck](https://github.com/nextcloud/deck).

![Screenshot of a UI.](/img/Deck%20Trello%20Import.png)

The import creates a new Deck board, or adds files and comments to a board that was already imported. It runs from the **Import Trello** page and from the `occ deck_trello:import` command.

## What is imported

| Trello | Deck |
| --- | --- |
| List | Stack |
| Card | Card, including archived cards |
| Label | Label, mapped onto the Deck palette |
| Checklist | [Deck Trello-style Checklists](https://github.com/400-Interactive/deckchecklist) when that app is enabled, otherwise Markdown checkboxes in the description |
| `commentCard` action | Deck comment |
| Member | Deck user when a match exists, otherwise a line in the description |
| Attachment | Deck file when a token is provided, otherwise a link |
| Board description | A card titled “Description” on the first stack |
| Due date | Due date on the card |

Closed lists are skipped unless the option is selected. Deck’s public API cannot archive a stack, so an imported closed list stays an open stack.

Only actions of type `commentCard` become comments. The author stored in Deck is the person who runs the import. The text starts with the Trello author and the date, for example `Nathan (2024-03-01 12:00):`.

A member whose Trello username matches a Nextcloud uid is assigned automatically. The others are matched after Preview: each Trello member is shown next to a Nextcloud user picker, including the avatar. The signed-in account appears in that list. The submitted mapping has the form `trelloUsername=nextcloudUid`. A member without a match stays listed on the card as `Membres Trello : …`.

## JSON file or API

The JSON file is Trello’s board export. That export contains only the **latest 1000 actions**. Cards, lists, and attachments are not cut by this limit, but older comments can be missing.

A board address such as `https://trello.com/b/xxxxxxxx` goes through the Trello API. The app loads the board, then pages through every `commentCard` comment. The history is no longer limited to the latest 1000 actions.

## API key and token

Trello requires both. The key identifies the Power-Up. The token identifies the person who allows read access.

The key is stored once for this Nextcloud:

```bash
occ config:app:set deck_trello api_key --value="THE_API_KEY"
```

The import page does not ask for it again. **Create a read token** opens the Trello authorization with that key, read-only, for **1 day**. Trello only accepts `1hour`, `1day`, `30days`, and `never`. The token is used for that import and is not stored.

**Use another API key** stays collapsed. It is only there to paste a different key for one import.

To get the key, create a Power-Up at [trello.com/power-ups/admin/new](https://trello.com/power-ups/admin/new). Power-Up features are not used. Then open `https://trello.com/power-ups/{id}/edit/api-key` and copy the **API key** field. The secret key does not work as a read token.

Without a key and a token, a JSON import still works. Trello files are kept as links.

## Attachments

Files hosted by Trello are downloaded and attached to the card before it is archived. Authorization is sent in the `Authorization` header, because the download address rejects the key in the URL. One file is at most 32 MB, and one import is at most 200 MB in total. The local name includes the Trello id, so several files named `image.png` do not overwrite each other.

## A board that was already imported

**Add the files and comments to an existing Deck board** does not wipe the board. It finds cards by title and adds the missing files and comments. A checklist whose title already exists is not created again.

## From Deck

When Deck is open, **Import Trello** appears in its sidebar, under the board import entry.

## Command

```bash
occ deck_trello:import export.json --user=admin
occ deck_trello:import --trello-board="https://trello.com/b/xxxxxxxx" --trello-token="TOKEN" --user=admin
occ deck_trello:import export.json --user=admin --board=5 --trello-token="TOKEN"
```

Useful options:

- `--user`: Nextcloud uid that owns the board
- `--map-user=trello=nextcloud`, repeatable, or `--map-file`
- `--include-closed-lists`
- `--use-deck-checklist` when [Deck Trello-style Checklists](https://github.com/400-Interactive/deckchecklist) is enabled
- `--trello-key` to replace the stored key
- `--trello-token`
- `--trello-board`: board address or id
- `--board`: id of an existing Deck board

## Limits

- Card title: 255 characters. Board, stack, and label titles: 100 characters.
- Comment: 1000 characters.
- API response for one board: 40 MB.
- Two labels with the same name are kept apart. An empty label title uses the Trello color name.

## Installation

Copy this folder into `custom_apps/deck_trello`, then:

```bash
occ app:enable deck_trello
occ upgrade
```

Nextcloud 30 to 35, PHP 8.1 or newer, and the Deck app. [Deck Trello-style Checklists](https://github.com/400-Interactive/deckchecklist) is optional.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

## Licence

AGPL-3.0-or-later.
