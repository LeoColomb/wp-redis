<?php

/**
 * Core class that implements an object cache.
 *
 * The WordPress Object Cache is used to save on trips to the database. The
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 *
 * @since 2.0.0
 */
class WP_Redis_Object_Cache {

    /**
     * The Redis client.
     *
     * @var mixed
     */
    private $redis;

    /**
     * Track if Redis is available
     *
     * @var bool
     */
    private $redis_connected = false;

    /**
     * Holds the non-Redis objects.
     *
     * @since 2.0.0
     * @var array
     */
    private $cache = array();

    /**
     * Name of the used Redis client
     *
     * @var bool
     */
    public $redis_client = null;

    /**
     * The amount of times the cache data was already stored in the cache.
     *
     * @since 2.5.0
     * @var int
     */
    public $cache_hits = 0;

    /**
     * Amount of times the cache did not have the request in cache.
     *
     * @since 2.0.0
     * @var int
     */
    public $cache_misses = 0;

    /**
     * List of global cache groups.
     *
     * @since 3.0.0
     * @var array
     */
    protected $global_groups = array(
        'blog-details',
        'blog-id-cache',
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'user_meta',
        'userslugs',
    );

    /**
     * List of groups not saved to Redis.
     *
     * @var array
     */
    public $ignored_groups = array( 'counts', 'plugins' );

    /**
     * The blog prefix to prepend to keys in non-global groups.
     *
     * @since 3.5.0
     * @var int
     */
    private $blog_prefix;

    /**
     * Holds the value of is_multisite().
     *
     * @since 3.5.0
     * @var bool
     */
    private $multisite;

    /**
     * Instantiate the Redis class.
     *
     * @param bool $fail_gracefully
     *
     * @throws Exception
     */
    public function __construct( $fail_gracefully = true ) {
        global $table_prefix;

        $parameters = array(
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379
        );

        foreach ( array( 'scheme', 'host', 'port', 'path', 'password', 'database' ) as $setting ) {
            $constant = sprintf( 'WP_REDIS_%s', strtoupper( $setting ) );
            if ( defined( $constant ) ) {
                $parameters[ $setting ] = constant( $constant );
            }
        }

        if ( defined( 'WP_REDIS_GLOBAL_GROUPS' ) && is_array( WP_REDIS_GLOBAL_GROUPS ) ) {
            $this->global_groups = WP_REDIS_GLOBAL_GROUPS;
        }

        if ( defined( 'WP_REDIS_IGNORED_GROUPS' ) && is_array( WP_REDIS_IGNORED_GROUPS ) ) {
            $this->ignored_groups = WP_REDIS_IGNORED_GROUPS;
        }

        $client = defined( 'WP_REDIS_CLIENT' ) ? WP_REDIS_CLIENT : null;

        if ( class_exists( 'Redis' ) && strcasecmp( 'predis', $client ) !== 0 ) {
            $client = defined( 'HHVM_VERSION' ) ? 'hhvm' : 'pecl';
        } else {
            $client = 'predis';
        }

        try {

            if ( strcasecmp( 'hhvm', $client ) === 0 ) {

                $this->redis_client = sprintf( 'HHVM Extension (v%s)', HHVM_VERSION );
                $this->redis = new Redis();

                // Adjust host and port, if the scheme is `unix`
                if ( strcasecmp( 'unix', $parameters[ 'scheme' ] ) === 0 ) {
                    $parameters[ 'host' ] = 'unix://' . $parameters[ 'path' ];
                    $parameters[ 'port' ] = 0;
                }

                $this->redis->connect( $parameters[ 'host' ], $parameters[ 'port' ] );
            }

            if ( strcasecmp( 'pecl', $client ) === 0 ) {

                $this->redis_client = sprintf( 'PhpRedis (v%s)', phpversion( 'redis' ) );

                if ( defined( 'WP_REDIS_SHARDS' ) ) {
                    $this->redis = new RedisArray( array_values( WP_REDIS_CLUSTER ) );
                } elseif ( defined( 'WP_REDIS_CLUSTER' ) ) {
                    $this->redis = new RedisCluster( null, array_values( WP_REDIS_CLUSTER ) );
                } else {
                    $this->redis = new Redis();

                    if ( strcasecmp( 'unix', $parameters[ 'scheme' ] ) === 0 ) {
                        $this->redis->connect( $parameters[ 'path' ] );
                    } else {
                        $this->redis->connect( $parameters[ 'host' ], $parameters[ 'port' ] );
                    }
                }
            }

            if ( strcasecmp( 'pecl', $client ) === 0 || strcasecmp( 'hhvm', $client ) === 0 ) {
                if ( isset( $parameters[ 'password' ] ) ) {
                    $this->redis->auth( $parameters[ 'password' ] );
                }

                if ( isset( $parameters[ 'database' ] ) ) {
                    $this->redis->select( $parameters[ 'database' ] );
                }
            }

            if ( strcasecmp( 'predis', $client ) === 0 ) {

                $this->redis_client = 'Predis';

                // Require PHP 5.4 or greater
                if ( version_compare( PHP_VERSION, '5.4.0', '<' ) ) {
                    throw new Exception;
                }

                // Load bundled Predis library
                if ( ! class_exists( 'Predis\Client' ) ) {
                    require_once __DIR__ . '../vendor/autoload.php';
                    Predis\Autoloader::register();
                }

                $options = array();

                if ( defined( 'WP_REDIS_SHARDS' ) ) {
                    $parameters = WP_REDIS_SHARDS;
                } elseif ( defined( 'WP_REDIS_SENTINEL' ) ) {
                    $parameters = WP_REDIS_SERVERS;
                    $options[ 'replication' ] = 'sentinel';
                    $options[ 'service' ] = WP_REDIS_SENTINEL;
                } elseif ( defined( 'WP_REDIS_SERVERS' ) ) {
                    $parameters = WP_REDIS_SERVERS;
                    $options[ 'replication' ] = true;
                } elseif ( defined( 'WP_REDIS_CLUSTER' ) ) {
                    $parameters = WP_REDIS_CLUSTER;
                    $options[ 'cluster' ] = 'redis';
                }

                foreach ( array( 'WP_REDIS_SERVERS', 'WP_REDIS_SHARDS', 'WP_REDIS_CLUSTER' ) as $constant ) {
                    if ( defined( 'WP_REDIS_PASSWORD' ) && defined( $constant ) ) {
                        $options[ 'parameters' ][ 'password' ] = WP_REDIS_PASSWORD;
                    }
                }

                $this->redis = new Predis\Client( $parameters, $options );
                $this->redis->connect();

                $this->redis_client .= sprintf( ' (v%s)', Predis\Client::VERSION );

            }

            // Throws exception if Redis is unavailable
            $this->redis->ping();

            $this->redis_connected = true;

        } catch ( Exception $exception ) {

            // When Redis is unavailable, fall back to the internal back by forcing all groups to be "no redis" groups
            $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $this->global_groups ) );

            $this->redis_connected = false;

            if ( ! $fail_gracefully ) {
                throw $exception;
            }

        }

        // Assign global and blog prefixes for use with keys
        if ( function_exists( 'is_multisite' ) ) {
            $this->multisite   = is_multisite();
        }

        $this->global_prefix = ( $this->multisite || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) ) ? '' : $table_prefix;
        $this->blog_prefix = $this->multisite ? get_current_blog_id() : $table_prefix;
    }

    /**
     * Is Redis available?
     *
     * @return bool
     */
    public function redis_status() {
        return $this->redis_connected;
    }

    /**
     * Returns the Redis instance.
     *
     * @return mixed
     */
    public function redis_instance() {
        return $this->redis;
    }

    /**
     * Adds data to the cache if it doesn't already exist.
     *
     * @since 2.0.0
     *
     * @uses WP_Object_Cache::_exists() Checks to see if the cache already has data.
     * @uses WP_Object_Cache::set()     Sets the data after the checking the cache
     *                                  contents existence.
     *
     * @param int|string $key    What to call the contents in the cache.
     * @param mixed      $data   The contents to store in the cache.
     * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
     * @param int        $expire Optional. When to expire the cache contents. Default 0 (no expiration).
     * @return bool False if cache key and group already exist, true on success
     */
    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        return $this->add_or_replace( true, $key, $data, $group, (int) $expire );
    }

    /**
     * Replaces the contents in the cache, if contents already exist.
     *
     * @since 2.0.0
     *
     * @see WP_Object_Cache::set()
     *
     * @param int|string $key    What to call the contents in the cache.
     * @param mixed      $data   The contents to store in the cache.
     * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
     * @param int        $expire Optional. When to expire the cache contents. Default 0 (no expiration).
     * @return bool False if not exists, true if contents were replaced.
     */
    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        return $this->add_or_replace( false, $key, $data, $group, (int) $expire );
    }

    /**
     * Add or replace a value in the cache.
     *
     * Add does not set the value if the key exists; replace does not replace if the value doesn't exist.
     *
     * @param   bool   $add            True if should only add if value doesn't exist, false to only add when value already exists
     * @param   string $key            The key under which to store the value.
     * @param   mixed  $value          The value to store.
     * @param   string $group          The group value appended to the $key.
     * @param   int    $expiration     The expiration time, defaults to 0.
     * @return  bool                   Returns TRUE on success or FALSE on failure.
     */
    protected function add_or_replace( $add, $key, $value, $group = 'default', $expiration = 0 ) {
        $result = true;
        $derived_key = $this->build_key( $key, $group );

        // save if group not excluded and redis is up
        if ( ! in_array( $group, $this->ignored_groups ) && $this->redis_status() ) {
            $exists = $this->redis->exists( $derived_key );

            if ( $add == $exists ) {
                return false;
            }

            $expiration = $this->validate_expiration( $expiration );

            if ( $expiration ) {
                $result = $this->parse_redis_response( $this->redis->setex( $derived_key, $expiration, $this->maybe_serialize( $value ) ) );
            } else {
                $result = $this->parse_redis_response( $this->redis->set( $derived_key, $this->maybe_serialize( $value ) ) );
            }
        }

        $exists = isset( $this->cache[ $derived_key ] );

        if ( $add == $exists ) {
            return false;
        }

        if ( $result ) {
            $this->add_to_internal_cache( $derived_key, $value );
        }

        return $result;
    }

    /**
     * Removes the contents of the cache key in the group.
     *
     * If the cache key does not exist in the group, then nothing will happen.
     *
     * @since 2.0.0
     *
     * @param int|string $key        What the contents in the cache are called.
     * @param string     $group      Optional. Where the cache contents are grouped. Default 'default'.
     * @param bool       $deprecated Optional. Unused. Default false.
     * @return bool False if the contents weren't deleted and true on success.
     */
    public function delete( $key, $group = 'default', $deprecated = false ) {
        $result = false;
        $derived_key = $this->build_key( $key, $group );

        if ( isset( $this->cache[ $derived_key ] ) ) {
            unset( $this->cache[ $derived_key ] );
            $result = true;
        }

        if ( $this->redis_status() && ! in_array( $group, $this->ignored_groups ) ) {
            $result = $this->parse_redis_response( $this->redis->del( $derived_key ) );
        }

        if ( function_exists( 'do_action' ) ) {
            do_action( 'redis_object_cache_delete', $key, $group );
        }

        return $result;
    }

    /**
     * Clears the object cache of all data.
     *
     * @since 2.0.0
     *
     * @return true Always returns true.
     */
    public function flush() {
        $result = false;
        $this->cache = array();

        if ( $this->redis_status() ) {
            $salt = defined( 'WP_CACHE_KEY_SALT' ) ? trim( WP_CACHE_KEY_SALT ) : null;
            $selective = defined( 'WP_REDIS_SELECTIVE_FLUSH' ) ? WP_REDIS_SELECTIVE_FLUSH : null;

            if ( $salt && $selective ) {
                $script = "
                    local i = 0
                    for _,k in ipairs(redis.call('keys', '{$salt}*')) do
                        redis.call('del', k)
                        i = i + 1
                    end
                    return i
                ";

                $result = $this->parse_redis_response( $this->redis->eval(
                    $script,
                    $this->redis instanceof Predis\Client ? 0 : []
                ) );
            } else {
                $result = $this->parse_redis_response( $this->redis->flushdb() );
            }

            if ( function_exists( 'do_action' ) ) {
                do_action( 'redis_object_cache_flush', $result, $selective, $salt );
            }
        }

        return $result;
    }

    /**
     * Retrieves the cache contents, if it exists.
     *
     * The contents will be first attempted to be retrieved by searching by the
     * key in the cache group. If the cache is hit (success) then the contents
     * are returned.
     *
     * On failure, the number of cache misses will be incremented.
     *
     * @since 2.0.0
     *
     * @param int|string $key    What the contents in the cache are called.
     * @param string     $group  Optional. Where the cache contents are grouped. Default 'default'.
     * @param string     $force  Optional. Unused. Whether to force a refetch rather than relying on the local
     *                           cache. Default false.
     * @param bool        $found  Optional. Whether the key was found in the cache (passed by reference).
     *                            Disambiguates a return of false, a storable value. Default null.
     * @return false|mixed False on failure to retrieve contents or the cache contents on success.
     */
    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        $derived_key = $this->build_key( $key, $group );

        if ( isset( $this->cache[ $derived_key ] ) && ! $force ) {
            $found = true;
            $this->cache_hits++;

            return is_object( $this->cache[ $derived_key ] ) ? clone $this->cache[ $derived_key ] : $this->cache[ $derived_key ];
        } elseif ( in_array( $group, $this->ignored_groups ) || ! $this->redis_status() ) {
            $found = false;
            $this->cache_misses++;

            return false;
        }

        $result = $this->redis->get( $derived_key );

        if ( $result === null || $result === false ) {
            $found = false;
            $this->cache_misses++;

            return false;
        } else {
            $found = true;
            $this->cache_hits++;
            $value = $this->maybe_unserialize( $result );
        }

        $this->add_to_internal_cache( $derived_key, $value );

        $value = is_object( $value ) ? clone $value : $value;

        if ( function_exists( 'do_action' ) ) {
            do_action( 'redis_object_cache_get', $key, $value, $group, $force, $found );
        }

        if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) ) {
            if ( has_filter( 'redis_object_cache_get' ) ) {
                return apply_filters( 'redis_object_cache_get', $value, $key, $group, $force, $found );
            }
        }

        return $value;
    }

    /**
     * Retrieve multiple values from cache.
     *
     * Gets multiple values from cache, including across multiple groups
     *
     * Usage: array( 'group0' => array( 'key0', 'key1', 'key2', ), 'group1' => array( 'key0' ) )
     *
     * Mirrors the Memcached Object Cache plugin's argument and return-value formats
     *
     * @param   array                           $groups  Array of groups and keys to retrieve
     * @return  bool|mixed                               Array of cached values, keys in the format $group:$key. Non-existent keys null.
     */
    public function get_multi( $groups ) {
        if ( empty( $groups ) || ! is_array( $groups ) ) {
            return false;
        }

        // Retrieve requested caches and reformat results to mimic Memcached Object Cache's output
        $cache = array();

        foreach ( $groups as $group => $keys ) {
            if ( in_array( $group, $this->ignored_groups ) || ! $this->redis_status() ) {
                foreach ( $keys as $key ) {
                    $cache[ $this->build_key( $key, $group ) ] = $this->get( $key, $group );
                }
            } else {
                // Reformat arguments as expected by Redis
                $derived_keys = array();

                foreach ( $keys as $key ) {
                    $derived_keys[] = $this->build_key( $key, $group );
                }

                // Retrieve from cache in a single request
                $group_cache = $this->redis->mget( $derived_keys );

                // Build an array of values looked up, keyed by the derived cache key
                $group_cache = array_combine( $derived_keys, $group_cache );

                // Restores cached data to its original data type
                $group_cache = array_map( array( $this, 'maybe_unserialize' ), $group_cache );

                // Redis returns null for values not found in cache, but expected return value is false in this instance
                $group_cache = array_map( array( $this, 'filter_redis_get_multi' ), $group_cache );

                $cache = array_merge( $cache, $group_cache );
            }
        }

        // Add to the internal cache the found values from Redis
        foreach ( $cache as $key => $value ) {
            if ( $value ) {
                $this->cache_hits++;
                $this->add_to_internal_cache( $key, $value );
            } else {
                $this->cache_misses++;
            }
        }

        return $cache;
    }

    /**
     * Sets the data contents into the cache.
     *
     * The cache contents is grouped by the $group parameter followed by the
     * $key. This allows for duplicate ids in unique groups. Therefore, naming of
     * the group should be used with care and should follow normal function
     * naming guidelines outside of core WordPress usage.
     *
     * The $expire parameter is not used, because the cache will automatically
     * expire for each time a page is accessed and PHP finishes. The method is
     * more for cache plugins which use files.
     *
     * @since 2.0.0
     *
     * @param int|string $key    What to call the contents in the cache.
     * @param mixed      $data   The contents to store in the cache.
     * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
     * @param int        $expire Not Used.
     * @return true Always returns true.
     */
    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        $result = true;
        $derived_key = $this->build_key( $key, $group );

        // save if group not excluded from redis and redis is up
        if ( ! in_array( $group, $this->ignored_groups ) && $this->redis_status() ) {
            $expire = $this->validate_expiration( $expire );

            if ( $expire ) {
                $result = $this->parse_redis_response( $this->redis->setex( $derived_key, $expire, $this->maybe_serialize( $data ) ) );
            } else {
                $result = $this->parse_redis_response( $this->redis->set( $derived_key, $this->maybe_serialize( $data ) ) );
            }
        }

        // if the set was successful, or we didn't go to redis
        if ( $result ) {
            $this->add_to_internal_cache( $derived_key, $data );
        }

        if ( function_exists( 'do_action' ) ) {
            do_action( 'redis_object_cache_set', $key, $data, $group, $expire );
        }

        return $result;
    }

    /**
     * Increments numeric cache item's value.
     *
     * @since 3.3.0
     *
     * @param int|string $key    The cache key to increment
     * @param int        $offset Optional. The amount by which to increment the item's value. Default 1.
     * @param string     $group  Optional. The group the key is in. Default 'default'.
     * @return false|int False on failure, the item's new value on success.
     */
    public function incr( $key, $offset = 1, $group = 'default' ) {
        $derived_key = $this->build_key( $key, $group );
        $offset = (int) $offset;

        // If group is a non-Redis group, save to internal cache, not Redis
        if ( in_array( $group, $this->ignored_groups ) || ! $this->redis_status() ) {
            $value = $this->get_from_internal_cache( $derived_key, $group );
            $value += $offset;
            $this->add_to_internal_cache( $derived_key, $value );

            return $value;
        }

        // Save to Redis
        $result = $this->parse_redis_response( $this->redis->incrBy( $derived_key, $offset ) );

        $this->add_to_internal_cache( $derived_key, (int) $this->redis->get( $derived_key ) );

        return $result;
    }

    /**
     * Decrements numeric cache item's value.
     *
     * @since 3.3.0
     *
     * @param int|string $key    The cache key to decrement.
     * @param int        $offset Optional. The amount by which to decrement the item's value. Default 1.
     * @param string     $group  Optional. The group the key is in. Default 'default'.
     * @return false|int False on failure, the item's new value on success.
     */
    public function decr( $key, $offset = 1, $group = 'default' ) {
        $derived_key = $this->build_key( $key, $group );
        $offset = (int) $offset;

        // If group is a non-Redis group, save to internal cache, not Redis
        if ( in_array( $group, $this->ignored_groups ) || ! $this->redis_status() ) {
            $value = $this->get_from_internal_cache( $derived_key, $group );
            $value -= $offset;
            $this->add_to_internal_cache( $derived_key, $value );

            return $value;
        }

        // Save to Redis
        $result = $this->parse_redis_response( $this->redis->decrBy( $derived_key, $offset ) );

        $this->add_to_internal_cache( $derived_key, (int) $this->redis->get( $derived_key ) );

        return $result;
    }

    /**
     * Echoes the stats of the caching.
     *
     * Gives the cache hits, and cache misses. Also prints every cached group,
     * key and the data.
     *
     * @since 2.0.0
     */
    public function stats() {
        echo '<p>';
        echo '<strong>Redis Status:</strong> ' . $this->redis_status() ? 'Connected' : 'Not Connected' . '<br />';
        echo "<strong>Redis Client:</strong> {$this->redis_client}<br />";
        echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
        echo '</p>';
        echo '<ul>';
        foreach ( $this->cache as $group => $cache ) {
            echo "<li><strong>Group:</strong> $group - ( " . number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 ) . 'k )</li>';
        }
        echo '</ul>';
    }

    /**
     * Builds a key for the cached object using the prefix, group and key.
     *
     * @param   string $key        The key under which to store the value.
     * @param   string $group      The group value appended to the $key.
     *
     * @return  string
     */
    public function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $salt = defined( 'WP_CACHE_KEY_SALT' ) ? trim( WP_CACHE_KEY_SALT ) : '';
        $prefix = in_array( $group, $this->global_groups ) ? $this->global_prefix : $this->blog_prefix;

        return "{$salt}{$prefix}:{$group}:{$key}";
    }

    /**
     * Convert data types when using Redis MGET
     *
     * When requesting multiple keys, those not found in cache are assigned the value null upon return.
     * Expected value in this case is false, so we convert
     *
     * @param   string  $value  Value to possibly convert
     * @return  string          Converted value
     */
    protected function filter_redis_get_multi( $value ) {
        if ( is_null( $value ) ) {
            $value = false;
        }

        return $value;
    }

    /**
     * Convert Redis responses into something meaningful
     *
     * @param mixed $response
     * @return mixed
     */
    protected function parse_redis_response( $response ) {
        if ( is_bool( $response ) ) {
            return $response;
        }

        if ( is_numeric( $response ) ) {
            return $response;
        }

        if ( is_object( $response ) && method_exists( $response, 'getPayload' ) ) {
            return $response->getPayload() === 'OK';
        }

        return false;
    }

    /**
     * Simple wrapper for saving object to the internal cache.
     *
     * @param   string $derived_key    Key to save value under.
     * @param   mixed  $value          Object value.
     */
    public function add_to_internal_cache( $derived_key, $value ) {
        $this->cache[ $derived_key ] = $value;
    }

    /**
     * Get a value specifically from the internal, run-time cache, not Redis.
     *
     * @param   int|string $key        Key value.
     * @param   int|string $group      Group that the value belongs to.
     *
     * @return  bool|mixed              Value on success; false on failure.
     */
    public function get_from_internal_cache( $key, $group ) {
        $derived_key = $this->build_key( $key, $group );

        if ( isset( $this->cache[ $derived_key ] ) ) {
            return $this->cache[ $derived_key ];
        }

        return false;
    }

    /**
     * Switches the internal blog ID.
     *
     * This changes the blog ID used to create keys in blog specific groups.
     *
     * @since 3.5.0
     *
     * @param int $blog_id Blog ID.
     */
    public function switch_to_blog( $blog_id ) {
        $blog_id           = (int) $blog_id;
        $this->blog_prefix = $this->multisite ? $blog_id : '';
    }

    /**
     * Sets the list of global cache groups.
     *
     * @since 3.0.0
     *
     * @param array $groups List of groups that are global.
     */
    public function add_global_groups( $groups ) {
        $groups = (array) $groups;

        if ( $this->redis_status() ) {
            $this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
        } else {
            $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $groups ) );
        }
    }

    /**
     * Sets the list of groups not to be cached by Redis.
     *
     * @param array $groups List of groups that are to be ignored.
     */
    public function add_non_persistent_groups( $groups ) {
        $groups = (array) $groups;

        $this->ignored_groups = array_unique( array_merge( $this->ignored_groups, $groups ) );
    }

    /**
     * Wrapper to validate the cache keys expiration value
     *
     * @param mixed $expiration Incomming expiration value (whatever it is)
     *
     * @return int|mixed
     */
    protected function validate_expiration( $expiration ) {
        $expiration = is_int( $expiration ) || ctype_digit( $expiration ) ? (int) $expiration : 0;

        if ( defined( 'WP_REDIS_MAXTTL' ) ) {
            $max = (int) WP_REDIS_MAXTTL;

            if ( $expiration === 0 || $expiration > $max ) {
                $expiration = $max;
            }
        }

        return $expiration;
    }

    /**
     * Unserialize value only if it was serialized.
     *
     * @param string $original Maybe unserialized original, if is needed.
     * @return mixed Unserialized data can be any type.
     */
    protected function maybe_unserialize( $original ) {
        // don't attempt to unserialize data that wasn't serialized going in
        if ( $this->is_serialized( $original ) ) {
            return @unserialize( $original );
        }

        return $original;
    }

    /**
     * Serialize data, if needed.
     * @param string|array|object $data Data that might be serialized.
     * @return mixed A scalar data
     */
    protected function maybe_serialize( $data ) {
        if ( is_array( $data ) || is_object( $data ) ) {
            return serialize( $data );
        }

        if ( $this->is_serialized( $data, false ) ) {
            return serialize( $data );
        }

        return $data;
    }

    /**
     * Check value to find if it was serialized.
     *
     * If $data is not an string, then returned value will always be false.
     * Serialized data is always a string.
     *
     * @param string $data   Value to check to see if was serialized.
     * @param bool   $strict Optional. Whether to be strict about the end of the string. Default true.
     * @return bool False if not serialized and true if it was.
     */
    protected function is_serialized( $data, $strict = true ) {
        // if it isn't a string, it isn't serialized.
        if ( ! is_string( $data ) ) {
            return false;
        }

        $data = trim( $data );

         if ( 'N;' == $data ) {
            return true;
        }

        if ( strlen( $data ) < 4 ) {
            return false;
        }

        if ( ':' !== $data[1] ) {
            return false;
        }

        if ( $strict ) {
            $lastc = substr( $data, -1 );

            if ( ';' !== $lastc && '}' !== $lastc ) {
                return false;
            }
        } else {
            $semicolon = strpos( $data, ';' );
            $brace = strpos( $data, '}' );

            // Either ; or } must exist.
            if ( false === $semicolon && false === $brace ) {
                return false;
            }

            // But neither must be in the first X characters.
            if ( false !== $semicolon && $semicolon < 3 ) {
                return false;
            }

            if ( false !== $brace && $brace < 4 ) {
                return false;
            }
        }
        $token = $data[0];

        switch ( $token ) {
            case 's':
                if ( $strict ) {
                    if ( '"' !== substr( $data, -2, 1 ) ) {
                        return false;
                    }
                } elseif ( false === strpos( $data, '"' ) ) {
                    return false;
                }
                // or else fall through
            case 'a':
            case 'O':
                return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';

                return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
        }

        return false;
    }
}
