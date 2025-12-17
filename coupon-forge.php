<?php
/**
 * Plugin Name: CouponForge
 * Plugin URI:  https://github.com/KaziSadibReza/CouponForge
 * Description: High-performance, conditional coupon generator for WooCommerce with a modern admin interface.
 * Version:     1.0.0
 * Author:      Kazi Sadib Reza
 * Author URI:  https://github.com/KaziSadibReza
 * Text Domain: coupon-forge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CouponForge {

    /**
     * Plugin Constants
     */
    const VERSION     = '1.0.0';
    const TEXT_DOMAIN = 'coupon-forge'; 

    /**
     * Singleton Instance
     */
    private static $instance = null;

    /**
     * Main Instance
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define Constants
     */
    private function define_constants() {
        define( 'COUPON_FORGE_VERSION', self::VERSION );
        define( 'COUPON_FORGE_PATH', plugin_dir_path( __FILE__ ) );
        define( 'COUPON_FORGE_URL', plugin_dir_url( __FILE__ ) );
        define( 'COUPON_FORGE_ASSETS', COUPON_FORGE_URL . 'assets/' );
        
        // DEV MODE FLAG: 
        // true  = Load from http://localhost:3000 (Hot Reload)
        // false = Load from /assets (Production Build)
        define( 'COUPON_FORGE_DEV_MODE', true ); 
    }

    /**
     * Include Required Files
     */
    private function includes() {
        require_once COUPON_FORGE_PATH . 'includes/Database/Schema.php';
        require_once COUPON_FORGE_PATH . 'includes/Core/Mailer.php';
        require_once COUPON_FORGE_PATH . 'includes/Core/Generator.php';
        require_once COUPON_FORGE_PATH . 'includes/Api/AdminController.php';
    }

    /**
     * Initialize Hooks
     */
    private function init_hooks() {
        register_activation_hook( __FILE__, [ 'CouponForge\Database\Schema', 'create_tables' ] );

        add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_links' ] );

        // Initialize Core Logic
        new \CouponForge\Core\Generator();
        new \CouponForge\Api\AdminController();
    }

    /**
     * Add Settings link to plugin actions
     */
    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=coupon-forge' ) . '">' . __( 'Settings', self::TEXT_DOMAIN ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Plugins Loaded Handler
     */
    public function on_plugins_loaded() {
        load_plugin_textdomain( 
            self::TEXT_DOMAIN, 
            false, 
            dirname( plugin_basename( __FILE__ ) ) . '/languages' 
        );
    }

    /**
     * Declare HPOS Support
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    /**
     * Register Admin Menu
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'CouponForge', self::TEXT_DOMAIN ),
            __( 'CouponForge', self::TEXT_DOMAIN ),
            'manage_options',
            self::TEXT_DOMAIN, // Page Slug
            [ $this, 'render_admin_app' ],
            'dashicons-tickets-alt', 
            56
        );
    }

    /**
     * Render the Admin SPA Container
     */
    public function render_admin_app() {
        echo '<div class="wrap">';
        echo '<div id="coupon-forge-admin-app"></div>';
        echo '</div>';
    }

    /**
     * Enqueue Admin Assets
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_' . self::TEXT_DOMAIN !== $hook ) {
            return;
        }

        $script_handle = self::TEXT_DOMAIN . '-app';
        $style_handle  = self::TEXT_DOMAIN . '-styles';

        $localize_data = [
            'apiUrl'  => esc_url_raw( rest_url( 'coupon-forge/v1/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'colors'  => [ 'primary' => '#e8b8b8' ],
            'i18n'    => [
                'saved' => __( 'Settings Saved', self::TEXT_DOMAIN ),
                'error' => __( 'Something went wrong', self::TEXT_DOMAIN ),
            ]
        ];

        // --- DEV MODE (Vite HMR) ---
        if ( defined( 'COUPON_FORGE_DEV_MODE' ) && COUPON_FORGE_DEV_MODE ) {
            
            // 1. CRITICAL: Inject React Refresh Preamble
            // This fixes the "can't detect preamble" error
            add_action('admin_head', function() {
                echo '<script type="module">
                    import RefreshRuntime from "http://localhost:3000/@react-refresh"
                    RefreshRuntime.injectIntoGlobalHook(window)
                    window.$RefreshReg$ = () => {}
                    window.$RefreshSig$ = () => (type) => type
                    window.__vite_plugin_react_preamble_installed__ = true
                </script>';
            });

            // 2. Load Vite Client
            wp_enqueue_script( 'vite-client', 'http://localhost:3000/@vite/client', [], null, false );
            
            // 3. Load Entry Point
            wp_enqueue_script( $script_handle, 'http://localhost:3000/src/main.tsx', ['vite-client'], null, true );
            
            // 4. Inject Settings
            wp_localize_script( $script_handle, 'CouponForgeSettings', $localize_data );

            // 5. Add type="module"
            add_filter('script_loader_tag', function($tag, $handle, $src) use ($script_handle) {
                if ($handle === $script_handle || $handle === 'vite-client') {
                    return '<script type="module" src="' . esc_url($src) . '"></script>';
                }
                return $tag;
            }, 10, 3);

        } else {
            // --- PRODUCTION MODE ---
            wp_enqueue_script( $script_handle, COUPON_FORGE_ASSETS . 'js/main.js', [ 'wp-element', 'wp-i18n' ], self::VERSION, true );
            wp_enqueue_style( $style_handle, COUPON_FORGE_ASSETS . 'css/style.css', [], self::VERSION );
            wp_localize_script( $script_handle, 'CouponForgeSettings', $localize_data );
        }
    }
}

/**
 * Boot the Plugin
 */
function coupon_forge_init() {
    return CouponForge::instance();
}
coupon_forge_init();