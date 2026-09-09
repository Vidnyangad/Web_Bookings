# Amusement Park Ticketing Plugin

This plugin provides an online ticketing and booking system for your amusement park, integrated directly with WooCommerce Variable Products.

## Setup Instructions

### 1. Create the WooCommerce Product
1. Go to **Products > Add New** in your WordPress admin.
2. Name the product (e.g., "Amusement Park Ticket").
3. In the **Product Data** dropdown, select **Variable product**.
4. Go to **Attributes**, add a new custom attribute named **Ticket Type**. Check "Used for variations".
5. Enter the values separated by `|`: `Adult | Child (6-12) | Child (Under 6)`
6. Save Attributes.
7. Go to **Variations** and select **Create variations from all attributes**.
8. Set the pricing for each variation:
   - **Adult**: Set Regular Price to `500`.
   - **Child (6-12)**: Set Regular Price to `300`.
   - **Child (Under 6)**: Set Regular Price to `0` (Free).
9. Publish the product and take note of the **Product ID**.

### 2. Plugin Configuration
1. Activate the **Amusement Park Ticketing** plugin.
2. Go to the new **Amusement Park** menu in the WordPress admin dashboard.
3. In the Settings tab, enter the **WooCommerce Product ID** you created in step 1.
4. Set the default daily capacity for Adult tickets per time slot (e.g., 100 for Morning, 100 for Afternoon, 100 for Evening).

### 3. Placing the Booking Interface
1. Edit the page where you want the booking interface to appear.
2. Add the shortcode: `[amusement_park_booking]`
3. Users will be able to select their ticket quantities, check availability, select a date and time, and add everything to their WooCommerce cart.

### 4. Staff Scanner Page
1. Create a private page for your staff (e.g., restricted by WordPress password or a role-based visibility plugin).
2. Add the shortcode: `[amusement_park_scanner]`
3. Staff can use this page to view a calendar of reservations and check in guests using their 6-character PNR code. Staff can also click on any date in the calendar to view a detailed breakdown of all orders (grouped by time slot), including contact information and passenger counts.
