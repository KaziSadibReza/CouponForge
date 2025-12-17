<?php
namespace CouponForge\Core;

defined( 'ABSPATH' ) || exit;

class Mailer {

    public static function send_coupon_email( \WC_Order $order, $code, $rule ) {
        global $wpdb;
        $to = $order->get_billing_email();
        
        // 1. Get Email Template
        $template = null;
        if ( ! empty( $rule->template_id ) ) {
            $template = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM {$wpdb->prefix}cf_email_templates WHERE id = %d", 
                $rule->template_id 
            ) );
        }
        
        // Fallback to default template
        if ( ! $template ) {
            $template = $wpdb->get_row( 
                "SELECT * FROM {$wpdb->prefix}cf_email_templates WHERE is_default = 1 LIMIT 1" 
            );
        }
        
        // If still no template, use hardcoded defaults
        if ( ! $template ) {
            $template = (object) [
                'subject'          => 'Your Exclusive Coupon is Ready!',
                'heading'          => 'Thank You for Your Order!',
                'message'          => "We appreciate your business. Here's a special coupon code just for you:",
                'footer_text'      => 'Questions? Contact us anytime.',
                'primary_color'    => '#d6336c',
                'background_color' => '#f7f7f7',
            ];
        }
        
        $subject = $template->subject;
        
        // 2. Get Store Info
        $store_name = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );

        // 3. Set Custom Headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: $store_name <$admin_email>"
        ];

        // 4. Template Variables
        $heading = $template->heading;
        $message = $template->message;
        $footer_text = $template->footer_text;
        $primary_color = $template->primary_color;
        $bg_color = $template->background_color;
        
        ob_start();
        ?>
<!DOCTYPE html>
        <html>
        <body style="margin:0; padding:0; background-color:<?php echo esc_attr($bg_color); ?>; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;">
            <div style="max-width:600px; margin:40px auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.05);">
                
                <div style="background: <?php echo esc_attr($primary_color); ?>; padding: 50px 30px; text-align:center;">
                    <h1 style="color:#ffffff; margin:0; font-size:28px; font-weight:800;"><?php echo esc_html( $heading ); ?></h1>
                </div>
                
                <div style="padding:50px 40px; text-align:center; color:#495057;">
                    <p style="font-size:16px; line-height:1.6; margin-bottom:30px; color:#555;"><?php echo wp_kses_post( $message ); ?></p>
                    
                    <div style="background: #f9fafb; border:2px dashed <?php echo esc_attr($primary_color); ?>; padding:30px; border-radius:12px; margin-bottom:30px;">
                        <span style="display:block; font-size:13px; text-transform:uppercase; color:#868e96; margin-bottom:10px; letter-spacing:1px;">YOUR COUPON CODE</span>
                        <span style="display:block; font-size:32px; font-weight:800; color:<?php echo esc_attr($primary_color); ?>; letter-spacing:2px; font-family:'Courier New', monospace;"><?php echo esc_html( $code ); ?></span>
                        <div style="margin-top:10px; font-size:13px; color:#6b7280;">Click to copy and paste at checkout</div>
                    </div>

                    <?php if ( $rule->expiry_days > 0 ) : ?>
                        <p style="font-size:13px; color:#9ca3af;">Expires in <?php echo intval($rule->expiry_days); ?> days.</p>
                    <?php endif; ?>
                    
                    <a href="<?php echo esc_url( get_home_url() ); ?>" style="display:inline-block; background:<?php echo esc_attr($primary_color); ?>; color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-weight:600; font-size:15px;">Shop Now</a>
                </div>
                
                <div style="background:#f9fafb; padding:24px 30px; text-align:center; border-top:1px solid #e5e7eb;">
                    <p style="color:#6b7280; font-size:14px; margin:0;"><?php echo esc_html( $footer_text ); ?></p>
                </div>
            </div>
        </body>
        </html>
<?php
        $content = ob_get_clean();

        wp_mail( $to, $subject, $content, $headers );
    }
}