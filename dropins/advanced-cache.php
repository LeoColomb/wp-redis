<?php

if ((! defined('WP_REDIS_DISABLED') || ! WP_REDIS_DISABLED)
    && class_exists('WP_Redis_Page_Cache')
) :

    /**
     * Advanced Cache API
     */

    $redis_cache = new WP_Redis_Page_Cache();
    $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_MISS);

    // Don't cache interactive scripts or API endpoints
    if (in_array(basename($_SERVER['SCRIPT_FILENAME']), [
        'wp-cron.php',
        'xmlrpc.php',
    ])) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_BYPASS);

        return;
    }

    // Don't cache javascript generators
    if (strpos($_SERVER['SCRIPT_FILENAME'], 'wp-includes/js') !== false) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_BYPASS);

        return;
    }

    // Only cache HEAD and GET requests
    if (isset($_SERVER['REQUEST_METHOD']) && ! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'])) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_BYPASS);

        return;
    }

    // Don't cache when cookies indicate a cache-exempt visitor
    if (is_array($_COOKIE) && ! empty($_COOKIE)) {
        foreach (array_keys($_COOKIE) as $cookie) {
            if (in_array($cookie, $redis_cache->noskip_cookies)) {
                continue;
            }

            if (strpos($cookie, 'wp') === 0 ||
                strpos($cookie, 'wordpress') === 0 ||
                strpos($cookie, 'comment_author') === 0
            ) {
                $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_BYPASS);

                return;
            }
        }
    }

    if (! defined('WP_CONTENT_DIR')) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_DOWN);

        return;
    }

    if (! require_once WP_CONTENT_DIR . '/object-cache.php') {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_DOWN);

        return;
    }

    wp_cache_init();

    if (! ($wp_object_cache instanceof WP_Redis_Object_Cache)) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_DOWN);

        return;
    }

    // Cache is disabled
    if ($redis_cache->max_age < 1) {
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_BYPASS);

        return;
    }

    // Necessary to prevent clients using cached version after login cookies set
    if (defined('WP_REDIS_VARY_COOKIE') && WP_REDIS_VARY_COOKIE) {
        header('Vary: Cookie', false);
    }

    if (function_exists('wp_cache_add_global_groups')) {
        wp_cache_add_global_groups([$redis_cache->group]);
    }

    $redis_cache->setup_request();
    $redis_cache->do_variants();
    $redis_cache->generate_keys();

    $genlock = false;
    $do_cache = false;
    $serve_cache = false;
    $cache = wp_cache_get($redis_cache->key, $redis_cache->group);

    if (isset($cache['version']) && $cache['version'] !== $redis_cache->url_version) {
        // Refresh the cache if a newer version is available
        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_EXPIRED);
        $do_cache = true;
    } else if ($redis_cache->seconds < 1 || $redis_cache->times < 2) {
        if (is_array($cache) && time() < $cache['time'] + $cache['max_age']) {
            $do_cache = false;
            $serve_cache = true;
        } else if (is_array($cache) && $redis_cache->use_stale) {
            $do_cache = true;
            $serve_cache = true;
        } else {
            $do_cache = true;
        }
    } else if (! is_array($cache) || time() >= $cache['time'] + $redis_cache->max_age - $redis_cache->seconds) {
        // No cache item found, or ready to sample traffic again at the end of the cache life

        wp_cache_add($redis_cache->req_key, 0, $redis_cache->group);
        $requests = wp_cache_incr($redis_cache->req_key, 1, $redis_cache->group);

        if ($requests >= $redis_cache->times) {
            if (is_array($cache) && time() >= $cache['time'] + $cache['max_age']) {
                $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_EXPIRED);
            }

            wp_cache_delete($redis_cache->req_key, $redis_cache->group);
            $do_cache = true;
        } else {
            $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_IGNORED);
            $do_cache = false;
        }
    }

    // Obtain cache generation lock
    if ($do_cache) {
        $genlock = wp_cache_add("{$redis_cache->url_key}_genlock", 1, $redis_cache->group, 10);
    }

    if ($serve_cache &&
        isset($cache['time'], $cache['max_age']) &&
        time() < $cache['time'] + $cache['max_age']
    ) {
        // Respect ETags
        $three04 = false;

        if (isset($_SERVER['HTTP_IF_NONE_MATCH'], $cache['headers']['ETag'][0]) &&
            $_SERVER['HTTP_IF_NONE_MATCH'] == $cache['headers']['ETag'][0]
        ) {
            $three04 = true;
        } else if ($redis_cache->cache_control && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $client_time = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);

            if (isset($cache['headers']['Last-Modified'][0])) {
                $cache_time = strtotime($cache['headers']['Last-Modified'][0]);
            } else {
                $cache_time = $cache['time'];
            }

            if ($client_time >= $cache_time) {
                $three04 = true;
            }
        }

        // Use the cache save time for `Last-Modified` so we can issue "304 Not Modified",
        // but don't clobber a cached `Last-Modified` header.
        if ($redis_cache->cache_control && ! isset($cache['headers']['Last-Modified'][0])) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $cache['time']) . ' GMT', true);
            header('Cache-Control: max-age=' . ($cache['max_age'] - time() + $cache['time']) . ', must-revalidate', true);
        }

        $redis_cache->do_headers($redis_cache->headers, $cache['headers']);

        if ($three04) {
            $protocol = $_SERVER['SERVER_PROTOCOL'];

            if (! preg_match('/^HTTP\/[0-9]{1}.[0-9]{1}$/', $protocol)) {
                $protocol = 'HTTP/1.0';
            }

            header("{$protocol} 304 Not Modified", true, 304);
            $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_HIT);
            exit;
        }

        if (! empty($cache['status_header'])) {
            header($cache['status_header'], true);
        }

        $redis_cache->cache_status_header($redis_cache::CACHE_STATUS_HIT);

        if ($do_cache && function_exists('fastcgi_finish_request')) {
            echo $cache['output'];
            fastcgi_finish_request();
        } else {
            echo $cache['output'];
            exit;
        }
    }

    if (! $do_cache || ! $genlock) {
        return;
    }

    $wp_filter['status_header'][10]['redis_cache'] = [
        'function' => [&$redis_cache, 'status_header'],
        'accepted_args' => 2
    ];

    ob_start([&$redis_cache, 'output_callback']);
endif;
