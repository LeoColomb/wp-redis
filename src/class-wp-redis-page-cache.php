<?php

/**
 * Redis class that implements cache with Redis backend.
 */
class WP_Redis_Page_Cache
{
    const CACHE_STATUS_HEADER_NAME = 'X-Redis-Cache-Status';
    const CACHE_STATUS_HIT = 'HIT';
    const CACHE_STATUS_MISS = 'MISS';
    const CACHE_STATUS_BYPASS = 'BYPASS';
    const CACHE_STATUS_DOWN = 'DOWN';
    const CACHE_STATUS_IGNORED = 'IGNORED';
    const CACHE_STATUS_EXPIRED = 'EXPIRED';

    /**
     * Only cache a page after it is accessed this many times.
     *
     * @var integer
     */
    protected $times = 2;

    /**
     * Only cache a page if it is accessed `$times` in this many seconds.
     * Set to zero to ignore this and use cache immediately.
     *
     * @var integer
     */
    protected $seconds = 120;

    /**
     * Expire cache items aged this many seconds.
     * Set to zero to disable cache.
     *
     * @var integer
     */
    protected $max_age = 300;

    /**
     * Name of object cache group used for page cache.
     *
     * @var string
     */
    protected $group = 'redis-cache';

    /**
     * If you conditionally serve different content, put the variable values here
     * using the `add_variant()` method.
     *
     * @var array
     */
    protected $unique = [];

    /**
     * Array of functions for `create_function()`.
     *
     * @var array
     */
    protected $vary = [];

    /**
     * Add headers here as `name => value` or `name => [values]`.
     * These will be sent with every response from the cache.
     *
     * @var array
     */
    protected $headers = [];

    /**
     * These headers will never be cached. (Use lower case only!)
     *
     * @var array
     */
    protected $uncached_headers = [
        'transfer-encoding'
    ];

    /**
     * Set to `false` to disable `Last-Modified` and `Cache-Control` headers.
     *
     * @var boolean
     */
    protected $cache_control = true;

    /**
     * Set to `true` to disable the output buffer.
     *
     * @var boolean
     */
    protected $cancel = false;

    /**
     * Is it ok to return stale cached response when updating the cache?
     *
     * @var boolean
     */
    protected $use_stale = true;

    /**
     * Names of cookies - if they exist and the cache would normally be bypassed, don't bypass it.
     *
     * @var array
     */
    protected $noskip_cookies = [
        'wordpress_test_cookie'
    ];

    protected $keys = [];
    protected $url_key;
    protected $url_version;
    protected $key;
    protected $req_key;
    protected $status_header;
    protected $status_code;

    public function __construct()
    {
        array_map(function ($setting) {
            $constant = sprintf('WP_REDIS_%s', strtoupper($setting));
            if (defined($constant)) {
                $this->$setting = constant($constant);
            }
        }, [
            'times',
            'seconds',
            'max_age',
            'group',
            'unique',
            'headers',
            'uncached_headers',
            'cache_control',
            'use_stale',
            'noskip_cookies'
        ]);
    }

    public function __get($name)
    {
        return $this->$name;
    }

    public function setup_request()
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            $this->keys['host'] = $_SERVER['HTTP_HOST'];
        }

        if (isset($_SERVER['REQUEST_METHOD'])) {
            $this->keys['method'] = $_SERVER['REQUEST_METHOD'];
        }

        if (isset($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $query_string);
            $this->keys['query'] = $query_string;
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            if (($pos = strpos($_SERVER['REQUEST_URI'], '?')) !== false) {
                $this->keys['path'] = substr($_SERVER['REQUEST_URI'], 0, $pos);
            } else {
                $this->keys['path'] = $_SERVER['REQUEST_URI'];
            }
        }

        $this->keys['ssl'] = $this->is_secure();

        $this->keys['extra'] = $this->unique;

        $this->url_key = md5(sprintf(
            '%s://%s%s',
            $this->keys['ssl'] ? 'http' : 'https',
            $this->keys['host'],
            $this->keys['path']
        ));

        $this->url_version = (int) wp_cache_get("{$this->url_key}_version", $this->group);
    }

    public function is_secure()
    {
        if (isset($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) === 'on' || $_SERVER['HTTPS'] == '1')) {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && ($_SERVER['SERVER_PORT'] == '443')) {
            return true;
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        return false;
    }

    protected function add_variant($function)
    {
        $this->vary[md5($function)] = $function;
    }

    /**
     * This function is called without arguments early in the page load,
     * then with arguments during the output buffer handler.
     *
     * @param  array|false  $dimensions
     */
    public function do_variants($dimensions = false)
    {
        if ($dimensions === false) {
            $dimensions = wp_cache_get("{$this->url_key}_vary", $this->group);
        } else {
            wp_cache_set("{$this->url_key}_vary", $dimensions, $this->group, $this->max_age + 10);
        }

        if (is_array($dimensions)) {
            ksort($dimensions);

            foreach ($dimensions as $key => $function) {
                $value = $function();
                $this->keys[$key] = $value;
            }
        }
    }

    public function generate_keys()
    {
        $this->key = md5(serialize($this->keys));
        $this->req_key = "{$this->key}_reqs";
    }

    protected function status_header($status_header, $status_code)
    {
        $this->status_header = $status_header;
        $this->status_code = $status_code;

        return $status_header;
    }

    public function cache_status_header($cache_status)
    {
        header(self::CACHE_STATUS_HEADER_NAME . ": $cache_status");
    }

    /**
     * Merge the arrays of headers into one and send them.
     *
     * @param  array  $headers1
     * @param  array  $headers2
     */
    public function do_headers($headers1, $headers2 = [])
    {
        $headers = [];
        $keys = array_unique(array_merge(array_keys($headers1), array_keys($headers2)));

        foreach ($keys as $k) {
            $headers[$k] = [];

            if (isset($headers1[$k]) && isset($headers2[$k])) {
                $headers[$k] = array_merge((array) $headers2[$k], (array) $headers1[$k]);
            } else if (isset($headers2[$k])) {
                $headers[$k] = (array) $headers2[$k];
            } else {
                $headers[$k] = (array) $headers1[$k];
            }

            $headers[$k] = array_unique($headers[$k]);
        }

        // These headers take precedence over any previously sent with the same names
        foreach ($headers as $k => $values) {
            $clobber = true;

            foreach ($values as $v) {
                header("$k: $v", $clobber);
                $clobber = false;
            }
        }
    }

    protected function output_callback($output)
    {
        $output = trim($output);

        if ($this->cancel !== false) {
            wp_cache_delete("{$this->url_key}_genlock", $this->group);
            header('X-Redis-Cache-Status: BYPASS', true);

            return $output;
        }

        // Do not cache 5xx responses
        if (isset($this->status_code) && intval($this->status_code / 100) === 5) {
            wp_cache_delete("{$this->url_key}_genlock", $this->group);
            header('X-Redis-Cache-Status: BYPASS', true);

            return $output;
        }

        $this->do_variants($this->vary);
        $this->generate_keys();

        $cache = [
            'version' => $this->url_version,
            'time' => isset($_SERVER['REQUEST_TIME']) ? $_SERVER['REQUEST_TIME'] : time(),
            'status_header' => $this->status_header,
            'headers' => [],
            'output' => $output,
        ];

        foreach (headers_list() as $header) {
            list($k, $v) = array_map('trim', explode(':', $header, 2));
            $cache['headers'][$k][] = $v;
        }

        if (! empty($cache['headers']) && ! empty($this->uncached_headers)) {
            foreach ($this->uncached_headers as $header) {
                unset($cache['headers'][$header]);
            }
        }

        foreach ($cache['headers'] as $header => $values) {
            // Don't cache if cookies were set
            if (strtolower($header) === 'set-cookie') {
                wp_cache_delete("{$this->url_key}_genlock", $this->group);
                header('X-Redis-Cache-Status: BYPASS', true);

                return $output;
            }

            foreach ((array) $values as $value) {
                if (preg_match('/^Cache-Control:.*max-?age=(\d+)/i', "{$header}: {$value}", $matches)) {
                    $this->max_age = intval($matches[1]);
                }
            }
        }

        $cache['max_age'] = $this->max_age;

        wp_cache_set($this->key, $cache, $this->group, $this->max_age + $this->seconds + 30);

        wp_cache_delete("{$this->url_key}_genlock", $this->group);

        if ($this->cache_control) {
            // Don't clobber `Last-Modified` header if already set
            if (! isset($cache['headers']['Last-Modified'])) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cache['time']) . ' GMT', true);
            }

            if (! isset($cache['headers']['Cache-Control'])) {
                header("Cache-Control: max-age={$this->max_age}, must-revalidate", false);
            }
        }

        $this->do_headers($this->headers);

        return $cache['output'];
    }
}
