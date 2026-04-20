<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAG_Chatbot_API_Handler {

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route(
            'rag-chatbot/v1',
            '/chat',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_chat' ],
                'permission_callback' => '__return_true', // Public endpoint — rate limiting is in middleware
                'args'                => [
                    'message' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function( $param ) {
                            return is_string( $param ) && mb_strlen( trim( $param ) ) > 0;
                        },
                    ],
                    'history' => [
                        'required'          => false,
                        'type'              => 'array',
                        'default'           => [],
                    ],
                ],
            ]
        );
    }

    public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
        $message = sanitize_text_field( $request->get_param( 'message' ) );
        $history = $request->get_param( 'history' );

        if ( mb_strlen( $message ) > 500 ) {
            $message = mb_substr( $message, 0, 500 );
        }

        // Sanitise history — keep only role/content pairs
        $clean_history = [];
        if ( is_array( $history ) ) {
            foreach ( array_slice( $history, -10 ) as $turn ) {
                if ( isset( $turn['role'], $turn['content'] ) ) {
                    $clean_history[] = [
                        'role'    => sanitize_text_field( $turn['role'] ),
                        'content' => sanitize_text_field( $turn['content'] ),
                    ];
                }
            }
        }

        $site_id = get_option( 'rag_chatbot_site_id' );
        if ( ! $site_id ) {
            return new WP_REST_Response(
                [ 'answer' => 'Sorry, the chatbot is not configured correctly.' ],
                200
            );
        }

        $payload = wp_json_encode( [
            'site_id' => $site_id,
            'message' => $message,
            'history' => $clean_history,
        ] );

        $response = wp_remote_post(
            rag_chatbot_middleware_url() . '/chat',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'               => 'application/json',
                    'X-Plugin-Secret'            => rag_chatbot_secret(),
                    'ngrok-skip-browser-warning' => 'true',
                ],
                'body'    => $payload,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[RAG Chatbot] Chat request failed: ' . $response->get_error_message() );
            return new WP_REST_Response(
                [ 'answer' => 'Sorry, the chatbot is temporarily unavailable.' ],
                200
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 || ! isset( $data['answer'] ) ) {
            error_log( "[RAG Chatbot] Middleware returned {$code}: {$body}" );
            return new WP_REST_Response(
                [ 'answer' => 'Sorry, the chatbot is temporarily unavailable.' ],
                200
            );
        }

        return new WP_REST_Response( $data, 200 );
    }
}
