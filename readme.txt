=== OrderBay Lite ===
Contributors: alextechgamer
Tags: woocommerce, invoice, credit note, e-invoice, pdf
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
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

Not tax advice: invoice layout, numbering, VAT lines, and e-invoice files are tools — confirm legal requirements with your accountant or tax adviser. The e-invoice XML is an EN16931 baseline export; validate it with an official validator before relying on it, and note that OrderBay Lite does not transmit anything to the Peppol network.

A paid **OrderBay** plugin (packing slips, delivery notes, shipping labels, proforma invoices, bulk printing, barcodes/QR, fulfillment desk, and more) is sold separately at https://tillpress.com/orderbay/. It is a different plugin. This Lite plugin is complete and is never locked.

== External services ==

None. OrderBay Lite makes no outbound network requests: there is no license check, no telemetry, no account, and no call to the plugin author's servers — ever. All documents are generated on your own site.

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

= 1.0.1 =
* Fix: the printed credit note now lists each refund (reason and amount); it previously showed only the total.

= 1.0.0 =
* First public release: printable invoices, sequential invoice numbering with format tokens and counter reset, credit notes, customer My Account invoice download, UBL 2.1 + Factur-X CII e-invoice XML export.

== Upgrade Notice ==

= 1.0.1 =
Credit notes list each refund again.

= 1.0.0 =
First release.
