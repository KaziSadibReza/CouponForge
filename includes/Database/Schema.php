<?php
namespace CouponForge\Database;

defined( 'ABSPATH' ) || exit;

class Schema {

    /**
     * Dynamic Table Names
     */
    public static function get_rules_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'couponforge_rules';
    }

    public static function get_history_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'couponforge_history';
    }

    public static function get_email_templates_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'cf_email_templates';
    }

    /**
     * Create Tables
     * Optimized for high-speed lookups on Order ID and Email.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $rules_table     = self::get_rules_table();
        $history_table   = self::get_history_table();

        // Table 1: Rules (Configuration)
        $sql_rules = "CREATE TABLE $rules_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            product_ids longtext NOT NULL COMMENT 'JSON array of product IDs',
            coupon_amount decimal(10,2) NOT NULL,
            coupon_type varchar(50) DEFAULT 'fixed_cart',
            expiry_days int(11) DEFAULT 7,
            template_id bigint(20) unsigned NULL,
            is_active tinyint(1) DEFAULT 1,
            use_per_product_discount tinyint(1) DEFAULT 0,
            product_discounts longtext NULL COMMENT 'JSON array of per-product discounts',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_active (is_active),
            KEY template_id (template_id)
        ) $charset_collate;";

        // Table 2: History (Logs & Sync)
        $sql_history = "CREATE TABLE $history_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            wc_coupon_id bigint(20) unsigned NOT NULL,
            coupon_code varchar(100) NOT NULL,
            customer_email varchar(100) NOT NULL,
            rule_id bigint(20) unsigned NOT NULL,
            is_used tinyint(1) DEFAULT 0,
            expires_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY customer_email (customer_email),
            KEY coupon_code (coupon_code)
        ) $charset_collate;";

        // Table 3: Email Templates
        $templates_table = self::get_email_templates_table();
        $sql_templates = "CREATE TABLE $templates_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            heading varchar(255) NOT NULL,
            message longtext NOT NULL,
            footer_text varchar(255) DEFAULT '',
            primary_color varchar(7) DEFAULT '#d6336c',
            background_color varchar(7) DEFAULT '#f7f7f7',
            is_default tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_default (is_default)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_rules );
        dbDelta( $sql_history );
        dbDelta( $sql_templates );

        // Create default template if none exists
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM $templates_table" );
        if ( $count == 0 ) {
            $wpdb->insert( $templates_table, [
                'name'             => 'Default Template',
                'subject'          => 'Your Exclusive Coupon is Ready!',
                'heading'          => 'Thank You for Your Order!',
                'message'          => "We appreciate your business. Here's a special coupon code just for you:",
                'footer_text'      => 'Questions? Contact us anytime.',
                'primary_color'    => '#d6336c',
                'background_color' => '#f7f7f7',
                'is_default'       => 1,
            ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        }

        update_option( 'coupon_forge_db_version', '1.0.0' );
    }

    /**
     * Drop Tables (For Uninstaller)
     */
    public static function drop_tables(): void {
        global $wpdb;
        $rules_table = self::get_rules_table();
        $history_table = self::get_history_table();
        $templates_table = self::get_email_templates_table();

        $wpdb->query( "DROP TABLE IF EXISTS $rules_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $history_table" );
        $wpdb->query( "DROP TABLE IF EXISTS $templates_table" );
    }
}