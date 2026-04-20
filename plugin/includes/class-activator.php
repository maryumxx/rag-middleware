<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAG_Chatbot_Activator {

    /**
     * Generate and persist a permanent site_id UUID on first activation.
     */
    public static function activate(): void {
        if ( ! get_option( 'rag_chatbot_site_id' ) ) {
            $site_id = wp_generate_uuid4();
            add_option( 'rag_chatbot_site_id', $site_id, '', false );
        }
    }

    /**
     * Nothing to do on deactivation — preserve data so re-activation is seamless.
     */
    public static function deactivate(): void {
        // Intentionally empty.
    }

    /**
     * Clean up WordPress options and remote data on full uninstall.
     */
    public static function uninstall(): void {
        $site_id = get_option( 'rag_chatbot_site_id' );

        // Remove all plugin options
        delete_option( 'rag_chatbot_site_id' );
        delete_option( 'rag_chatbot_greeting' );
        delete_option( 'rag_chatbot_color' );
        delete_option( 'rag_chatbot_position' );

        // Attempt to clean up remote data — best-effort, do not fail uninstall if it errors
        if ( $site_id && defined( 'RAG_CHATBOT_MIDDLEWARE_URL' ) && defined( 'RAG_CHATBOT_PLUGIN_SECRET' ) ) {
            wp_remote_request(
                RAG_CHATBOT_MIDDLEWARE_URL . '/delete',
                [
                    'method'  => 'DELETE',
                    'timeout' => 10,
                    'headers' => [
                        'Content-Type'    => 'application/json',
                        'X-Plugin-Secret' => RAG_CHATBOT_PLUGIN_SECRET,
                    ],
                    'body'    => wp_json_encode( [ 'site_id' => $site_id ] ),
                ]
            );
        }
    }
}
