<?php
/**
 * Template: My Account Coupons Display
 * 
 * Available variables:
 * @var array  $coupons  Array of coupon objects from database
 * @var array  $settings Plugin settings (title, message, colors)
 * @var object $order    WooCommerce order object
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $coupons ) ) {
    return;
}
?>

<section class="woocommerce-customer-details cf-my-account-coupons" style="
    margin-top: 30px;
    background: <?php echo esc_attr( $settings['template_bg_color'] ); ?>;
    padding: 20px;
    border-radius: 10px;
    border: 2px dashed <?php echo esc_attr( $settings['template_border_color'] ); ?>;
">
    <h2 style="
        color: <?php echo esc_attr( $settings['template_text_color'] ); ?>;
        font-size: 18px;
        margin-top: 0;
        font-weight: 600;
    ">
        <?php echo esc_html( $settings['template_title'] ); ?>
    </h2>
    
    <?php foreach ( $coupons as $coupon ) : ?>
        <div class="cf-coupon-item" style="margin-bottom: 15px;">
            <p style="
                margin-bottom: 10px;
                color: <?php echo esc_attr( $settings['template_text_color'] ); ?>;
                font-size: 14px;
                line-height: 1.5;
            ">
                <?php echo esc_html( $settings['template_message'] ); ?>
            </p>
            <div style="
                background: #fff;
                padding: 10px 15px;
                border: 2px solid <?php echo esc_attr( $settings['template_border_color'] ); ?>;
                border-radius: 6px;
                display: inline-block;
                font-weight: 700;
                font-size: 18px;
                color: <?php echo esc_attr( $settings['template_code_color'] ); ?>;
                letter-spacing: 1px;
            ">
                <?php echo esc_html( $coupon->coupon_code ); ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
