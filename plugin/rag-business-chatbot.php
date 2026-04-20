<?php
/**
 * Plugin Name: RAG Business Chatbot
 * Description: AI-powered chatbot trained on your business PDF. Upload once, works instantly.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: rag-business-chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RAG_CHATBOT_VERSION',        '1.0.0' );
define( 'RAG_CHATBOT_PLUGIN_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RAG_CHATBOT_PLUGIN_URL',     plugin_dir_url( __FILE__ ) );

// Middleware URL and secret — can be overridden via Settings page in WP Admin.
// Fallback constants are used only if the DB options are empty.
define( 'RAG_CHATBOT_MIDDLEWARE_URL_DEFAULT', 'https://YOUR-RAILWAY-URL.up.railway.app' );
define( 'RAG_CHATBOT_SECRET_DEFAULT',         'YOUR-32-CHAR-SECRET-MATCHES-MIDDLEWARE-ENV' );

/**
 * Get the active middleware URL (DB option takes priority over constant).
 */
function rag_chatbot_middleware_url(): string {
    $url = get_option( 'rag_chatbot_middleware_url', '' );
    return ( $url !== '' ) ? rtrim( $url, '/' ) : RAG_CHATBOT_MIDDLEWARE_URL_DEFAULT;
}

/**
 * Get the active plugin secret (DB option takes priority over constant).
 */
function rag_chatbot_secret(): string {
    $secret = get_option( 'rag_chatbot_plugin_secret', '' );
    return ( $secret !== '' ) ? $secret : RAG_CHATBOT_SECRET_DEFAULT;
}

// Keep backward-compatible constants (used by older code paths)
define( 'RAG_CHATBOT_MIDDLEWARE_URL', 'https://YOUR-RAILWAY-URL.up.railway.app' );
define( 'RAG_CHATBOT_PLUGIN_SECRET',  'YOUR-32-CHAR-SECRET-MATCHES-MIDDLEWARE-ENV' );

// Load classes
require_once RAG_CHATBOT_PLUGIN_DIR . 'includes/class-activator.php';
require_once RAG_CHATBOT_PLUGIN_DIR . 'includes/class-admin.php';
require_once RAG_CHATBOT_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once RAG_CHATBOT_PLUGIN_DIR . 'includes/class-rate-limiter.php';

// Activation / deactivation / uninstall hooks
register_activation_hook( __FILE__, [ 'RAG_Chatbot_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RAG_Chatbot_Activator', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'RAG_Chatbot_Activator', 'uninstall' ] );

// Boot admin UI
if ( is_admin() ) {
    $admin = new RAG_Chatbot_Admin();
    $admin->init();
}

// Boot REST API
$api_handler = new RAG_Chatbot_API_Handler();
$api_handler->init();

// Register shortcode
add_shortcode( 'rag_chatbot', 'rag_chatbot_render_shortcode' );

/**
 * Enqueue front-end assets only when the shortcode is actually present on the page.
 * WordPress does not run shortcodes during wp_enqueue_scripts, so we use a flag
 * set by the shortcode callback itself and leverage late enqueue via wp_footer.
 */
add_action( 'wp_footer', 'rag_chatbot_maybe_enqueue_assets', 1 );

function rag_chatbot_maybe_enqueue_assets(): void {
    if ( ! did_action( 'rag_chatbot_shortcode_rendered' ) ) {
        return;
    }
    wp_enqueue_style(
        'rag-chatbot-widget',
        RAG_CHATBOT_PLUGIN_URL . 'assets/chat-widget.css',
        [],
        RAG_CHATBOT_VERSION
    );
    wp_enqueue_script(
        'rag-chatbot-widget',
        RAG_CHATBOT_PLUGIN_URL . 'assets/chat-widget.js',
        [],
        RAG_CHATBOT_VERSION,
        true
    );
}

function rag_chatbot_render_shortcode( array $atts ): string {
    do_action( 'rag_chatbot_shortcode_rendered' );

    $greeting = get_option( 'rag_chatbot_greeting', 'Hi! Ask me anything about our business.' );
    $color    = get_option( 'rag_chatbot_color',    '#1a1a2e' );
    $position = get_option( 'rag_chatbot_position', 'bottom-right' );

    // Whitelist position
    if ( ! in_array( $position, [ 'bottom-right', 'bottom-left' ], true ) ) {
        $position = 'bottom-right';
    }

    return sprintf(
        '<div id="rag-chatbot-root" data-endpoint="%s" data-greeting="%s" data-color="%s" data-position="%s"></div>',
        esc_attr( rest_url( 'rag-chatbot/v1/chat' ) ),
        esc_attr( $greeting ),
        esc_attr( $color ),
        esc_attr( $position )
    );
}
