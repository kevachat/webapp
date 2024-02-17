# KevaChat - Chat in Blockchain

KevaChat is distributed chat platform for open, uncensored and privacy respectable communication with permanent data storage in blockchain.

![KevaChat](https://github.com/kevachat/webapp/assets/108541346/9b286719-eafe-443f-a6e3-4b4927edde96)

## Tech

Instance require connection to the [Kevacoin](https://github.com/kevacoin-project/) wallet, `memcached` server, [clitor-is-protocol](https://github.com/clitor-is-protocol) for multimedia support and [Symfony](https://github.com/symfony/symfony) for web interface.

## Model

KevaChat following open wallet model, where community boost shared ballance for talks.

* In another way, node administrators able to provide unique payment addresses to each message sent and charge commission for instance monetization.

Administrators have flexible settings of access levels explained in the `.env` file: read-only rooms, connection and post limits, etc.

## Communication

Everyone able to join the chat, post messages as ghosty or sign ownership by IP. Also users can explore remote rooms by namespaces if option enabled.

Basic social features like identicons, replies, mentions, RSS subscriptions etc are supported.

## Protocol

KevaChat protocol following native Kevacoin's `key`/`value` model, where `key` - is the `timestamp@username` and `value` - is message.

All messages related to their room `namespace`.

## Examples

* `http://[201:23b4:991a:634d:8359:4521:5576:15b7]/kevachat/` - [Yggdrasil](https://github.com/yggdrasil-network/) instance
  * `http://kevachat.ygg` - [Alfis DNS](https://github.com/Revertron/Alfis) alias

## Install

* `apt install git composer memcached sqlite3 php-curl php-memcached php-sqlite3 php-mbstring`
* `git clone https://github.com/kevachat/webapp.git`
* `cd webapp`
* `composer update`
* `php bin/console doctrine:schema:update --force`
* `* * * * * /usr/bin/wget -q --spider http://../crontab/pool > /dev/null 2>&1` - process transactions pool
* `0 0 * * * /usr/bin/wget -q --spider http://../crontab/withdraw > /dev/null 2>&1` - withdraw node profit

## Update

* `cd webapp`
* `git pull`
* `composer update`
* `php bin/console doctrine:migrations:migrate`
* `APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear`

## Setup

Application package contain settings preset, just few steps required to launch:

* Make sure `memcached` server enabled
* Setup Kevacoin server connection with `rpcuser`/`rpcpassword` in `~/.kevacoin/kevacoin.conf`
* Copy `rpcuser` to `env`.`APP_KEVACOIN_USERNAME` and `rpcpassword` to `env`.`APP_KEVACOIN_PASSWORD`
* Generate new address using CLI `kevacoin-cli getnewaddress` and copy to `env`.`APP_KEVACOIN_BOOST_ADDRESS`
* Send few coins to this address and wait for new block to continue
* To allow users registration, create namespace `kevacoin-cli keva_namespace "_KEVACHAT_USERS_"`
* Create at least one room namespace with Web UI or CLI `kevacoin-cli keva_namespace "sandbox"`
* Provide at least one namespace for default chat room to `env`.`APP_KEVACOIN_ROOM_NAMESPACE_DEFAULT` (for homepage redirects)

## Modes

KevaChat supported following `mode` in `GET` requests:

* `stream` - useful for iframe integrations on external websites to create news feed or support chats

## Contribution

Project created by people for people: MIT License to use it for other needs e.g. new fork, chat instance or Kevacoin blockchain explorer.

Join the development and make your feedback!

## See also

* [KevaChat Gemini Application](https://github.com/kevachat/geminiapp)