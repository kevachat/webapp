# KevaChat - Chat in Blockchain

KevaChat is distributed chat platform for open, uncensored and privacy respectable communication with permanent data storage in blockchain.

![KevaChat](https://github.com/kevachat/webapp/assets/108541346/9b286719-eafe-443f-a6e3-4b4927edde96)

## Tech

Instance require connection to the [Kevacoin](https://github.com/kevacoin-project/) wallet, `memcached` server, [clitor-is-protocol](https://github.com/clitor-is-protocol) for multimedia support and [Symfony](https://github.com/symfony/symfony) for web interface.

## Model

KevaChat following open wallet model, where community boost shared ballance for talks.

Administrators have flexible settings of access levels explained in the `.env` file: read-only rooms, connection and post limits, etc.

## Communication

Everyone able to join the chat, post messages as ghosty or sign ownership by IP. Also users can explore remote rooms by namespaces if option enabled.

Basic social features like identicons, replies, mentions, RSS subscriptions etc are supported.

## Protocol

KevaChat protocol following native Kevacoin's `key`/`value` model, where `key` - is the `timestamp@username` and `value` - is message.

All messages related to their room `namespaces`.

## Install

### Production

`composer create-project kevachat/webapp KevaChat`

### Development

* `git clone https://github.com/kevachat/webapp.git KevaChat`
* `cd KevaChat`
* `composer install`

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
