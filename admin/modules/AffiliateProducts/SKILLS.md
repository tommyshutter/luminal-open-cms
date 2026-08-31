# AffiliateProducts — Skills Reference

## Overview
Multi-retailer affiliate product management module. Supports Amazon PA-API, Walmart, and Best Buy affiliate programs with AI-powered product discovery and manual product curation.

## Capabilities
- Manage affiliate products (CRUD operations)
- Multi-retailer API credential management (Amazon, Walmart, Best Buy)
- AI-powered product discovery and approval workflow
- URL parsing to extract product info
- Product reordering
- Reseller management
- Mask-aware credential saving (prevents overwriting with masked values)

## API Endpoints
- `action=list_products` — List all affiliate products
- `action=get_product` — Get single product details
- `action=save_product` — Create or update a product
- `action=delete_product` — Delete a product
- `action=reorder_products` — Reorder product display order
- `action=get_config` — Get module configuration
- `action=save_config` — Save configuration (mask-aware for API keys)
- `action=parse_url` — Parse affiliate URL for product data
- `action=ai_discover` — AI-powered product discovery
- `action=ai_approve` — Approve AI-discovered product
- `action=get_resellers` — List resellers
- `action=save_reseller` — Save reseller config
- `action=delete_reseller` — Delete reseller

## Data Storage
- `admin/data/AffiliateProducts/` — Product data, API configs

## Dependencies
- AIResources (for AI discovery feature)

## Amazon Associates — Essential Program Docs
Complete program documentation: policies, linking rules, API reference, reporting, compliance. Reference before building any affiliate workflow.

**URL:** https://www.amazon.com/b?node=53634300011

## Amazon Product Bounties & Deals
Amazon runs curated deal programs where affiliates earn bonus commissions on promoted products ("bounties"). Always check here before curating affiliate products — bounty items earn significantly more than standard commission rates.

**Amazon Affiliate Deals & Bounty Hub:**
https://www.amazon.com/b?node=121530259011&ref=CG_ac_banner_030226_Announcement_BSSDEALCURATIONS

**Targeting tip:** match product categories to the site's audience — a film site does well with
home-theater gear, collectibles and books; a lifestyle or travel site with beauty, outdoor and
fashion items. Prioritise categories that overlap Amazon's current bounty promotions.

## Common Tasks
1. **Add a product manually**: Use save_product with title, URL, image, price, retailer
2. **Configure Amazon PA-API**: Go to Settings tab, enter Access Key, Secret Key, Partner Tag
3. **AI product discovery**: Use ai_discover action with search query, then ai_approve to add
4. **Check bounties first**: Before running ai_discover, review the Bounty Hub link above — filter searches toward active deal categories for higher commissions
