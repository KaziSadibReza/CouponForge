<?php
namespace CouponForge\Core;

use CouponForge\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Generator {

    public function __construct() {
        // Trigger on Order Complete
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order' ], 10, 1 );
        
        // Show in My Account > Orders
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_coupon_in_account' ], 10, 1 );
        
        // Sync Usage (When a coupon is used in a new order)
        add_action( 'woocommerce_order_status_processing', [ $this, 'sync_usage_on_payment' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'sync_usage_on_payment' ] );
    }

    /**
     * Main Processing Logic
     */
    public function process_order( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // check if we already generated coupons for this order (Prevent Duplicates)
        if ( $order->get_meta( '_coupon_forge_generated' ) ) {
            return;
        }

        // 1. Get All Active Rules
        $rules = $this->get_active_rules();
        if ( empty( $rules ) ) return;

        // 2. Get Order Product IDs
        $order_product_ids = [];
        foreach ( $order->get_items() as $item ) {
            $order_product_ids[] = $item->get_product_id();
            $order_product_ids[] = $item->get_variation_id(); // Support Variations
        }
        $order_product_ids = array_filter( array_unique( $order_product_ids ) );

        $coupons_created = 0;

        // 3. Match Rules
        foreach ( $rules as $rule ) {
            $rule_products = !empty($rule->product_ids) ? json_decode( $rule->product_ids, true ) : [];
            
            // If rule has no products, it applies to ALL products
            $match = empty($rule_products) || !empty( array_intersect( $order_product_ids, $rule_products ) );
            
            if ( $match ) {
                $this->create_coupon( $order, $rule );
                $coupons_created++;
            }
        }

        // Mark order as processed if coupons were made
        if ( $coupons_created > 0 ) {
            $order->update_meta_data( '_coupon_forge_generated', 'yes' );
            $order->save();
        }
    }

    /**
     * Fetch Rules from DB (Optimized)
     */
    private function get_active_rules() {
        global $wpdb;
        $table = Schema::get_rules_table();
        // Use prepared statement for safety, though query is simple
        return $wpdb->get_results( "SELECT * FROM $table WHERE is_active = 1" );
    }

    /**
     * Create the Coupon
     */
    private function create_coupon( \WC_Order $order, $rule ) {
        
        // 1. Generate Unique Code
        $first_name = preg_replace( '/[^a-zA-Z0-9]/', '', $order->get_billing_first_name() );
        $last_name  = preg_replace( '/[^a-zA-Z0-9]/', '', $order->get_billing_last_name() );
        $base_code  = strtoupper( substr( $first_name . $last_name, 0, 10 ) );
        $unique_suf = strtoupper( wp_generate_password( 4, false ) );
        $coupon_code = $base_code . '-' . $unique_suf;

        // 2. Calculate Expiry
        $expiry_date = null;
        if ( $rule->expiry_days > 0 ) {
            $expiry_date = date( 'Y-m-d', strtotime( "+{$rule->expiry_days} days" ) );
        }

        // 3. Create WooCommerce Coupon Object
        $coupon = new \WC_Coupon();
        $coupon->set_code( $coupon_code );
        $coupon->set_amount( $rule->coupon_amount );
        $coupon->set_discount_type( $rule->coupon_type ); // percent, fixed_cart, etc.
        $coupon->set_description( sprintf( __( 'Generated for order #%d', 'coupon-forge' ), $order->get_id() ) );
        
        if ( $expiry_date ) {
            $coupon->set_date_expires( $expiry_date );
        }

        // Usage Restrictions (Email restriction)
        $coupon->set_email_restrictions( [ $order->get_billing_email() ] );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        
        // Save (Standard WC Way)
        $wc_coupon_id = $coupon->save();

        // 4. Log to Custom History Table
        if ( $wc_coupon_id ) {
            global $wpdb;
            $wpdb->insert(
                Schema::get_history_table(),
                [
                    'order_id'       => $order->get_id(),
                    'wc_coupon_id'   => $wc_coupon_id,
                    'coupon_code'    => $coupon_code,
                    'customer_email' => $order->get_billing_email(),
                    'rule_id'        => $rule->id,
                    'is_used'        => 0,
                    'expires_at'     => $expiry_date ? $expiry_date . ' 23:59:59' : null
                ],
                [ '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
            );

            // 5. Send Email
            Mailer::send_coupon_email( $order, $coupon_code, $rule );
        }
    }

    /**
     * 2. Display on "My Account > Orders" Page
     */
    public function display_coupon_in_account( $order ) {
        global $wpdb;
        $table = Schema::get_history_table();
        $order_id = $order->get_id();

        $coupons = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE order_id = %d", $order_id ) );

        if ( empty( $coupons ) ) {
            return;
        }

        // Get settings from database
        $defaults = [
            'template_title'        => '🎁 Your Rewards',
            'template_message'      => 'Thanks for your order. Use this code:',
            'template_bg_color'     => '#fef2f2',
            'template_border_color' => '#fecaca',
            'template_text_color'   => '#991b1b',
            'template_code_color'   => '#dc2626',
        ];
        $settings = get_option( 'coupon_forge_settings', $defaults );
        $settings = wp_parse_args( $settings, $defaults );

        // Load template
        $template_path = COUPON_FORGE_PATH . 'templates/my-account-coupons.php';
        
        if ( file_exists( $template_path ) ) {
            include $template_path;
        }
    }

    /**
     * 3. Sync Usage (Check if any of our coupons were used)
     */
    public function sync_usage_on_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $used_coupons = $order->get_coupon_codes(); // Get codes used in THIS order

        if ( empty( $used_coupons ) ) return;

        global $wpdb;
        $table = Schema::get_history_table();

        foreach ( $used_coupons as $code ) {
            // Check if this code belongs to us
            $exists = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE coupon_code = %s", $code ) );
            
            if ( $exists ) {
                // Mark as used
                $wpdb->update( 
                    $table, 
                    [ 'is_used' => 1 ], 
                    [ 'id' => $exists->id ] 
                );
            }
        }
    }
}