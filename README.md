# Zero_ProductAttachments

Files on product pages: manuals, data sheets, certificates, size drawings.
A "Downloads" section in the product page's details lists them with their
kind and size; each opens in a new tab.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-product-attachments
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Setting it up

Stores > Configuration > Zero > Product Attachments: Enabled (off by default,
per store view), the section's title (Downloads), the largest file (20 MB).

## Admin

Catalog > Product Attachments (resource `Zero_ProductAttachments::attachments`):
attach a file to a product by its SKU, with a title (empty: the file's
name), a store view (or all) and a sort order; below, the files of every
product or of one SKU, each with its title, store view and order to change,
and Remove. A product's form has an **Attachments** button that opens its
files.

## Files

Allowed: PDF, JPEG, PNG, WebP, ZIP, TXT, CSV, Word, Excel, PowerPoint,
OpenDocument text and spreadsheet. Each is checked by its extension and by
its content (an HTML page named .pdf is refused). Nothing a browser would
run from the store's domain: no HTML, SVG or scripts.

Stored under `pub/media/zero/attachments/<random>/<clean-name>`, served as
media. The store view and the file are checked before anything is copied;
if the row can't be written after the copy, the copied file is removed.
Removing an attachment removes its file; deleting the product removes its
attachments' rows. Adding, changing or removing a file cleans the
product's page from the page cache (built-in or Varnish).

Table: `zero_product_attachment`.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
