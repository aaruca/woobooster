# WooBooster

**The intelligent product recommendation engine for WooCommerce.**

WooBooster replaces standard "Related Products" with a powerful, rule-based engine that lets you define exactly *what* products to show and *when*. It includes native integration with Bricks Builder and supports advanced algorithms like "Trending" and "Recently Viewed" without bloating your site.

## 🚀 Key Features

- **Rule-Based Matching**: Create precise rules using taxonomy terms (Category, Tag, Attribute). Supports complex logic (AND/OR groups) and hierarchical matching (include children terms).
- **Smart Algorithms**: Beyond simple category matches, WooBooster supports:
  - **Co-Purchase History**: Recommends products frequently bought together.
  - **Trending**: Shows top-selling products in the current category or globally.
  - **Recently Viewed**: Displays products the user has browsed in their session.
  - **Similarity Engine**: Finds products with similar price ranges (+/- 25%) and categories.
- **High Performance**: 
  - Uses a custom index table for millisecond-fast lookups.
  - Multi-layer caching system (Object Cache for queries, Transients for heavy aggregations).
  - Optimized for large catalogs (30k+ products).
- **Native Bricks Builder Integration**: 
  - First-class citizen in Bricks.
  - Custom Query Type: `WooBooster Recommendations`.
  - Advanced Query Controls: Override context (Manual ID, Cart Item), exclude out-of-stock, and define fallbacks.

## ⚙️ Requirements

| Dependency | Version |
|------------|---------|
| WordPress  | 6.0+    |
| WooCommerce | 6.0+   |
| PHP        | 7.4+    |
| Bricks Builder | 1.7+ *(Optional, but recommended)* |

## 📦 Installation

1. Download the latest release `.zip` from [GitHub Releases](https://github.com/aaruca/woobooster/releases).
2. In WordPress admin → **Plugins → Add New → Upload Plugin** → select the `.zip`.
3. Activate the plugin.

### Auto-Updates

WooBooster includes a built-in GitHub updater. To enable auto-updates for private repositories or to avoid rate limits, add your GitHub Personal Access Token to `wp-config.php`:

```php
define( 'WOOBOOSTER_GITHUB_TOKEN', 'ghp_your_personal_access_token' );
```

## 🛠 Usage

### 1. Creating Rules
Go to **WooCommerce → WooBooster → Rules**.
1. **Conditions**: Define *when* this rule applies (e.g., "Product Category is 'Clothing'").
2. **Actions**: Define *what* to show (e.g., "Show Trending products from 'Accessories'").
3. **Priority**: Rules are evaluated in order. The first matching rule wins.

### 2. Bricks Builder Integration
WooBooster adds a powerful Query Loop type to Bricks.

1. Add a **Container** or **Block** to your template.
2. Enable **Use Query Loop**.
3. In the **Query** settings, select Type: **WooBooster Recommendations**.
4. A new settings group **WooBooster Settings** will appear in the **Content** tab:
   - **Product Source**: Auto-detect (Current Post), Manual ID, or Last Added to Cart Item.
   - **Max Products**: Override the rule's limit.
   - **Exclude Out of Stock**: Hides out-of-stock items automatically.
   - **Fallback**: What to show if no rule matches (Woo Related, Recent, Bestselling, or None).

## 🧩 Rendering Methods

| Method | Description |
|--------|-------------|
| **Bricks Query Loop** | **Best for custom designs.** Full control over layout and dynamic data. |
| **WooCommerce Hook** | Replaces the default `woocommerce_output_related_products` hook. Uses your theme's default styling. |
| **Shortcode** | `[woobooster product_id="123" limit="4" fallback="recent"]` |

## 🔧 Diagnostics

The **Diagnostics** tab allows you to simulate the engine logic without browsing your site.
- Enter a Product ID.
- WooBooster will show you:
  - Extracted Terms & Keys.
  - The **Winning Rule** (if any).
  - The resulting Product IDs and rationale.
  - Execution time (ms).

## 👥 Authors

- **Ale Aruca** — [@aaruca](https://github.com/aaruca)
- **Muhammad Adeel** — [@adeelwebify](https://github.com/adeelwebify)

## 📄 License

GPLv2 or later.
