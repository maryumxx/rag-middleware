<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAG_Chatbot_Admin {

    public function init(): void {
        add_action( 'admin_menu',        [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',        [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_rag_process_pdf', [ $this, 'ajax_process_pdf' ] );
    }

    // -------------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------------

    public function register_menu(): void {
        add_menu_page(
            'RAG Chatbot',
            'RAG Chatbot',
            'manage_options',
            'rag-chatbot',
            [ $this, 'render_knowledge_base_page' ],
            'dashicons-format-chat',
            80
        );

        add_submenu_page(
            'rag-chatbot',
            'Knowledge Base',
            'Knowledge Base',
            'manage_options',
            'rag-chatbot',
            [ $this, 'render_knowledge_base_page' ]
        );

        add_submenu_page(
            'rag-chatbot',
            'Settings',
            'Settings',
            'manage_options',
            'rag-chatbot-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_rag-chatbot', 'rag-chatbot_page_rag-chatbot-settings' ], true ) ) {
            return;
        }

        // Inline admin styles (no extra HTTP request)
        wp_add_inline_style( 'wp-admin', $this->admin_css() );
    }

    // -------------------------------------------------------------------------
    // Settings API
    // -------------------------------------------------------------------------

    public function register_settings(): void {
        register_setting( 'rag_chatbot_settings', 'rag_chatbot_greeting', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Hi! Ask me anything about our business.',
        ] );

        register_setting( 'rag_chatbot_settings', 'rag_chatbot_color', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_hex_color' ],
            'default'           => '#1a1a2e',
        ] );

        register_setting( 'rag_chatbot_settings', 'rag_chatbot_position', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_position' ],
            'default'           => 'bottom-right',
        ] );

        register_setting( 'rag_chatbot_settings', 'rag_chatbot_middleware_url', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'rag_chatbot_settings', 'rag_chatbot_plugin_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
    }

    public function sanitize_hex_color( string $value ): string {
        $value = trim( $value );
        if ( preg_match( '/^#[0-9a-fA-F]{3,6}$/', $value ) ) {
            return $value;
        }
        return '#1a1a2e';
    }

    public function sanitize_position( string $value ): string {
        return in_array( $value, [ 'bottom-right', 'bottom-left' ], true ) ? $value : 'bottom-right';
    }

    // -------------------------------------------------------------------------
    // Knowledge Base page
    // -------------------------------------------------------------------------

    public function render_knowledge_base_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status = $this->fetch_status();
        $nonce  = wp_create_nonce( 'rag_chatbot_upload_nonce' );
        ?>
        <div class="rag-admin-wrap">
            <div class="rag-admin-header">
                <div>
                    <h1 class="rag-admin-title">Knowledge Base</h1>
                    <p class="rag-admin-subtitle">Upload your business PDF to train the chatbot.</p>
                </div>
                <?php if ( ( $status['chunks'] ?? 0 ) > 0 ) : ?>
                <span class="rag-status-badge rag-status-active">Active</span>
                <?php else : ?>
                <span class="rag-status-badge rag-status-empty">No data</span>
                <?php endif; ?>
            </div>

            <div class="rag-card">
                <div id="rag-drop-zone" class="rag-drop-zone" onclick="document.getElementById('rag-pdf-input').click()">
                    <div class="rag-drop-zone-icon">
                        <svg width="48" height="48" fill="none" viewBox="0 0 48 48">
                            <rect width="48" height="48" rx="12" fill="#f1f5f9"/>
                            <path d="M16 34h16M24 14v16M24 14l-5 5M24 14l5 5" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <p class="rag-drop-zone-text">Drag and drop your PDF here<br>or <strong>click to browse</strong></p>
                    <p class="rag-drop-zone-hint">Max 20 MB &middot; PDF only</p>
                    <input type="file" id="rag-pdf-input" accept="application/pdf,.pdf" style="display:none">
                </div>

                <div id="rag-file-selected" class="rag-file-selected" style="display:none">
                    <svg width="20" height="20" fill="none" viewBox="0 0 20 20">
                        <path d="M5 10.5l3.5 3.5 6.5-7" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span id="rag-file-name"></span>
                    <button type="button" id="rag-clear-file" class="rag-clear-btn">&times;</button>
                </div>

                <div id="rag-progress" style="display:none">
                    <div class="rag-progress-bar">
                        <div class="rag-progress-fill" id="rag-progress-fill"></div>
                    </div>
                    <p id="rag-progress-text" class="rag-progress-text">Uploading...</p>
                </div>

                <div id="rag-result" style="display:none"></div>

                <button type="button" id="rag-upload-btn" class="rag-btn-primary" disabled>
                    Upload &amp; Process Knowledge Base
                </button>
            </div>

            <?php if ( ( $status['chunks'] ?? 0 ) > 0 ) : ?>
            <div class="rag-card rag-status-card">
                <div class="rag-status-row">
                    <div>
                        <p class="rag-status-label">Chunks stored</p>
                        <p class="rag-status-value"><?php echo esc_html( (string) ( $status['chunks'] ?? 0 ) ); ?></p>
                    </div>
                    <div>
                        <p class="rag-status-label">Last updated</p>
                        <p class="rag-status-value"><?php echo esc_html( $status['last_updated'] ?? '—' ); ?></p>
                    </div>
                    <div>
                        <p class="rag-status-label">Requests remaining today</p>
                        <p class="rag-status-value"><?php echo esc_html( (string) ( $status['rate_limit_remaining'] ?? '—' ) ); ?></p>
                    </div>
                </div>
            </div>
            <?php else : ?>
            <div class="rag-card rag-empty-state">
                <p>No knowledge base yet. Upload a PDF above to get started.</p>
                <p class="rag-hint">The chatbot will answer visitor questions based on the content of your PDF.</p>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            const dropZone   = document.getElementById('rag-drop-zone');
            const fileInput  = document.getElementById('rag-pdf-input');
            const fileInfo   = document.getElementById('rag-file-selected');
            const fileName   = document.getElementById('rag-file-name');
            const clearBtn   = document.getElementById('rag-clear-file');
            const uploadBtn  = document.getElementById('rag-upload-btn');
            const progress   = document.getElementById('rag-progress');
            const progFill   = document.getElementById('rag-progress-fill');
            const progText   = document.getElementById('rag-progress-text');
            const result     = document.getElementById('rag-result');
            let selectedFile = null;

            function setFile(file) {
                if (!file) return;
                if (file.type !== 'application/pdf') {
                    showResult('error', 'Only PDF files are accepted.');
                    return;
                }
                if (file.size > 20 * 1024 * 1024) {
                    showResult('error', 'File exceeds the 20 MB limit.');
                    return;
                }
                selectedFile = file;
                fileName.textContent = file.name;
                fileInfo.style.display = 'flex';
                uploadBtn.disabled = false;
                result.style.display = 'none';
            }

            fileInput.addEventListener('change', () => setFile(fileInput.files[0]));
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                selectedFile = null;
                fileInput.value = '';
                fileInfo.style.display = 'none';
                uploadBtn.disabled = true;
            });

            dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('drag-over'); });
            dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
                setFile(e.dataTransfer.files[0]);
            });

            uploadBtn.addEventListener('click', () => {
                if (!selectedFile) return;

                uploadBtn.disabled = true;
                progress.style.display = 'block';
                result.style.display   = 'none';
                progFill.style.width   = '10%';
                progText.textContent   = 'Uploading…';

                const formData = new FormData();
                formData.append('action', 'rag_process_pdf');
                formData.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
                formData.append('pdf', selectedFile);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>);

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const pct = Math.round((e.loaded / e.total) * 60);
                        progFill.style.width = pct + '%';
                    }
                };

                xhr.onload = () => {
                    progFill.style.width = '100%';
                    progress.style.display = 'none';
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            showResult('success', 'Success! ' + (data.data.chunks_stored || 0) + ' chunks stored. The chatbot is ready.');
                        } else {
                            showResult('error', data.data || 'Upload failed.');
                        }
                    } catch(e) {
                        showResult('error', 'Unexpected server response.');
                    }
                    uploadBtn.disabled = false;
                };

                xhr.onerror = () => {
                    progress.style.display = 'none';
                    showResult('error', 'Network error. Please try again.');
                    uploadBtn.disabled = false;
                };

                progText.textContent = 'Processing…';
                progFill.style.width = '60%';
                xhr.send(formData);
            });

            function showResult(type, msg) {
                result.style.display = 'block';
                result.className = 'rag-result rag-result-' + type;
                result.textContent = msg;
            }
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $color    = get_option( 'rag_chatbot_color',    '#1a1a2e' );
        $position = get_option( 'rag_chatbot_position', 'bottom-right' );
        ?>
        <div class="rag-admin-wrap">
            <div class="rag-admin-header">
                <div>
                    <h1 class="rag-admin-title">Settings</h1>
                    <p class="rag-admin-subtitle">Customise how the chat widget looks and behaves.</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'rag_chatbot_settings' ); ?>

                <div class="rag-settings-grid">
                    <div class="rag-card">
                        <h2 class="rag-card-title">Widget Appearance</h2>

                        <div class="rag-field">
                            <label for="rag_chatbot_greeting" class="rag-label">Greeting Message</label>
                            <input type="text" id="rag_chatbot_greeting" name="rag_chatbot_greeting"
                                   value="<?php echo esc_attr( get_option( 'rag_chatbot_greeting', 'Hi! Ask me anything about our business.' ) ); ?>"
                                   class="rag-input" placeholder="Hi! Ask me anything about our business.">
                            <p class="rag-field-hint">Shown as the first bot message when a visitor opens the chat.</p>
                        </div>

                        <div class="rag-field">
                            <label for="rag_chatbot_color" class="rag-label">Button &amp; Accent Color</label>
                            <div class="rag-color-row">
                                <input type="color" id="rag_chatbot_color" name="rag_chatbot_color"
                                       value="<?php echo esc_attr( $color ); ?>" class="rag-color-input"
                                       oninput="document.getElementById('rag-preview-btn').style.background=this.value">
                                <input type="text" id="rag_chatbot_color_text" value="<?php echo esc_attr( $color ); ?>"
                                       class="rag-input rag-color-text" maxlength="7"
                                       oninput="document.getElementById('rag_chatbot_color').value=this.value;document.getElementById('rag-preview-btn').style.background=this.value">
                            </div>
                        </div>

                        <div class="rag-field">
                            <label for="rag_chatbot_position" class="rag-label">Button Position</label>
                            <select id="rag_chatbot_position" name="rag_chatbot_position" class="rag-input rag-select">
                                <option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>>Bottom Right</option>
                                <option value="bottom-left"  <?php selected( $position, 'bottom-left' );  ?>>Bottom Left</option>
                            </select>
                        </div>

                        <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
                        <h2 class="rag-card-title">Middleware Connection</h2>

                        <div class="rag-field">
                            <label for="rag_chatbot_middleware_url" class="rag-label">Middleware URL</label>
                            <input type="text" id="rag_chatbot_middleware_url" name="rag_chatbot_middleware_url"
                                   value="<?php echo esc_attr( get_option( 'rag_chatbot_middleware_url', '' ) ); ?>"
                                   class="rag-input" placeholder="https://your-ngrok-or-railway-url">
                            <p class="rag-field-hint">Your ngrok or Railway URL — no trailing slash.</p>
                        </div>

                        <div class="rag-field">
                            <label for="rag_chatbot_plugin_secret" class="rag-label">Plugin Secret</label>
                            <input type="text" id="rag_chatbot_plugin_secret" name="rag_chatbot_plugin_secret"
                                   value="<?php echo esc_attr( get_option( 'rag_chatbot_plugin_secret', '' ) ); ?>"
                                   class="rag-input" placeholder="Your ALLOWED_PLUGIN_SECRET value">
                            <p class="rag-field-hint">Must match <code>ALLOWED_PLUGIN_SECRET</code> in your middleware <code>.env</code>.</p>
                        </div>

                        <?php submit_button( 'Save Settings', 'primary', 'submit', false, [ 'class' => 'rag-btn-primary' ] ); ?>
                    </div>

                    <div class="rag-card rag-preview-card">
                        <h2 class="rag-card-title">Preview</h2>
                        <p class="rag-field-hint">Live preview of the chat button.</p>
                        <div class="rag-preview-area">
                            <div id="rag-preview-btn" class="rag-preview-btn" style="background:<?php echo esc_attr( $color ); ?>">
                                <svg width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                                          fill="white"/>
                                </svg>
                            </div>
                        </div>
                        <p class="rag-field-hint" style="text-align:center;margin-top:12px">
                            Add <code>[rag_chatbot]</code> to any page or post to show the widget.
                        </p>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX — PDF ingest
    // -------------------------------------------------------------------------

    public function ajax_process_pdf(): void {
        check_ajax_referer( 'rag_chatbot_upload_nonce', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        if ( empty( $_FILES['pdf'] ) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'PDF file is required.' );
        }

        $file = $_FILES['pdf'];

        // Validate mimetype server-side
        $finfo    = new finfo( FILEINFO_MIME_TYPE );
        $mimeType = $finfo->file( $file['tmp_name'] );
        if ( $mimeType !== 'application/pdf' ) {
            wp_send_json_error( 'Only PDF files are accepted.' );
        }

        if ( $file['size'] > 20 * 1024 * 1024 ) {
            wp_send_json_error( 'File exceeds 20 MB limit.' );
        }

        $site_id = get_option( 'rag_chatbot_site_id' );
        if ( ! $site_id ) {
            wp_send_json_error( 'Site ID not found. Please deactivate and reactivate the plugin.' );
        }

        // Build multipart POST to middleware
        $boundary = wp_generate_password( 20, false );
        $body     = $this->build_multipart_body( $boundary, $file['tmp_name'], $site_id );

        $response = wp_remote_post(
            rag_chatbot_middleware_url() . '/ingest',
            [
                'timeout' => 120,
                'headers' => [
                    'Content-Type'               => 'multipart/form-data; boundary=' . $boundary,
                    'X-Plugin-Secret'            => rag_chatbot_secret(),
                    'ngrok-skip-browser-warning' => 'true',
                ],
                'body'    => $body,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[RAG Chatbot] Ingest error: ' . $response->get_error_message() );
            wp_send_json_error( 'Could not reach the middleware service. Please try again.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 || empty( $data['success'] ) ) {
            $errMsg = $data['error'] ?? 'Unknown error from middleware.';
            error_log( "[RAG Chatbot] Ingest failed ({$code}): {$errMsg}" );
            wp_send_json_error( esc_html( $errMsg ) );
        }

        wp_send_json_success( $data );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Fetch status from middleware or return zeros on error. */
    private function fetch_status(): array {
        $site_id = get_option( 'rag_chatbot_site_id' );
        if ( ! $site_id ) {
            return [ 'chunks' => 0 ];
        }

        $response = wp_remote_get(
            add_query_arg( 'site_id', rawurlencode( $site_id ), rag_chatbot_middleware_url() . '/status' ),
            [
                'timeout' => 10,
                'headers' => [
                    'X-Plugin-Secret'            => rag_chatbot_secret(),
                    'ngrok-skip-browser-warning' => 'true',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'chunks' => 0 ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return [ 'chunks' => 0 ];
        }

        // Format last_updated for human display
        if ( ! empty( $data['last_updated'] ) ) {
            $ts = strtotime( $data['last_updated'] );
            if ( $ts ) {
                $data['last_updated'] = human_time_diff( $ts ) . ' ago';
            }
        }

        return $data;
    }

    /**
     * Manually build a multipart/form-data body so we can send the raw file bytes
     * through wp_remote_post (which does not natively support file uploads).
     */
    private function build_multipart_body( string $boundary, string $tmpPath, string $siteId ): string {
        $eol      = "\r\n";
        $body     = '';
        $fileData = file_get_contents( $tmpPath );

        // site_id field
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="site_id"' . $eol . $eol;
        $body .= $siteId . $eol;

        // PDF field
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="pdf"; filename="upload.pdf"' . $eol;
        $body .= 'Content-Type: application/pdf' . $eol . $eol;
        $body .= $fileData . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /** Return inline admin CSS. */
    private function admin_css(): string {
        return '
        .rag-admin-wrap { max-width: 900px; margin: 24px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .rag-admin-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .rag-admin-title  { font-size: 24px; font-weight: 700; color: #1e293b; margin: 0; }
        .rag-admin-subtitle { color: #64748b; margin: 4px 0 0; font-size: 14px; }
        .rag-status-badge  { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .rag-status-active { background: #d1fae5; color: #065f46; }
        .rag-status-empty  { background: #f1f5f9; color: #64748b; }
        .rag-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px; margin-bottom: 20px; }
        .rag-card-title { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 20px; }
        .rag-drop-zone { border: 2px dashed #e2e8f0; border-radius: 10px; padding: 40px 20px; text-align: center; cursor: pointer; transition: border-color .2s, background .2s; margin-bottom: 16px; }
        .rag-drop-zone:hover, .rag-drop-zone.drag-over { border-color: #1a1a2e; background: #f8f9fc; }
        .rag-drop-zone-icon { margin-bottom: 12px; }
        .rag-drop-zone-text { margin: 0 0 4px; color: #1e293b; font-size: 15px; }
        .rag-drop-zone-hint { margin: 0; color: #64748b; font-size: 13px; }
        .rag-file-selected { display: flex; align-items: center; gap: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; color: #1e293b; }
        .rag-clear-btn { background: none; border: none; cursor: pointer; color: #64748b; font-size: 18px; line-height: 1; padding: 0 0 0 4px; }
        .rag-progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-bottom: 8px; }
        .rag-progress-fill { height: 100%; background: #1a1a2e; border-radius: 3px; transition: width .3s ease; }
        .rag-progress-text { color: #64748b; font-size: 13px; margin: 0 0 16px; }
        .rag-result { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
        .rag-result-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #065f46; }
        .rag-result-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .rag-btn-primary { display: inline-block; background: #1a1a2e; color: #fff !important; border: none; border-radius: 8px; padding: 12px 24px; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity .2s; }
        .rag-btn-primary:hover   { opacity: .85; }
        .rag-btn-primary:disabled { opacity: .4; cursor: not-allowed; }
        .rag-status-card { padding: 20px 28px; }
        .rag-status-row  { display: flex; gap: 40px; flex-wrap: wrap; }
        .rag-status-label { margin: 0 0 4px; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .rag-status-value { margin: 0; font-size: 20px; font-weight: 700; color: #1e293b; }
        .rag-empty-state  { text-align: center; padding: 40px; color: #64748b; }
        .rag-hint { font-size: 13px; color: #94a3b8; margin: 4px 0 0; }
        .rag-settings-grid { display: grid; grid-template-columns: 1fr 280px; gap: 20px; }
        @media (max-width: 700px) { .rag-settings-grid { grid-template-columns: 1fr; } }
        .rag-field { margin-bottom: 20px; }
        .rag-label { display: block; font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
        .rag-input { width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #1e293b; background: #fff; box-sizing: border-box; }
        .rag-input:focus { outline: none; border-color: #1a1a2e; box-shadow: 0 0 0 3px rgba(26,26,46,.1); }
        .rag-select { appearance: auto; cursor: pointer; }
        .rag-field-hint { margin: 6px 0 0; font-size: 12px; color: #64748b; }
        .rag-color-row  { display: flex; align-items: center; gap: 10px; }
        .rag-color-input { width: 44px; height: 36px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; padding: 2px; }
        .rag-color-text  { max-width: 100px !important; }
        .rag-preview-card { text-align: center; }
        .rag-preview-area { display: flex; justify-content: center; align-items: center; height: 120px; background: #f8f9fc; border-radius: 8px; margin-top: 16px; }
        .rag-preview-btn  { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(0,0,0,.18); cursor: default; }
        ';
    }
}
