# WooBooster

Rule-based product recommendation engine for WooCommerce with native Bricks Builder Query Loop integration.

## Requirements

| Dependency | Version |
|------------|---------|
| WordPress  | 6.0+    |
| WooCommerce | 6.0+   |
| PHP        | 7.4+    |
| Bricks Builder *(optional)* | 1.7+ |

## Installation

1. Download the latest release `.zip` from [GitHub Releases](https://github.com/aaruca/woobooster/releases).
2. In WordPress admin → **Plugins → Add New → Upload Plugin** → select the `.zip`.
3. Activate.

### Auto-Updates (Private Repo)

WooBooster checks GitHub Releases for new versions automatically. For private repos, add this to `wp-config.php`:

```php
define( 'WOOBOOSTER_GITHUB_TOKEN', 'ghp_your_personal_access_token' );
```

## How It Works

1. **Create rules** — match products by taxonomy term (category, tag, or attribute).
2. **Define actions** — recommend products from a specific category, tag, or same attribute.
3. **Engine matches** — when a visitor views a product, the engine finds the best matching rule via a fast lookup index and runs the recommendation query.

### Rendering Methods

| Method | Description |
|--------|-------------|
| **Bricks Query Loop** *(recommended)* | Full Bricks-native layout control. Select "WooBooster Recommendations" as query type. All Bricks dynamic data tags work. |
| **WooCommerce Hook** | Replaces default related products on single product pages. Uses WooCommerce templates. |
| **Shortcode** | `[woobooster product_id="123" limit="6" fallback="recent"]` |

## Admin Pages

- **Settings** — Enable/disable, section title, rendering method, debug mode.
- **Rule Manager** — Create, edit, activate/deactivate, delete rules.
- **Diagnostics** — Test a product ID or SKU against all rules and see what matches.

## Creating a Release

1. Update `WOOBOOSTER_VERSION` in `woobooster.php`.
2. Commit and push.
3. Create a GitHub Release with a tag matching the version (e.g., `1.0.1` or `v1.0.1`).
4. Attach a `.zip` of the plugin folder as a release asset, or let GitHub's auto-generated zipball work.
5. WordPress sites will detect the update within 6 hours (or sooner on manual check).

## Authors

- **Ale Aruca** — [@aaruca](https://github.com/aaruca)
- **Muhammad Adeel** — [@adeelwebify](https://github.com/adeelwebify)

## License

GPLv2 or later.
