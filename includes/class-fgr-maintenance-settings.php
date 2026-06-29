<?php
defined( 'ABSPATH' ) || exit;

class FGR_Maintenance_Settings {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_submenu' ] );
        add_action( 'admin_init',             [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_editor' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'fgr-plugins',
            'FGR Maintenance',
            'Maintenance',
            'manage_options',
            'fgr-maintenance',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'fgr_maintenance_group',
            'fgr_maintenance',
            [
                'sanitize_callback' => [ $this, 'sanitize_options' ],
                'default'           => [ 'active' => false, 'template' => 'aufbau', 'custom_html' => '' ],
            ]
        );
    }

    public function sanitize_options( $input ): array {
        $clean             = [];
        $clean['active']   = ! empty( $input['active'] );
        $clean['template'] = in_array( $input['template'] ?? '', [ 'aufbau', 'wartung', 'custom' ], true )
            ? $input['template']
            : 'aufbau';

        // HTML: <script> und Event-Handler entfernen (analog zu rsuc_sanitize_html_lite)
        $html = (string) ( $input['custom_html'] ?? '' );
        $html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
        $html = preg_replace( '#<script\b[^>]*/?>#i', '', $html );
        $html = preg_replace( '#\son\w+\s*=\s*"[^"]*"#i', '', $html );
        $html = preg_replace( "#\son\w+\s*=\s*'[^']*'#i", '', $html );
        $html = preg_replace( '#\son\w+\s*=\s*[^\s>]+#i', '', $html );
        $html = preg_replace( '#(href|src|action|formaction)\s*=\s*"\s*javascript:[^"]*"#i', '$1=""', $html );
        $html = preg_replace( "#(href|src|action|formaction)\s*=\s*'\s*javascript:[^']*'#i", '$1=""', $html );
        $clean['custom_html'] = $html;

        // Secret-Link
        $clean['secret_enabled'] = ! empty( $input['secret_enabled'] );
        $secret                  = sanitize_text_field( $input['secret'] ?? 'fgr-secret' );
        $clean['secret']         = $secret !== '' ? $secret : 'fgr-secret';

        // IP-Whitelist: jede Zeile einzeln sanitieren
        $lines = preg_split( '/\r\n|\r|\n/', (string) ( $input['ip_whitelist'] ?? '' ) );
        $clean['ip_whitelist'] = implode( "\n", array_map( 'sanitize_text_field', $lines ) );

        return $clean;
    }

    public function enqueue_editor( string $hook ): void {
        // Hook-Name für Subseiten unter fgr-plugins: fgr-plugins_page_fgr-maintenance
        if ( 'fgr-plugins_page_fgr-maintenance' !== $hook ) { return; }

        // WordPress-eigenen CodeMirror-Editor laden (HTML-Modus)
        $settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
        if ( false === $settings ) { return; } // Benutzer hat Code-Editor deaktiviert

        // Editor-Instanz global speichern damit refresh() möglich ist
        wp_add_inline_script(
            'code-editor',
            sprintf(
                'var fgrCodeEditor = null;
                var fgrCodeSettings = %s;
                function fgrInitEditor() {
                    var el = document.getElementById( "fgr_custom_html" );
                    if ( ! el ) { return; }
                    if ( fgrCodeEditor ) {
                        // Bereits initialisiert: Layout neu berechnen (behebt Gutter-Bug bei hidden→visible)
                        fgrCodeEditor.codemirror.refresh();
                    } else {
                        fgrCodeEditor = wp.codeEditor.initialize( el, fgrCodeSettings );
                    }
                }',
                wp_json_encode( $settings )
            )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $opts           = get_option( 'fgr_maintenance', [ 'active' => false, 'template' => 'aufbau', 'custom_html' => '' ] );
        $active         = ! empty( $opts['active'] );
        $template       = $opts['template'] ?? 'aufbau';
        $html           = $opts['custom_html'] ?? '';
        $secret_enabled = ! empty( $opts['secret_enabled'] );
        $secret         = $opts['secret'] ?? 'fgr-secret';
        $ip_whitelist   = $opts['ip_whitelist'] ?? '';
        $current_ip     = function_exists( 'fgr_maintenance_get_ip' ) ? fgr_maintenance_get_ip() : '';
        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-hammer" style="font-size:28px;height:28px;margin-right:6px;vertical-align:middle;color:#2271b1;"></span>
                FGR Maintenance
            </h1>
            <p style="color:#646970;">Zeigt Besuchern eine Platzhalterseite. Eingeloggte Benutzer sehen die Website normal.</p>

            <?php if ( $active ) : ?>
            <div class="notice notice-warning" style="border-left-color:#d63638;">
                <p><strong>Maintenance ist aktiv</strong> – Besucher sehen aktuell die Platzhalterseite.</p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'fgr_maintenance_group' ); ?>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <label>
                                <input type="checkbox" name="fgr_maintenance[active]" value="1" <?php checked( $active ); ?>>
                                <strong>Maintenance-Modus aktivieren</strong>
                            </label>
                            <p class="description">Wenn aktiv, sehen nicht eingeloggte Besucher die gewählte Vorlage.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Vorlage</th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin-bottom:14px;">
                                    <input type="radio" name="fgr_maintenance[template]" value="aufbau" <?php checked( $template, 'aufbau' ); ?>>
                                    <strong>Vorlage 1: Website in Aufbau</strong>
                                    <br>
                                    <span style="color:#646970;margin-left:20px;">Weiße Seite, zentrierter Text: „hier entsteht eine neue Webseite"</span>
                                </label>
                                <label style="display:block;margin-bottom:14px;">
                                    <input type="radio" name="fgr_maintenance[template]" value="wartung" <?php checked( $template, 'wartung' ); ?>>
                                    <strong>Vorlage 2: Wartungsarbeiten</strong>
                                    <br>
                                    <span style="color:#646970;margin-left:20px;">Weiße Seite, zentrierter Text: „Wartungsarbeiten"</span>
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="fgr_maintenance[template]" value="custom" <?php checked( $template, 'custom' ); ?>>
                                    <strong>Vorlage 3: Eigenes HTML</strong>
                                    <br>
                                    <span style="color:#646970;margin-left:20px;">Vollständig selbst gestaltete Seite</span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr id="fgr_custom_row" <?php echo ( 'custom' !== $template ) ? 'style="display:none"' : ''; ?>>
                        <th scope="row">HTML-Editor</th>
                        <td>
                            <textarea
                                id="fgr_custom_html"
                                name="fgr_maintenance[custom_html]"
                                rows="24"
                                style="width:100%;font-family:monospace;font-size:13px;"
                            ><?php echo esc_textarea( $html ); ?></textarea>
                            <p class="description">
                                Vollständiges HTML-Dokument (<code>&lt;!DOCTYPE html&gt;</code> …).
                                <code>&lt;script&gt;</code> und <code>on*</code>-Event-Handler werden beim Speichern automatisch entfernt.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Secret-Link</th>
                        <td>
                            <label>
                                <input type="checkbox" name="fgr_maintenance[secret_enabled]" value="1" <?php checked( $secret_enabled ); ?>>
                                <strong>Secret-Link aktivieren</strong>
                            </label>
                            <p class="description">Besucher die diesen Link kennen, können die Maintenance-Seite umgehen. Ein Cookie merkt sich den Browser für 30 Tage.</p>
                            <br>
                            <label for="fgr_secret_word" style="font-weight:600;">Secret-Wort:</label>
                            <input type="text" id="fgr_secret_word" name="fgr_maintenance[secret]"
                                   value="<?php echo esc_attr( $secret ); ?>"
                                   style="width:200px;margin-left:8px;">
                            <p class="description" style="margin-top:6px;">
                                Aktueller Bypass-Link:
                                <code><?php echo esc_html( home_url( '?' . $secret ) ); ?></code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">IP-Whitelist</th>
                        <td>
                            <textarea
                                id="fgr_ip_whitelist"
                                name="fgr_maintenance[ip_whitelist]"
                                rows="6"
                                style="width:100%;max-width:400px;font-family:monospace;font-size:13px;"
                            ><?php echo esc_textarea( $ip_whitelist ); ?></textarea>
                            <p class="description">
                                Eine IP-Adresse pro Zeile. Diese IPs sehen die Website immer normal.
                                <?php if ( $current_ip ) : ?>
                                    <br>Deine aktuelle IP:
                                    <code id="fgr-current-ip"><?php echo esc_html( $current_ip ); ?></code>
                                    <button type="button" class="button button-small" id="fgr-add-ip"
                                            style="margin-left:6px;">Hinzufügen</button>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button( 'Einstellungen speichern' ); ?>
            </form>
        </div>

        <script>
        ( function () {
            var radios = document.querySelectorAll( 'input[name="fgr_maintenance[template]"]' );
            var row    = document.getElementById( 'fgr_custom_row' );
            if ( ! row ) { return; }
            radios.forEach( function ( r ) {
                r.addEventListener( 'change', function () {
                    var show = ( this.value === 'custom' );
                    row.style.display = show ? '' : 'none';
                    // Editor erst initialisieren/refreshen wenn sichtbar
                    if ( show && typeof fgrInitEditor === 'function' ) {
                        fgrInitEditor();
                    }
                } );
            } );
            // Seite lädt mit Vorlage 3 bereits gewählt → sofort initialisieren
            if ( row.style.display !== 'none' && typeof fgrInitEditor === 'function' ) {
                fgrInitEditor();
            }
        } )();

        // "Hinzufügen"-Button: eigene IP in die Whitelist-Textarea eintragen
        ( function () {
            var btn = document.getElementById( 'fgr-add-ip' );
            var ta  = document.getElementById( 'fgr_ip_whitelist' );
            var ip  = document.getElementById( 'fgr-current-ip' );
            if ( ! btn || ! ta || ! ip ) { return; }
            btn.addEventListener( 'click', function () {
                var val    = ta.value.trim();
                var ipText = ip.textContent.trim();
                // Nur hinzufügen wenn noch nicht vorhanden
                var lines = val ? val.split( '\n' ) : [];
                if ( lines.indexOf( ipText ) === -1 ) {
                    ta.value = val ? val + '\n' + ipText : ipText;
                }
                btn.textContent = 'Hinzugefügt ✓';
                btn.disabled    = true;
            } );
        } )();
        </script>
        <?php
    }
}
