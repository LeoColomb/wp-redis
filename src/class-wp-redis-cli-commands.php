<?php

class WP_Redis_CLI_Commands extends WP_CLI_Command
{

    /**
     * Show the Redis object cache status and (when possible) client.
     *
     * ## EXAMPLES
     *
     *     wp redis status
     */
    public function status()
    {

        $plugin = $GLOBALS['wp_object_cache'];
        $status = $plugin->get_status();
        $client = $plugin->get_redis_client_name();


        switch ($status) {
            case __('Disabled', 'redis-cache'):
                $status = WP_CLI::colorize("%y{$status}%n");
                break;
            case __('Connected', 'redis-cache'):
                $status = WP_CLI::colorize("%g{$status}%n");
                break;
            case __('Not Connected', 'redis-cache'):
                $status = WP_CLI::colorize("%r{$status}%n");
                break;
        }

        WP_CLI::line("Status: $status");

        if (! is_null($client)) {
            WP_CLI::line("Client: $client");
        }
    }
}
