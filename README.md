# Redis Cache for WordPress

> A persistent cache backend powered by Redis.  

[![Build Status](https://travis-ci.com/LeoColomb/wp-redis.svg?branch=master)](https://travis-ci.com/LeoColomb/wp-redis)

## Features

* Enable the two cache wrappers for WordPress
  * Object cache
  * Page cache
* Adds handy [WP-CLI](http://wp-cli.org/) commands
* Supports major PHP Redis drivers 
  * [Predis](https://github.com/nrk/predis/)
  * [PhpRedis (PECL)](https://github.com/phpredis/phpredis)
* Supports replication and clustering


## Installation

* Prepare your Composer file
  ```json
  {
    "extra": {
      "dropin-paths": {
        "web/app/": [
          "package:leocolomb/wp-redis:dropins/object-cache.php",
          "package:leocolomb/wp-redis:dropins/page-cache.php"
        ]
      }
    }
  }
  ```

* Require the package in your Composer-managed WordPress instance
  ```bash
  $ composer require leocolomb/wp-redis
  ```

## Configuration

To adjust the configuration, define any of the following constants in your `wp-config.php` file.

### Connection

By default the object cache drop-in will connect to Redis over TCP at `127.0.0.1:6379` and select database `0`.

Constant name|Default value|Description
--|--|--
`WP_REDIS_CLIENT`|_not set_|Specifies the client used to communicate with Redis. Supports `pecl` and `predis`.
`WP_REDIS_SCHEME`|`tcp`|Specifies the protocol used to communicate with an instance of Redis. Internally the client uses the connection class associated to the specified connection scheme. Supports `tcp` (TCP/IP), `unix` (UNIX domain sockets), `tls` (transport layer security) or `http` (HTTP protocol through Webdis).
`WP_REDIS_HOST`|`127.0.0.1`|IP or hostname of the target server. This is ignored when connecting to Redis using UNIX domain sockets.
`WP_REDIS_PORT`|`6379`|TCP/IP port of the target server. This is ignored when connecting to Redis using UNIX domain sockets.
`WP_REDIS_PATH`| _not set_|Path of the UNIX domain socket file used when connecting to Redis using UNIX domain sockets.
`WP_REDIS_DATABASE`|`0`|Accepts a numeric value that is used to automatically select a logical database with the `SELECT` command.
`WP_REDIS_PASSWORD`|_not set_|Accepts a value used to authenticate with a Redis server protected by password with the `AUTH` command.

### Parameters

Constant name|Default value|Description
--|--|--
`WP_CACHE_KEY_SALT`|_not set_|Set the prefix for all cache keys. Useful in setups where multiple installs share a common `wp-config.php` or `$table_prefix`, to guarantee uniqueness of cache keys.
`WP_REDIS_MAXTTL`|_not set_|Set maximum time-to-live (in seconds) for cache keys with an expiration time of `0`.
`WP_REDIS_GLOBAL_GROUPS`|`['blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts', 'networks', 'rss', 'sites', 'site-details', 'site-lookup', 'site-options', 'site-transient', 'users', 'useremail', 'userlogins', 'usermeta', 'user_meta', 'userslugs']`|Set the list of network-wide cache groups that should not be prefixed with the blog-id _(Multisite only)_.
`WP_REDIS_IGNORED_GROUPS`|`['counts', 'plugins']`|Set the cache groups that should not be cached in Redis.
`WP_REDIS_IGBINARY`|_not set_|Set to `true` to enable the [igbinary](https://github.com/igbinary/igbinary) serializer.

### Page cache

Constant name|Default value|Description
--|--|--
`WP_CACHE`|`false`|Set to `true` to enable advanced page caching. If not set, the Redis page cache will not be used.
`WP_REDIS_TIMES`|`2`|Only cache a page after it is accessed this many times.
`WP_REDIS_SECONDS`|`120`|Only cache a page if it is accessed `$times` in this many seconds. Set to zero to ignore this and use cache immediately.
`WP_REDIS_MAXAGE`|`300`|Expire cache items aged this many seconds. Set to zero to disable cache.
`WP_REDIS_GROUP`|`'redis-cache'`|Name of object cache group used for page cache.
`WP_REDIS_UNIQUE`|`[]`|If you conditionally serve different content, put the variable values here using the `add_variant()` method.
`WP_REDIS_HEADERS`|`[]`|Add headers here as `name => value` or `name => [values]`. These will be sent with every response from the cache.
`WP_REDIS_UNCACHED_HEADERS`|`['transfer-encoding']`|These headers will never be cached. (Use lower case only!)
`WP_REDIS_CACHE_CONTROL`|`true`|Set to `false` to disable `Last-Modified` and `Cache-Control` headers.
`WP_REDIS_USE_STALE`|`true`|Is it ok to return stale cached response when updating the cache?
`WP_REDIS_NOSKIP_COOKIES`|`['wordpress_test_cookie']`|Names of cookies - if they exist and the cache would normally be bypassed, don't bypass it.

## Replication & Clustering

To use Replication and Clustering, make sure your server is running PHP7, your setup is using Predis to connect to Redis and you consulted the [Predis documentation](https://github.com/nrk/predis).

For replication use the `WP_REDIS_SERVERS` constant and for clustering the `WP_REDIS_CLUSTER` constant. You can use a named array or an URI string to specify the parameters.

For authentication use the `WP_REDIS_PASSWORD` constant.

### Master-Slave Replication

```php
define('WP_REDIS_SERVERS', [
    'tcp://127.0.0.1:6379?database=15&alias=master',
    'tcp://127.0.0.2:6379?database=15&alias=slave-01',
]);
```

### Clustering via Client-side Sharding

```php
define('WP_REDIS_CLUSTER', [
    'tcp://127.0.0.1:6379?database=15&alias=node-01',
    'tcp://127.0.0.2:6379?database=15&alias=node-02',
]);
```

## License

GPL-3.0 © [Léo Colombaro](https://colombaro.fr)

* Eric Mann and Erick Hitter for [Redis Object Cache](https://github.com/ericmann/Redis-Object-Cache)
* Till Krüss for [tillkruss/redis-cache](https://github.com/tillkruss/redis-cache)
