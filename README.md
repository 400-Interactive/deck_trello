# Deck Trello Import

Application Nextcloud qui importe un tableau Trello dans [Deck](https://github.com/nextcloud/deck).

L’import crée un nouveau tableau Deck, ou ajoute les fichiers et les commentaires à un tableau déjà importé. Il se lance depuis la page **Import Trello** et depuis la commande `occ deck_trello:import`.

## Ce qui est repris

| Trello | Deck |
| --- | --- |
| Liste | Pile |
| Carte | Carte, y compris les cartes archivées |
| Étiquette | Étiquette, rapprochée de la palette Deck |
| Checklist | Deck Checklists si l’application est activée, sinon cases Markdown dans la description |
| Commentaire `commentCard` | Commentaire Deck |
| Membre | Utilisateur Deck si une correspondance existe, sinon une ligne dans la description |
| Pièce jointe | Fichier Deck si un jeton est fourni, sinon un lien |
| Description du tableau | Carte « Description » sur la première pile |
| Date de début et échéance | Dates de la carte |

Les listes fermées sont ignorées, sauf si l’option est cochée. Deck ne permet pas d’archiver une pile par son API publique, donc une liste fermée importée reste une pile ouverte.

Seules les actions de type `commentCard` deviennent des commentaires. L’auteur enregistré dans Deck est la personne qui lance l’import. Le texte commence par l’auteur Trello et la date, par exemple `Nathan (2024-03-01 12:00):`.

Un membre dont l’identifiant Trello est identique à un uid Nextcloud est assigné automatiquement. Les autres se règlent après l’aperçu : chaque membre Trello est proposé avec un choix d’utilisateur Nextcloud, avatar compris. Le compte connecté apparaît dans cette liste. La correspondance envoyée a la forme `utilisateurTrello=uidNextcloud`. Un membre sans correspondance reste indiqué sur la carte.

## Fichier JSON ou API

Le fichier JSON est l’export Trello du tableau. Cet export ne contient que les **1000 dernières actions**. Les cartes, les listes et les pièces jointes ne sont pas coupées par cette limite, mais des commentaires plus anciens peuvent manquer.

L’adresse du tableau, de la forme `https://trello.com/b/xxxxxxxx`, passe par l’API Trello. L’application lit le tableau, puis pagine tous les commentaires `commentCard`. L’historique n’est donc plus limité aux 1000 dernières actions.

## Clé API et jeton

Trello demande les deux. La clé identifie le Power-Up. Le jeton identifie la personne qui autorise la lecture.

La clé est enregistrée une fois pour ce Nextcloud :

```bash
occ config:app:set deck_trello api_key --value="LA_CLE_API"
```

La page d’import ne la redemande pas. **Créer un jeton de lecture** ouvre l’autorisation Trello avec cette clé, en lecture seule, pour **1 jour**. Trello n’accepte que `1hour`, `1day`, `30days` et `never`. Le jeton sert à cet import et n’est pas enregistré.

**Use another API key** reste replié. Il sert seulement à coller une autre clé pour un import précis.

Pour obtenir la clé : créer un Power-Up sur [trello.com/power-ups/admin/new](https://trello.com/power-ups/admin/new). Les fonctionnalités du Power-Up ne sont pas utilisées. Ouvrir ensuite `https://trello.com/power-ups/{id}/edit/api-key` et copier le champ **API key**. La clé secrète ne fonctionne pas comme jeton de lecture.

Sans clé ni jeton, un import JSON reste possible. Les fichiers Trello sont alors conservés comme des liens.

## Pièces jointes

Les fichiers hébergés par Trello sont téléchargés puis ajoutés à la carte avant son archivage. L’autorisation est envoyée dans l’en-tête `Authorization`, car l’adresse de téléchargement refuse la clé dans l’URL. Un fichier fait au plus 32 Mo, et l’ensemble d’un import au plus 200 Mo. Le nom local contient l’identifiant Trello, pour que plusieurs fichiers nommés `image.png` ne s’écrasent pas.

## Tableau déjà importé

La case **Add the files and comments to an existing Deck board** n’efface pas le tableau. Elle retrouve les cartes par leur titre et y ajoute les fichiers et les commentaires manquants. Une checklist dont le titre existe déjà n’est pas recréée.

## Depuis Deck

Quand Deck est ouvert, **Import Trello** apparaît dans sa barre latérale, sous l’import de tableau.

## Commande

```bash
occ deck_trello:import export.json --user=admin
occ deck_trello:import --trello-board="https://trello.com/b/xxxxxxxx" --trello-token="JETON" --user=admin
occ deck_trello:import export.json --user=admin --board=5 --trello-token="JETON"
```

Options utiles :

- `--user` : uid Nextcloud propriétaire du tableau
- `--map-user=trello=nextcloud`, répétable, ou `--map-file`
- `--include-closed-lists`
- `--use-deck-checklist` si Deck Checklists est activée
- `--trello-key` pour remplacer la clé enregistrée
- `--trello-token`
- `--trello-board` : adresse ou identifiant du tableau
- `--board` : identifiant d’un tableau Deck existant

## Limites

- Titre de carte : 255 caractères. Titres de tableau, de pile et d’étiquette : 100 caractères.
- Commentaire : 1000 caractères.
- Réponse de l’API pour un tableau : 40 Mo.
- Deux étiquettes de même nom sont distinguées. Un titre d’étiquette vide reprend le nom de la couleur Trello.

## Installation

Copier ce dossier dans `custom_apps/deck_trello`, puis :

```bash
occ app:enable deck_trello
occ upgrade
```

Nextcloud 30 à 35, PHP 8.1 ou plus, et l’application Deck. Deck Checklists est facultative.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

## Licence

AGPL-3.0-or-later.
