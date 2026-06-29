<?php
/**
 * Plugin Name:  FGR Maintenance
 * Description:  Ein Plugin der Freien Gestalterischen Republik. Zeigt Besuchern eine Platzhalterseite (Under Construction oder Wartung). Eingeloggte Benutzer sehen die Website normal.
 * Version:      1.1.0
 * Author:       Freie Gestalterische Republik
 * Author URI:   https://fgr.design
 * License:      GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Text Domain:  fgr-maintenance
 */

defined( 'ABSPATH' ) || exit;

define( 'FGR_MAINTENANCE_VERSION', '1.1.0' );

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

    // Vorlage 1 & 2: FGR-Design (lila, mit Logo)
    if ( 'wartung' === $template ) {
        $title = 'Webseiten-Wartung';
        $h1    = 'Wir sind gleich zurück!';
        $p     = 'Diese Webseite wird gerade gewartet. Bitte schau in Kürze noch einmal vorbei. Vielen Dank für deine Geduld!';
    } else {
        $title = 'Info Webseite';
        $h1    = 'Hier entsteht etwas Neues!';
        $p     = 'Diese Webseite wird gerade entwickelt. Schauen Sie bald wieder vorbei – es lohnt sich!';
    }

    ?><!doctype html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $title ); ?></title>
<style>
html,body{margin:0;padding:0}
body{background-color:#5c1bdb;color:#fff;font-family:Helvetica,Arial,sans-serif;display:flex;align-items:center;align-content:center;justify-content:center;min-height:100vh;box-sizing:border-box;padding:30px}
div.info{width:100%;max-width:675px;padding-bottom:75px}
#powered_by{position:absolute;right:50px;bottom:40px;width:130px;height:auto}
#powered_by svg{width:100%;height:auto}
h1{font-size:3.25rem;margin:0 0 10px;font-weight:900}
h1 span{font-weight:100}
p{font-size:1.25rem;font-weight:300;line-height:1.5;margin:0}
small{font-size:.8325rem;display:block;margin-bottom:6.5px}
@media screen and (max-width:767px){
    div.info{max-width:375px}
    h1{font-size:2.5rem;margin:0}
    p{font-size:1.125rem}
    img{position:absolute;right:30px;bottom:30px;width:100px;height:100px}
}
</style>
</head>
<body>
    <div class="info">
        <h1><?php echo esc_html( $h1 ); ?></h1>
        <p><?php echo esc_html( $p ); ?></p>
    </div>
    <div id="powered_by">
        <small>Eine Webseite von:</small>
        <a href="https://fgr.design" target="_blank">
            <svg xmlns="http://www.w3.org/2000/svg" width="283" height="143.393" viewBox="0 0 283 143.393">
                <path d="M0,102V0H283V103H40.394L0,143.393Z" fill="#ffff6b"/>
                <path d="M9.18-9.36V0h-6.8V-25.7h17.6v5.76H9.18v4.824H19.368v5.76Zm31.392.252h-4.1v-5.58H46.944v11.7a15.869,15.869,0,0,1-5.058,2.7,19.045,19.045,0,0,1-5.958.936Q29.88.648,26.6-2.826t-3.276-9.882a16.636,16.636,0,0,1,1.512-7.4,10.516,10.516,0,0,1,4.32-4.662,13.339,13.339,0,0,1,6.66-1.584,11.658,11.658,0,0,1,7.362,2.2A9.376,9.376,0,0,1,46.656-18l-6.912.684a4.3,4.3,0,0,0-1.35-2.43,4.089,4.089,0,0,0-2.646-.774,4.473,4.473,0,0,0-3.96,1.89,10.239,10.239,0,0,0-1.3,5.742q0,4.032,1.44,5.868A5.5,5.5,0,0,0,36.54-5.184a8.471,8.471,0,0,0,4.032-.936ZM66.384,0,62.172-8.856H58.428V0H51.84V-25.7H61.992q5.04,0,7.65,2.07a7.279,7.279,0,0,1,2.61,6.066,9.324,9.324,0,0,1-.972,4.356,7.164,7.164,0,0,1-2.844,2.952L73.692,0ZM61.812-14.22a4.449,4.449,0,0,0,2.88-.756,2.932,2.932,0,0,0,.9-2.376,2.665,2.665,0,0,0-.99-2.286,5.02,5.02,0,0,0-3.006-.738H58.428v6.156ZM76.428,0V-6.876h6.264V0ZM87.48,0V-25.7H97.6q6.372,0,9.684,3.2t3.312,9.36q0,6.336-3.474,9.738T97.2,0Zm9.828-5.832q6.12,0,6.12-7.092a8.077,8.077,0,0,0-1.422-5.238,5.32,5.32,0,0,0-4.338-1.71H94.392v14.04Zm18,5.832V-25.7h18.4v5.688H122.04v4.1h10.944v5.688H122.04v4.536h12.42L133.884,0Zm30.636-10.44q-4.392-.828-6.39-2.7a6.711,6.711,0,0,1-2-5.148,7.126,7.126,0,0,1,2.682-5.976q2.682-2.088,7.65-2.088a12.684,12.684,0,0,1,7.6,2.016,7.834,7.834,0,0,1,3.168,5.832l-6.7.684a3.847,3.847,0,0,0-1.314-2.34,4.58,4.58,0,0,0-2.79-.72,4.867,4.867,0,0,0-2.556.558,1.809,1.809,0,0,0-.9,1.638,1.742,1.742,0,0,0,.684,1.44,5.174,5.174,0,0,0,2.2.828l3.492.684a12.535,12.535,0,0,1,6.318,2.754,6.823,6.823,0,0,1,2.034,5.2,7.435,7.435,0,0,1-2.808,6.246Q153.5.648,148.284.648q-5.256,0-8.1-2.286a9.012,9.012,0,0,1-3.132-6.822h6.876a3.766,3.766,0,0,0,1.35,2.7,5.294,5.294,0,0,0,3.258.864,4.907,4.907,0,0,0,2.736-.63,2.092,2.092,0,0,0,.936-1.854,1.938,1.938,0,0,0-.7-1.584,4.641,4.641,0,0,0-2.142-.828ZM163.3,0V-25.7h6.912V0Zm28.872-9.108h-4.1v-5.58H198.54v11.7a15.869,15.869,0,0,1-5.058,2.7,19.045,19.045,0,0,1-5.958.936q-6.048,0-9.324-3.474t-3.276-9.882a16.635,16.635,0,0,1,1.512-7.4,10.516,10.516,0,0,1,4.32-4.662,13.339,13.339,0,0,1,6.66-1.584,11.658,11.658,0,0,1,7.362,2.2A9.376,9.376,0,0,1,198.252-18l-6.912.684a4.3,4.3,0,0,0-1.35-2.43,4.089,4.089,0,0,0-2.646-.774,4.473,4.473,0,0,0-3.96,1.89,10.239,10.239,0,0,0-1.3,5.742q0,4.032,1.44,5.868a5.5,5.5,0,0,0,4.608,1.836,8.471,8.471,0,0,0,4.032-.936ZM219.24,0l-9.252-14.724V0h-6.552V-25.7h6.048l9.252,14.724V-25.7h6.552V0Z" transform="translate(28 64)"/>
            </svg>
        </a>
    </div>
</body>
</html><?php
    exit;
}
