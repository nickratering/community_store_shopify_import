# Shopify Import for Community Store

Import products from a Shopify CSV export into [Community Store](https://github.com/concretecms-community-store/community_store) for Concrete CMS.

- Products, options (up to 3, e.g. colour × size) and all variations, with their own price, sale price, SKU, barcode, stock, weight and image
- Images are downloaded from the Shopify CDN into the File Manager (folder `Shopify import/<handle>`), resized to a configurable maximum width
- Each product gets a product page; SEO title and description are copied when present
- Product groups are assigned by configurable rules
- Running the import again updates the products instead of duplicating them
- A list of old Shopify URLs → new product pages is available for setting up 301 redirects

## Requirements

- Concrete CMS 9.5.1 or later
- Community Store 2.7 or later
- PHP 7.4 or later

## Usage

1. In Shopify, go to **Products › Export**, choose **All products** and **Plain CSV file**.
2. In Concrete, go to **Dashboard › Store › Products › Shopify Import › Settings** and set up how products are added to product groups.
3. Go to **Dashboard › Store › Products › Shopify Import**, upload the CSV (or choose one from the File Manager) and click **Check file**.
   The file is validated and a preview shows what will be created or updated. Errors (missing columns, invalid prices, duplicate variants, gift cards, …) block the import; warnings are only shown.
4. Click **Import**. Every product is imported as a separate background job, with a progress bar.
5. The results page lists what was imported and lets you download the redirects CSV.

The Shopify store must still be online during the import, because the images are downloaded from its CDN.

### Settings

- **Product group rules** – add products to a group when their Shopify title, handle (e.g. `baseball-jacket-kids`), product type, product category, vendor or one of their tags contains a value. Part of a word is enough: `shirt` matches `T-shirt`. Separate multiple values with commas to match any of them (e.g. `shirt, jacket`). A product is added to every group it matches.
- **Products that match no rule** – add them to a group named after the Shopify product type (created when missing), to a fixed group, or to no group.
- **Maximum image width** – very large images can make product pages run out of memory while thumbnails are generated.

### Command line

```bash
concrete/bin/concrete cstore:shopify:import path/to/products_export.csv --dry-run
concrete/bin/concrete cstore:shopify:import path/to/products_export.csv --redirects=redirects.csv
```

Options: `--dry-run`, `--only=<handle>` (repeatable), `--skip-images`, `--refresh-images`, `--redirects=<file>`.

## How Shopify data is mapped

| Shopify | Community Store |
| --- | --- |
| Title, Body (HTML) | Name, details (the first paragraph also becomes the short description) |
| Option1–3 name and values | Product options with variations; combinations that don't exist in Shopify are disabled |
| Variant price / compare-at price | Price / sale price |
| Variant SKU, barcode, grams, inventory | SKU, barcode, weight (converted to the store's weight unit), stock (unlimited when not tracked) |
| Variant inventory policy "continue" | Allow back orders |
| Requires shipping, taxable | Shippable, taxable (default tax class) |
| Status other than active, or not published | Inactive |
| Image Src, Variant Image | Product images, variation image |

Not imported: collections (use the group rules instead), metafields, customers, orders, discounts and gift cards.

Prices are imported as they are. If your Shopify prices include VAT, set Community Store to prices including tax.

## Security

- Only images on `cdn.shopify.com` are downloaded, over HTTPS, without following redirects, and only when the response is a valid image.
- The uploaded CSV is validated before it is stored in the File Manager, and File Manager permissions are respected.

## Uninstalling

Uninstalling removes the record of which products were imported. Products, pages and images stay in place, but a new import after reinstalling creates new products.

## License

MIT, see [LICENSE](LICENSE).
