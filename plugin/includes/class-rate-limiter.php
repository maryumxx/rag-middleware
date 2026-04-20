<?php

/**
 * Rate limiting for the WordPress plugin side.
 *
 * The primary rate limiting is handled by NeonClient in the middleware.
 * This class provides a lightweight local guard to prevent obvious abuse
 * before requests even reach the middleware (e.g. hitting the REST endpoint
 * with a script). It uses WordPress transients (no extra DB table needed).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAG_Chatbot_Rate_Limiter {

    /** Max requests per IP per window. */
    private int $max;

    /** Window length in seconds. */
    private int $window;

    public function __construct( int $max = 30, int $window = 60 ) {
        $this->max    = $max;
        $this->window = $window;
    }

    /**
     * Check whether the current request is within the rate limit.
     * Increments the counter if allowed.
     *
     * @return bool true if allowed, false if rate-limited
     */
    public function is_allowed(): bool {
        $ip  = $this->get_client_ip();
        $key = 'rag_rl_' . md5( $ip );

        $count = (int) get_transient( $key );

        if ( $count >= $this->max ) {
            return false;
        }

        if ( $count === 0 ) {
            set_transient( $key, 1, $this->window );
        } else {
            // Increment without resetting expiry — use update instead of set
            set_transient( $key, $count + 1, $this->window );
        }

        return true;
    }

    private function get_client_ip(): string {
        // Trust only REMOTE_ADDR; do NOT use X-Forwarded-For (spoofable).
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
