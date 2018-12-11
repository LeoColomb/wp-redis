<?php

class WP_Redis_CLI_Commands extends WP_CLI_Command
{
    /**
     * Show the Redis cache status and (when possible) client.
     *
     * ## EXAMPLES
     *
     *     wp redis status
     */
    public function status()
    {
        $plugin = $GLOBALS['wp_object_cache'];
        $client = $plugin->get_redis_client_name();

        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            WP_CLI::line('Status: ' . WP_CLI::colorize('%yDisabled%n'));
            return;
        }
        
        if (! $plugin->redis_status()) {
            WP_CLI::line('Status: ' . WP_CLI::colorize('%rNot Connected%n'));
            return;
        }
        
        WP_CLI::line('Status: ' . WP_CLI::colorize('%gConnected%n'));
        if (! is_null($client = $plugin->redis_instance())) {
            WP_CLI::line("Client: $client");
        }
    }
    
    /**
     * Flush the Redis object cache, clear all data.
     *
     * ## EXAMPLES
     *
     *     wp redis flush
     */
    public function flush()
    { 
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            WP_CLI::error('Redis disabled!');
            return;
        }
        
        $plugin = $GLOBALS['wp_object_cache'];
        $plugin->flush();
    }
}
