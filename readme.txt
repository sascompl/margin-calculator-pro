# Margin Calculator Pro for WooCommerce

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-green)
![License](https://img.shields.io/badge/License-GPL%20v2-yellow)

Advanced margin calculation and management for WooCommerce products with Quick Edit, per-category thresholds, order profit tracking, CSV import/export and dashboard widget.

---

## ✨ Features

- **Quick Edit** — Edit purchase prices directly from the product list
- **Margin colors** — Visual indicators (green / orange / red) based on thresholds
- **Per-category thresholds** — Individual margin thresholds per product category
- **Variation support** — Each variation has its own purchase price and margin
- **Order margin** — Profit and margin displayed on every order page and orders list
- **Unprofitable order warning** — Alert when an order generates negative profit
- **CSV Import** — Bulk import purchase prices via CSV (by SKU or product ID)
- **CSV Export** — Export all purchase prices to CSV
- **Dashboard widget** — Top and lowest margins at a glance
- **Statistics** — Average margin and product overview
- **HPOS compatible** — Fully compatible with WooCommerce High-Performance Order Storage

---

## 🌍 Translations

| Language | Code |
|---|---|
| 🇬🇧 English | default |
| 🇵🇱 Polish | pl_PL |
| 🇩🇪 German | de_DE |
| 🇫🇷 French | fr_FR |
| 🇪🇸 Spanish | es_ES |

---

## 📐 Margin formula

```
Margin % = (Sale Price Net - Purchase Price Net) / Sale Price Net × 100
```

Margin is calculated as profit share of net sale price (not markup).

---

## 🚀 Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the ZIP file and activate
4. Go to WooCommerce → Margin Calculator
5. Configure margin thresholds and VAT rate

---

## 📋 Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

---

## 📁 CSV Import format

```csv
sku,purchase_price
PROD-001,12.50
PROD-002,8.00
```

Or by product ID:

```csv
product_id,purchase_price
123,12.50
456,8.00
```

---

## 📄 License

[GPL v2 or later](LICENSE)

---

## 👤 Author

**Sascom** — [sascom.pl](https://sascom.pl)
