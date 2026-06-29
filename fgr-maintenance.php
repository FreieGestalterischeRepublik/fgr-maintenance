<?php
/**
 * Plugin Name:  FGR Maintenance
 * Description:  Ein Plugin der Freien Gestalterischen Republik. Zeigt Besuchern eine Platzhalterseite (Under Construction oder Wartung). Eingeloggte Benutzer sehen die Website normal.
 * Version:      1.0.0
 * Author:       Freie Gestalterische Republik
 * Author URI:   https://fgr.design
 * License:      GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Text Domain:  fgr-maintenance
 */

defined( 'ABSPATH' ) || exit;

define( 'FGR_MAINTENANCE_VERSION', '1.0.0' );

// ── MU-Plugin-Sync ────────────────────────────────────────────────────────────
// Installiert/aktualisiert das MU-Plugin von GitHub (function_exists-Guard: MU-Plugin definiert dieselbe Funktion)

if ( ! function_exists( 'fgr_mu_sync' ) ) {
    function fgr_mu_sync(): void {
        $url      = 'https://raw.githubusercontent.com/FreieGestalterischeRepublik/fgr-plugin-overview/main/fgr-plugin-overview.php';
        $dest_dir = WPMU_PLUGIN_DIR;
        $dest     = $dest_dir . '/fgr-plugin-overview.php';

        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        ] );

        if ( is_wp_error( $response ) ) return;
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return;

        $remote_content = wp_remote_retrieve_body( $response );
        if ( empty( $remote_content ) ) return;

        preg_match( '/\*\s+Version:\s+([\d.]+)/i', $remote_content, $matches );
        $remote_version = $matches[1] ?? '0';

        // Installierte Version lesen
        $installed_version = '0';
        if ( file_exists( $dest ) ) {
            $contents = file_get_contents( $dest );
            preg_match( '/\*\s+Version:\s+([\d.]+)/i', $contents, $m );
            $installed_version = $m[1] ?? '0';
        }

        if ( ! file_exists( $dest ) || version_compare( $remote_version, $installed_version, '>' ) ) {
            if ( ! is_dir( $dest_dir ) ) {
                wp_mkdir_p( $dest_dir );
            }
            file_put_contents( $dest, $remote_content );
            delete_transient( 'fgr_mu_update_info' );
        }
    }
}

// MU-Plugin bei Plugin-Aktivierung installieren/aktualisieren
register_activation_hook( __FILE__, 'fgr_mu_sync' );

// MU-Plugin nach Update eines FGR-Plugins aktualisieren
add_action( 'upgrader_process_complete', function ( $upgrader, array $hook_extra ): void {
    if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) return;
    if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) return;

    $fgr_plugins = [
        'fgr-mail-smtp/fgr-mail-smtp.php',
        'fgr-hide-login/fgr-hide-login.php',
        'fgr-maintenance/fgr-maintenance.php',
    ];

    $updated = array_merge(
        isset( $hook_extra['plugin'] )  ? (array) $hook_extra['plugin']  : [],
        isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : []
    );

    foreach ( $updated as $plugin_file ) {
        if ( in_array( $plugin_file, $fgr_plugins, true ) ) {
            fgr_mu_sync();
            return;
        }
    }
}, 10, 2 );
define( 'FGR_MAINTENANCE_BASENAME', plugin_basename( __FILE__ ) );

// Warnung wenn Plugin im falschen Ordner installiert ist
if ( is_admin() && str_ends_with( untrailingslashit( plugin_dir_path( __FILE__ ) ), '-main' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            . '<strong>FGR Maintenance:</strong> Das Plugin ist im falschen Ordner installiert '
            . '(<code>' . esc_html( basename( plugin_dir_path( __FILE__ ) ) ) . '</code>). '
            . 'Bitte das Plugin <strong>deaktivieren → löschen → neu installieren</strong>.'
            . '</p></div>';
    } );
}

// Settings-Klasse im Admin laden
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fgr-maintenance-settings.php';
        new FGR_Maintenance_Settings();
    }
} );

// ── Front-End-Intercept ───────────────────────────────────────────────────────

add_action( 'template_redirect', 'fgr_maintenance_intercept', 1 );

function fgr_maintenance_intercept(): void {
    // WP-CLI, Cron und AJAX nie blockieren
    if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) { return; }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return; }

    $opts = get_option( 'fgr_maintenance', [] );
    if ( empty( $opts['active'] ) ) { return; }

    // Eingeloggte Benutzer sehen die Seite normal
    if ( is_user_logged_in() ) { return; }

    $request_uri  = isset( $_SERVER['REQUEST_URI'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
        : '';
    $request_path = (string) parse_url( $request_uri, PHP_URL_PATH );

    // REST API durchlassen
    if ( str_contains( $request_uri, '/wp-json/' ) ) { return; }

    // wp-login.php durchlassen (Fallback, wenn FGR Hide Login nicht aktiv ist)
    if ( str_contains( $request_uri, 'wp-login.php' ) ) { return; }

    // FGR Hide Login: Custom-Login-Slug direkt aus der DB lesen.
    // Funktioniert unabhängig davon, ob das Plugin aktiv ist.
    // Wenn FGR Hide Login aktiv ist, stirbt es ohnehin vor template_redirect
    // für den Login-Slug – diese Prüfung ist der Fallback wenn es NICHT aktiv ist.
    $hide_slug = get_option( 'fgr_hide_login_slug', 'fgr-login' );
    if ( trim( $hide_slug ) !== '' ) {
        $login_path = '/' . ltrim( $hide_slug, '/' );
        if ( untrailingslashit( $request_path ) === untrailingslashit( $login_path ) ) {
            return;
        }
    }

    fgr_maintenance_render( $opts );
}

function fgr_maintenance_render( array $opts ): void {
    status_header( 503 );
    nocache_headers();
    header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
    header( 'Retry-After: 3600' );

    $template = $opts['template'] ?? 'aufbau';

    // Vorlage 3: eigenes HTML
    if ( 'custom' === $template && ! empty( $opts['custom_html'] ) ) {
        echo $opts['custom_html']; // phpcs:ignore -- beim Speichern sanitiert
        exit;
    }

    // Vorlage 1 & 2: einfache weiße Seite
    $text = ( 'wartung' === $template ) ? 'Wartungsarbeiten' : 'hier entsteht eine neue Webseite';

    ?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $text ); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, sans-serif;
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .fgr-msg {
            text-align: center;
            color: #333;
            font-size: 1.5rem;
            font-weight: 300;
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
    <div class="fgr-msg"><?php echo esc_html( $text ); ?></div>
</body>
</html><?php
    exit;
}
