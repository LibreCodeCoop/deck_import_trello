# Import Trello Boards into Nextcloud Deck App

Ported to PHP from project [mclang/nextcloud-deck-import-trello-json](https://github.com/mclang/nextcloud-deck-import-trello-json)

Simple script that can be used to import
[Trello](https://trello.com/) boards from exported JSON files into [Nextcloud](https://nextcloud.com)'s
[Deck](https://apps.nextcloud.com/apps/deck) app using Nextcloud Deck app
[API](https://github.com/nextcloud/deck/blob/master/docs/API.md).

To import:
* Copy file `config.tmpl` to config.json
* Put API access data on `config.json`
* Put all `json ` files exported from Trello boards on `data` directory
* Run `php import_trello_json_to_NC_Deck.php`