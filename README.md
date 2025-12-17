# CouponForge

**High-performance, conditional coupon generator for WooCommerce with a modern admin interface.**

## Features

✨ **Automated Coupon Generation**

- Automatically generate unique coupons when orders are completed
- Product-specific targeting with variation support
- Customizable discount types (percentage, fixed amount)
- Email restrictions and usage limits

🎨 **Beautiful Admin Interface**

- Modern React-based dashboard with real-time statistics
- Email template designer with live preview
- Collapsible sidebar navigation
- Toast notifications for user feedback

📧 **Email Templates**

- Customizable email templates for coupon delivery
- Visual template editor with color customization
- Support for multiple templates
- Professional HTML email design

📊 **Comprehensive History**

- Track all generated coupons
- View usage status and expiration dates
- Delete coupons with WooCommerce sync
- Order note integration

⚙️ **Advanced Rules Engine**

- Per-product discount configuration
- Per-variation discount support
- Flexible rule targeting
- Active/inactive rule toggling

🎁 **My Account Integration**

- Display coupons in customer's My Account page
- Customizable template colors and messages
- Professional coupon display

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher
- PHP 8.0 or higher

## Installation

1. Upload the `coupon-forge` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > CouponForge to configure

## Configuration

### Creating Rules

1. Navigate to CouponForge > Rules Engine
2. Click "Create New Rule"
3. Select target products
4. Configure discount type and value
5. Set expiration days
6. Choose email template
7. Save and activate the rule

### Email Templates

1. Go to CouponForge > Email Templates
2. Create or edit templates
3. Customize colors, subject, heading, and message
4. Preview changes in real-time

### Settings

Customize the My Account coupon display:

- Template title and message
- Background and border colors
- Text and coupon code colors
- Live preview available

## Developer Information

**Author:** Kazi Sadib Reza  
**Version:** 1.0.0  
**License:** GPL v2 or later  
**Text Domain:** coupon-forge

## Support

For support, please visit: [https://github.com/KaziSadibReza/CouponForge](https://github.com/KaziSadibReza/CouponForge)

## Changelog

### 1.0.0

- Initial release
- Automated coupon generation
- Rules engine with product targeting
- Email template system
- History tracking with delete functionality
- My Account integration
- Settings page for customization
- Per-product and per-variation discounts
- Toast notifications
- Skeleton loaders
- Modal dialogs
- Collapsible sidebar
