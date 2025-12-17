<?php
namespace CouponForge\Api;

use CouponForge\Database\Schema;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class AdminController {

    /**
     * Namespace for the API
     */
    const NAMESPACE = 'coupon-forge/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST Routes
     */
    public function register_routes(): void {
        
        // 1. GET Dashboard Stats
        register_rest_route( self::NAMESPACE, '/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // 2. GET History (Paginated)
        register_rest_route( self::NAMESPACE, '/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_history' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'page' => [
                    'validate_callback' => function($param) { return is_numeric($param); },
                    'default' => 1
                ]
            ]
        ] );

        // 3. Rules Routes (List & Save)
        register_rest_route( self::NAMESPACE, '/rules', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_rules' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_rule' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        ] );

        // 4. Product Search (For the React Autocomplete)
        register_rest_route( self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'search_products' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // 4.5. Delete History Item
        register_rest_route( self::NAMESPACE, '/history/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_history_item' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // 5. Email Templates Routes
        register_rest_route( self::NAMESPACE, '/email-templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_email_templates' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_email_template' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        ] );

        register_rest_route( self::NAMESPACE, '/email-templates/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_email_template' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_email_template' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        ] );

        // 6. Settings Routes
        register_rest_route( self::NAMESPACE, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_settings' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        ] );
    }

    /**
     * Permission Check: Admins Only
     */
    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Endpoint: Get Dashboard Stats
     */
    public function get_stats( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $history_table = Schema::get_history_table();
        $rules_table   = Schema::get_rules_table();

        // High-performance count queries
        $total_generated = $wpdb->get_var( "SELECT COUNT(id) FROM $history_table" );
        $total_used      = $wpdb->get_var( "SELECT COUNT(id) FROM $history_table WHERE is_used = 1" );
        $active_rules    = $wpdb->get_var( "SELECT COUNT(id) FROM $rules_table WHERE is_active = 1" );

        return rest_ensure_response( [
            'total_generated' => (int) $total_generated,
            'total_used'      => (int) $total_used,
            'active_rules'    => (int) $active_rules,
            'recent_activity' => __( 'Stats updated successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }

    /**
     * Endpoint: Get History
     */
    public function get_history( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table = Schema::get_history_table();
        
        $page     = (int) $request->get_param( 'page' );
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $results = $wpdb->get_results( 
            $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) 
        );

        // Convert is_used to boolean and fetch coupon details from WooCommerce
        foreach ( $results as $key => $row ) {
            $results[$key]->is_used = (bool) $row->is_used;
            
            // Fetch coupon details from WooCommerce
            if ( ! empty( $row->wc_coupon_id ) ) {
                $coupon = new \WC_Coupon( $row->wc_coupon_id );
                if ( $coupon && $coupon->get_id() ) {
                    $results[$key]->coupon_amount = $coupon->get_amount();
                    $results[$key]->coupon_type = $coupon->get_discount_type();
                } else {
                    // Coupon doesn't exist in WooCommerce (might have been deleted externally)
                    $results[$key]->coupon_amount = null;
                    $results[$key]->coupon_type = null;
                }
            } else {
                $results[$key]->coupon_amount = null;
                $results[$key]->coupon_type = null;
            }
        }

        $total_rows = $wpdb->get_var( "SELECT COUNT(id) FROM $table" );

        return rest_ensure_response( [
            'data'  => $results,
            'meta'  => [
                'current_page' => $page,
                'total_pages'  => ceil( $total_rows / $per_page ),
                'total_items'  => (int) $total_rows
            ]
        ] );
    }

    /**
     * Endpoint: Delete History Item
     */
    public function delete_history_item( \WP_REST_Request $request ) {
        global $wpdb;
        $table = Schema::get_history_table();
        $id = intval( $request->get_param( 'id' ) );

        if ( empty( $id ) ) {
            return new \WP_Error( 
                'missing_id', 
                __( 'History ID is required.', \CouponForge::TEXT_DOMAIN ), 
                [ 'status' => 400 ] 
            );
        }

        // Get the history item to find the WooCommerce coupon ID
        $history_item = $wpdb->get_row( 
            $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) 
        );

        if ( ! $history_item ) {
            return new \WP_Error( 
                'not_found', 
                __( 'History item not found.', \CouponForge::TEXT_DOMAIN ), 
                [ 'status' => 404 ] 
            );
        }

        // Delete the WooCommerce coupon
        if ( ! empty( $history_item->wc_coupon_id ) ) {
            wp_delete_post( $history_item->wc_coupon_id, true );
        }

        // Add order note if order exists
        if ( ! empty( $history_item->order_id ) ) {
            $order = wc_get_order( $history_item->order_id );
            if ( $order ) {
                $note = sprintf(
                    __( 'Coupon "%s" was deleted from CouponForge by admin.', \CouponForge::TEXT_DOMAIN ),
                    $history_item->coupon_code
                );
                $order->add_order_note( $note );
            }
        }

        // Delete from history table
        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        if ( $deleted === false ) {
            return rest_ensure_response( new \WP_Error( 
                'db_error', 
                __( 'Could not delete history item.', \CouponForge::TEXT_DOMAIN ), 
                [ 'status' => 500 ] 
            ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Coupon deleted successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }

    /**
     * Endpoint: Get All Rules
     */
    public function get_rules( WP_REST_Request $request ) {
        global $wpdb;
        $table = Schema::get_rules_table();
        
        $results = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
        
        // Decode product_ids for frontend use
        foreach ( $results as $key => $row ) {
            $results[$key]->product_ids = ! empty( $row->product_ids ) ? json_decode( $row->product_ids ) : [];
            $results[$key]->is_active   = (bool) $row->is_active;
            $results[$key]->use_per_product_discount = (bool) $row->use_per_product_discount;
            $results[$key]->product_discounts = ! empty( $row->product_discounts ) ? json_decode( $row->product_discounts, true ) : [];
        }

        return rest_ensure_response( $results );
    }

    /**
     * Endpoint: Save Rule
     */
    public function save_rule( WP_REST_Request $request ) {
        global $wpdb;
        $table = Schema::get_rules_table();
        
        $id = $request->get_param( 'id' );
        $name = sanitize_text_field( $request->get_param( 'name' ) );
        
        if ( empty( $name ) ) {
            return new WP_Error( 
                'missing_name', 
                __( 'Rule name is required.', \CouponForge::TEXT_DOMAIN ), 
                [ 'status' => 400 ] 
            );
        }

        // Prepare Data
        $data = [
            'name'          => $name,
            'product_ids'   => json_encode( $request->get_param( 'product_ids' ) ), // Save as JSON string
            'coupon_amount' => floatval( $request->get_param( 'coupon_amount' ) ),
            'coupon_type'   => sanitize_text_field( $request->get_param( 'coupon_type' ) ),
            'expiry_days'   => intval( $request->get_param( 'expiry_days' ) ),
            'template_id'   => $request->get_param( 'template_id' ) ? intval( $request->get_param( 'template_id' ) ) : null,
            'is_active'     => $request->get_param( 'is_active' ) ? 1 : 0,
            'use_per_product_discount' => $request->get_param( 'use_per_product_discount' ) ? 1 : 0,
            'product_discounts' => $request->get_param( 'product_discounts' ) ? json_encode( $request->get_param( 'product_discounts' ) ) : null
        ];

        // Format for DB
        $format = [ '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%s' ];

        if ( ! empty( $id ) ) {
            // Update
            $updated = $wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
            if ( $updated === false ) {
                return new WP_Error( 'db_error', 'Could not update rule', [ 'status' => 500 ] );
            }
        } else {
            // Insert
            $inserted = $wpdb->insert( $table, $data, $format );
            if ( ! $inserted ) {
                return new WP_Error( 'db_error', 'Could not create rule', [ 'status' => 500 ] );
            }
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Rule saved successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }

    /**
     * Search Products (Lightweight & HPOS Compatible)
     * Register route: 'coupon-forge/v1/products'
     */
    public function search_products( \WP_REST_Request $request ) {
        $search  = sanitize_text_field( $request->get_param( 'search' ) );
        $include = $request->get_param( 'include' );
        
        $args = [
            'limit'  => 20,
            'status' => 'publish',
            'return' => 'ids', // We fetch objects manually for speed
        ];

        if ( ! empty( $include ) ) {
            $args['include'] = explode( ',', $include );
        } elseif ( ! empty( $search ) ) {
            $args['s'] = $search;
        } else {
            return rest_ensure_response([]);
        }

        // Use WC_Product_Query for compatibility
        $query = new \WC_Product_Query( $args );
        $products = $query->get_products();
        
        $results = [];
        foreach ( $products as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            
            $variations = [];
            // If it's a variable product, fetch variations
            if ( $product->get_type() === 'variable' ) {
                $variation_ids = $product->get_children();
                foreach ( $variation_ids as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $variations[] = [
                            'id'   => $variation->get_id(),
                            'name' => $variation->get_formatted_name(),
                        ];
                    }
                }
            }
            
            // Format for Frontend
            $results[] = [
                'id'         => $product->get_id(),
                'name'       => $product->get_formatted_name(), // Includes Variation attributes!
                'type'       => $product->get_type(),
                'variations' => $variations,
            ];
        }

        return rest_ensure_response( $results );
    }

    /**
     * Get all email templates
     */
    public function get_email_templates( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_email_templates';

        $templates = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY is_default DESC, id DESC",
            ARRAY_A
        );

        // Convert string booleans to actual booleans
        foreach ( $templates as &$template ) {
            $template['is_default'] = (bool) $template['is_default'];
        }

        return rest_ensure_response( $templates );
    }

    /**
     * Create new email template
     */
    public function create_email_template( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_email_templates';

        $data = [
            'name'             => sanitize_text_field( $request->get_param( 'name' ) ),
            'subject'          => sanitize_text_field( $request->get_param( 'subject' ) ),
            'heading'          => sanitize_text_field( $request->get_param( 'heading' ) ),
            'message'          => wp_kses_post( $request->get_param( 'message' ) ),
            'footer_text'      => sanitize_text_field( $request->get_param( 'footer_text' ) ),
            'primary_color'    => sanitize_hex_color( $request->get_param( 'primary_color' ) ),
            'background_color' => sanitize_hex_color( $request->get_param( 'background_color' ) ),
            'is_default'       => (bool) $request->get_param( 'is_default' ),
        ];

        // If setting as default, unset other defaults
        if ( $data['is_default'] ) {
            $wpdb->update( $table, [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        }

        $inserted = $wpdb->insert( $table, $data, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );

        if ( ! $inserted ) {
            return rest_ensure_response( new WP_Error( 'db_error', 'Could not create template', [ 'status' => 500 ] ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Template created successfully.', \CouponForge::TEXT_DOMAIN ),
            'id'      => $wpdb->insert_id
        ] );
    }

    /**
     * Update email template
     */
    public function update_email_template( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_email_templates';
        $id    = (int) $request->get_param( 'id' );

        $data = [
            'name'             => sanitize_text_field( $request->get_param( 'name' ) ),
            'subject'          => sanitize_text_field( $request->get_param( 'subject' ) ),
            'heading'          => sanitize_text_field( $request->get_param( 'heading' ) ),
            'message'          => wp_kses_post( $request->get_param( 'message' ) ),
            'footer_text'      => sanitize_text_field( $request->get_param( 'footer_text' ) ),
            'primary_color'    => sanitize_hex_color( $request->get_param( 'primary_color' ) ),
            'background_color' => sanitize_hex_color( $request->get_param( 'background_color' ) ),
            'is_default'       => (bool) $request->get_param( 'is_default' ),
        ];

        // If setting as default, unset other defaults
        if ( $data['is_default'] ) {
            $wpdb->update( $table, [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        }

        $updated = $wpdb->update(
            $table,
            $data,
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return rest_ensure_response( new WP_Error( 'db_error', 'Could not update template', [ 'status' => 500 ] ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Template updated successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }

    /**
     * Delete email template
     */
    public function delete_email_template( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_email_templates';
        $id    = (int) $request->get_param( 'id' );

        // Check if it's the default template
        $template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        
        if ( $template && $template->is_default ) {
            return rest_ensure_response( new WP_Error( 'forbidden', 'Cannot delete default template', [ 'status' => 403 ] ) );
        }

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        if ( ! $deleted ) {
            return rest_ensure_response( new WP_Error( 'db_error', 'Could not delete template', [ 'status' => 500 ] ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Template deleted successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }

    /**
     * Get settings
     */
    public function get_settings( WP_REST_Request $request ): WP_REST_Response {
        $defaults = [
            'template_title'        => '🎁 Your Rewards',
            'template_message'      => 'Thanks for your order. Use this code:',
            'template_bg_color'     => '#fef2f2',
            'template_border_color' => '#fecaca',
            'template_text_color'   => '#991b1b',
            'template_code_color'   => '#dc2626',
        ];

        $settings = get_option( 'coupon_forge_settings', $defaults );

        // Merge with defaults to ensure all keys exist
        $settings = wp_parse_args( $settings, $defaults );

        return rest_ensure_response( $settings );
    }

    /**
     * Save settings
     */
    public function save_settings( WP_REST_Request $request ): WP_REST_Response {
        $data = [
            'template_title'        => sanitize_text_field( $request->get_param( 'template_title' ) ),
            'template_message'      => sanitize_text_field( $request->get_param( 'template_message' ) ),
            'template_bg_color'     => sanitize_hex_color( $request->get_param( 'template_bg_color' ) ),
            'template_border_color' => sanitize_hex_color( $request->get_param( 'template_border_color' ) ),
            'template_text_color'   => sanitize_hex_color( $request->get_param( 'template_text_color' ) ),
            'template_code_color'   => sanitize_hex_color( $request->get_param( 'template_code_color' ) ),
        ];

        update_option( 'coupon_forge_settings', $data );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Settings saved successfully.', \CouponForge::TEXT_DOMAIN )
        ] );
    }
}