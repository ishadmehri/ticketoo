=== Ticketoo ===
Contributors: ishadmehri
Tags: support, ticket, helpdesk, support ticket, customer support
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight and fast support ticket plugin for WordPress.

== Description ==

Ticketoo adds a complete support-ticket flow to your site, with no page
builder and no external service:

* Logged-in users and guests open tickets and reply from the front end.
* Guests get a bearer link by email so they can return to their ticket
  without an account.
* Attachments with configurable size and file-type limits.
* Email notifications: agents on new tickets, guest confirmation with the
  ticket link, owners on support replies, agents on customer replies, and
  owners when a ticket is auto-closed.
* A "Ticketoo" panel in wp-admin to list, assign and update tickets, plus a
  settings screen (auto-close window, attachment limits, sender name and
  email, guest tickets, live counter refresh).
* Daily automatic closing of inactive tickets on WP-Cron.
* Three Gutenberg blocks (Ticketoo Ticket List, Ticketoo New Ticket Form,
  Ticketoo Conversation) and an optional Elementor widget — all rendering
  the same shortcode.
* Translation ready: text domain `ticketoo`, template overrides from your
  theme's `ticketoo/` directory, and filters throughout.

= Shortcode =

`[ticketoo]` renders the ticket interface. Attributes:

* `view="list"` (default) — the current user's ticket list.
* `view="form"` — the new-ticket form.
* `view="ticket" id="123"` — a single conversation.

From the list view, `?ticketoo_ticket=123` opens a conversation directly
(guests append the token from their email link). The classic, no-JavaScript
form posts work on their own; the front-end script is an enhancement only.

= Server notes =

* **WP-Cron:** the auto-close sweep is the daily `ticketoo_auto_close_sweep`
  cron event. WP-Cron only runs when someone visits your site, so on low
  traffic sites stale tickets may close late. For reliable scheduling, disable
  WP-Cron and trigger `wp-cron.php` from a real system cron job.
* **nginx:** attachments are stored under `uploads/ticketoo/` and protected
  there by an `.htaccess` deny rule, which nginx ignores. On nginx add a
  server-level rule denying direct access to that directory; downloads still
  work through the plugin's permission-checked endpoint.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ticketoo/`, or install the
   plugin through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen.
3. Place `[ticketoo]` on a page, and open the "Ticketoo" menu to manage
   tickets and settings.

== Frequently Asked Questions ==

= What happens to my data if I delete the plugin? =

Deleting the plugin runs the uninstall routine: it drops the plugin's custom
tables, removes the agent role and capability, deletes its options and
unschedules the cron event. Export anything you want to keep first.

= Are uploaded attachments deleted on uninstall? =

No. Attachment files stored under `uploads/ticketoo/` are left in place, so
nothing you or your customers uploaded is destroyed by accident. After
uninstalling, delete that directory manually if you want the files gone.

== Changelog ==

= 0.1.0 =

* Initial release: front-end tickets for users and guests, attachments, email
  notifications, wp-admin panel with settings, Gutenberg blocks, Elementor
  widget, daily auto-close sweep, i18n bootstrap and uninstall cleanup.
