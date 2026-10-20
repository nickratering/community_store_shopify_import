# Changelog

## 1.0.0

First release.

- Dashboard page (Store › Products › Shopify Import) to check and import a Shopify products CSV export, with a preview, validation and a progress bar
- Products, options and variations with their own price, sale price, SKU, barcode, stock, weight and image
- Images downloaded from the Shopify CDN into the File Manager, resized to a configurable maximum width
- Product pages, SEO title and description
- Settings page with product group rules (part of the title, handle, type, category, vendor or a tag; comma-separated values)
- Running the import again updates products instead of duplicating them
- Download of old Shopify URLs → new product pages for 301 redirects
- Command line import: `cstore:shopify:import`
- The import runs one job per image and one per product, so no job runs into the web server timeout
