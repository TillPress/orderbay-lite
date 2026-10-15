=== OrderBay Lite ===
Contributors: alextechgamer
Tags: woocommerce, invoice, credit note, e-invoice, pdf
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Free WooCommerce invoices: printable invoices, sequential numbering, credit notes, and UBL 2.1 / Factur-X CII e-invoice XML export.

== Description ==

OrderBay Lite adds the invoicing tools a WooCommerce store legally needs — free forever, no license key, no account, no phone-home.

* Printable invoice for every order (browser Print → Save as PDF; optional Dompdf/TCPDF if already installed on your host)
* Sequential, race-free invoice numbers with configurable prefix, format tokens ({PREFIX} {YYYY} {MM} {SEQ:5} …), and yearly/monthly counter reset
* Credit note numbers and a printable credit note for refunded orders
* Customers can download / print their own invoice from My Account (owner-only, nonce-protected)
* E-invoice XML export: UBL 2.1 (Peppol BIS Billing 3.0) and UN/CEFACT CII (Factur-X EN16931 profile), with a seller-details readiness checklist
* Invoice numbers are immutable once assigned and survive plugin updates
* Compatible with WooCommerce HPOS and block checkout
* Help & demo page: one-click demo order with invoice, credit note, UBL, and CII, and a support ticket form

Not tax advice: invoice layout, numbering, VAT lines, and e-invoice files are tools — confirm legal requirements with your accountant or tax adviser. The e-invoice XML is an EN16931 baseline export; validate it with an official validator before relying on it, and note that OrderBay Lite does not transmit anything to the Peppol network.

A paid **OrderBay** plugin (packing slips, delivery notes, shipping labels, proforma invoices, bulk printing, barcodes/QR, fulfillment desk, and more) is sold separately at https://tillpress.com/orderbay/. It is a different plugin. This Lite plugin is complete and is never locked.

== External services ==

OrderBay Lite has no license check, no telemetry, no account, and makes no outbound requests during normal use. All documents are generated on your own site. The one exception is a support ticket you choose to send.

**Support tickets (only when you send one):** the Help & demo page has a support form. When an administrator fills it in, ticks the consent box, and presses **Send ticket**, the plugin sends the name, reply-to email, topic, subject, message, this site's address, and (if ticked) a system report of WordPress/WooCommerce/PHP versions, theme, and active plugin names to the Tillpress support desk at `https://tillpress.com/wp-json/tp/v1/support`. Nothing is sent automatically, in the background, or on a schedule, and no passwords, keys, or customer/order data are included. Service provider: Tillpress (Alex Software Holdings LLC). Privacy policy: https://tillpress.com/privacy/ — Terms: https://tillpress.com/terms/ . "Email instead" opens the same ticket in your own email app and sends nothing from the site.

== Installation ==

1. Upload the `orderbay-lite` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active)
3. Open **WooCommerce → OrderBay Lite** and enter your business name/address, VAT / Tax ID, logo URL, and invoice number format
4. Print an invoice from any order screen (Invoice button) or the orders list

== Frequently Asked Questions ==

= Is there a license key or trial? =
No. Every feature listed above works without a key, trial, or phone-home to the author.

= How do invoice numbers work? =
The first time an invoice is printed (by staff or by the customer from My Account), the order is assigned the next sequential number. The counter increments atomically in the database, so two simultaneous prints can never receive the same number. Numbers are immutable once assigned.

= Can customers download their invoice? =
Yes. Logged-in customers get an "Invoice" button on My Account → Orders and on the order view page. Only the order's owner (or store staff) can open it.

= What e-invoice formats are supported? =
UBL 2.1 (Peppol BIS Billing 3.0) and UN/CEFACT CII (the XML a Factur-X PDF embeds), both EN16931-baseline. Export only — validate with an official EN16931/Peppol validator before production use. A Factur-X PDF button additionally appears if your host has a PDF engine (Dompdf/TCPDF) and the horstoeko/zugferd library installed; OrderBay Lite bundles neither.

= Does it generate PDFs? =
The primary path is the browser's Print → Save as PDF on the print view. If Dompdf or TCPDF is already installed on your host, an optional "Download PDF" button can use it. No PDF library is bundled.

= How do I get support? =
Open **WooCommerce → OrderBay Lite help**. The support form there sends your ticket straight to the Tillpress support desk at tillpress.com after you tick the consent box and press Send ticket; you get a ticket number on screen and the reply comes by email. It includes your name, email, message, this site's address, and (optional) a system report of versions, theme, and active plugins; never passwords, keys, or customer data. Prefer email? "Email instead" opens the same ticket in your own email app. You can also use the WordPress.org support forum for this plugin or https://tillpress.com/support/. Replies Monday to Friday, 09:00 to 17:00 Pacific Time; first reply within one business day.

= Is there a demo? =
Yes. On **WooCommerce → OrderBay Lite help**, click **Create demo data**. It creates one completed order with a partial refund, numbered DEMO-INV-0001 and DEMO-CN-0001, so you can open the invoice, the credit note, and the UBL and CII e-invoice XML. Your real invoice and credit note counters are not touched. Everything is labelled "Demo", never emailed, and removed with one click (or when you uninstall the plugin).

= Does the plugin advertise anything? =
Only on its own Help & demo page: one box describing the paid OrderBay plugin with an optional 20% discount code. It can be hidden for good with "Hide this offer". There are no dashboard-wide notices, no tracking, and no referral IDs.

= What if I also install OrderBay (the paid plugin)? =
Lite steps aside automatically so you do not get duplicate buttons or a second number sequence. You can deactivate Lite.

= What does the paid OrderBay add? =
Packing slips, delivery notes, shipping labels, proforma invoices, bulk document printing, barcodes and QR codes, custom branding options, a fulfillment desk, and more — at https://tillpress.com/orderbay/.

== Screenshots ==

1. Invoice print view — browser Print / Save as PDF.
2. Settings: business details, logo, VAT / Tax ID, and invoice number format with tokens and yearly/monthly reset.
3. Order screen buttons: Invoice, E-invoice UBL, E-invoice CII (Factur-X).
4. Customer My Account invoice download.

== Changelog ==

= 1.0.9 =
* Dev: translator comments on the invoice-number and credit-note order notes.

= 1.0.8 =
* Fix: a fresh install whose WooCommerce order tables are not created yet no longer logs a database error when the first invoice number is issued.
* Fix: an expired invoice lock is taken over in one step. A late worker cannot overwrite a newer invoice number or drop the newer worker's lock.

= 1.0.7 =
* Fix: Lite and OrderBay Pro now share one invoice and credit-note sequence, so switching Lite to Pro and back never reissues a number.

= 1.0.6 =
* Fix: uninstalling no longer resets invoice or credit-note numbering; new numbers continue above Lite (and paid) history already on your orders.
* Fix: if a number cannot be allocated, the invoice is not saved, printed or downloaded — never fall back to 1.
* Fix: two requests for the same order share one identity; high-water recovery no longer loads every order on each allocation.

= 1.0.5 =
* Fix: invoice numbers are allocated atomically on SQLite installs, and the same order always keeps its number.

= 1.0.4 =
* Change: invoice and credit-note print styles and the Print button now load through registered WordPress styles/scripts instead of inline <style> tags and onclick attributes (wordpress.org review).

= 1.0.3 =
* New: Send support tickets from inside the plugin (Help & demo page). You get a ticket number on screen; nothing is sent until you tick the consent box and press Send ticket. "Email instead" still opens your email app.

= 1.0.2 =
* New: Help & demo page (WooCommerce → OrderBay Lite help, also linked from the Plugins screen).
* New: one-click demo order with a refund, using separate DEMO- numbers so the real invoice and credit note sequences are untouched. Never emailed; removed with one click or on uninstall.
* New: support ticket form that opens a pre-filled email in your own email app, with an optional system report (no passwords, keys, or customer data). Nothing is sent from your site.
* New: optional, dismissible 20% offer for the paid OrderBay plugin, shown only on the Help & demo page.

= 1.0.1 =
* Fix: the printed credit note now lists each refund (reason and amount); it previously showed only the total.

= 1.0.0 =
* First public release: printable invoices, sequential invoice numbering with format tokens and counter reset, credit notes, customer My Account invoice download, UBL 2.1 + Factur-X CII e-invoice XML export.

== Upgrade Notice ==

= 1.0.4 =
Internal asset-loading change for wordpress.org review. No settings change.

= 1.0.3 =
Support tickets can now be sent from inside the plugin. No settings change.

= 1.0.2 =
Adds a Help & demo page: demo order, support form, and system report. No settings change.

= 1.0.1 =
Credit notes list each refund again.

= 1.0.0 =
First release.
