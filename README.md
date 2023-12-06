# KevaChat Web Application

KevaChat is distributed web chat platform for open, uncensored and privacy respectable communication with permanent database storage in blockchain.

![KevaChat](https://github.com/kevachat/webapp/assets/108541346/9b286719-eafe-443f-a6e3-4b4927edde96)

## Tech

Instance require connection to the [Kevacoin](https://github.com/kevacoin-project/) wallet, `memcached` server and [Symfony](https://github.com/symfony/symfony) for web interface.

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

## Contribution

Project created from people and for people, feel free to use it for your own needs, join the development or make your feedback!
