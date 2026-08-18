# LyaiDeu — Food Delivery & Multi-Vendor Marketplace

A full-stack **food delivery and online marketplace** platform built with PHP + MySQL and vanilla JavaScript/CSS. Customers order food, groceries, beverages and more from partner stores; an admin panel runs the whole marketplace; partner kitchens and delivery riders each get their own dashboard to fulfil and deliver orders.

> College-project demo. Payments are simulated (Cash on Delivery only) — not production-ready for real money handling.

---

## Features

### Customer storefront
- Homepage with hero slides, featured dishes, groceries, drinks, gifts and partner stores.
- Catalog pages with nested category trees: **Menu**, **Mart** (groceries), **Beverages** and **Others** (gifts/decor).
- Dedicated **store pages** and **product pages** with clean, SEO-friendly URLs (`/store/himalayan-momo-house`, `/menu/momo/steamed-momo/chicken-steam-momo`).
- Search across the whole catalog, category filtering, and price/rating sorting.
- Shopping cart with quantity controls and a slide-in cart drawer.
- Favorites saved in the browser.
- Product variants (sizes, options) supported across all catalog types.
- Checkout with delivery details, Cash on Delivery, server-side totals, and promo code for free delivery.
- Order confirmation, order history and **live order-status tracking**.
- User accounts: sign-up/login (name, phone or email), profile with avatar, and **KYC document upload** (verified customers only).
- Contact page with an admin-managed team directory, plus FAQ and Terms pages.
- In-app notification bell (unread count + history) for logged-in customers.

### Admin panel (`/admin`)
- Dashboard with live stats: orders, revenue, pending orders, users, pending KYC, vendors, riders, unread messages.
- Full catalog management: menu items, mart items, beverages, others.
- Nested **category trees** per catalog type with icons and slugs.
- **Order management** with the status workflow:
  `Pending → Confirmed → Preparing → Ready for pickup → Out for delivery → Delivered` (plus cancellation).
- **Stores & Vendors**: manage partner stores, their branding, and their vendor logins.
- **Riders**: manage delivery rider accounts (vehicle, avatar, active status).
- **KYC verification**: approve/reject customer documents with a review reason.
- **Contacts**: update the service team directory shown on the Contact page.
- **Messages**: inbox for contact-form submissions.
- **Users**: view registered customer accounts.
- **Settings**: site logo/favicon, branding, delivery configuration and promo codes.
- CSRF-protected forms and hashed credentials throughout.

### Vendor dashboard (`/vendor`)
- Live kitchen order queue (auto-refreshing) with per-vendor status flow:
  `Pending → Accepted/Rejected → Preparing → Ready for pickup`.
- One-click status updates that notify the customer and nearby riders in real time.
- Own store page (`/vendor_store`) and product management (`/vendor_products`).

### Rider dashboard (`/rider`)
- Live delivery queue with **map previews** (Leaflet + OpenStreetMap) for each drop-off.
- Status updates as orders move to `Out for delivery` and `Delivered`.
- Rider profile with avatar.
- Real-time notifications for accepted, prepared and ready orders.

---

## Tech stack

- **Backend:** PHP 8+ (PDO with prepared statements, `utf8mb4`).
- **Database:** MySQL — schema and seed data in `schema.sql` (database: `lyaideudb`).
- **Frontend:** Vanilla JS, CSS custom properties, Font Awesome 6, Google Fonts.
- **Maps:** Leaflet + OpenStreetMap (delivery dashboards).
- **Server:** Apache via XAMPP/WAMP (`.htaccess` provides the pretty-URL routing).
- **Deployment:** cPanel-ready (see `.cpanel.yml`).

---

## Requirements

- PHP 8.0+ (uses `match`, arrow functions, `str_contains`, null coalescing).
- MySQL 5.7+ / MariaDB 10+.
- Apache with `mod_rewrite` (default in XAMPP/WAMP).
- Write permissions on the `uploads/` folder (product images, KYC docs, avatars).

---

## Setup (XAMPP / WAMP)

1. Place the project folder in your web root, e.g. `htdocs/lyaideu`.
2. Create the database and load schema + seed data:
   - Via phpMyAdmin: import `schema.sql`, or
   - From a terminal:
     ```
     mysql -u root -p < schema.sql
     ```
   This creates `lyaideudb` with all tables and demo catalog data.
3. Configure the database connection in `db.php` if your credentials differ. It reads the environment variables `LYAIDEU_DB_HOST`, `LYAIDEU_DB_NAME`, `LYAIDEU_DB_USER` and `LYAIDEU_DB_PASS`, and otherwise defaults to `root` with no password on `127.0.0.1`.
4. (Optional) Existing legacy installs that still have a `data.json` file can import their old dishes, hotels, contacts, users and orders into MySQL by opening `migrate.php` once — then delete the file.
5. Open `http://localhost/lyaideu/` to browse the storefront.
6. Admin panel: `http://localhost/lyaideu/admin` (set up the admin credentials on first use).

---

## Key pages

| URL                | Purpose                                   |
|--------------------|-------------------------------------------|
| `/`                | Homepage (featured sections + search)     |
| `/menu`            | Food menu catalog                         |
| `/mart`            | Grocery marketplace                       |
| `/beverages`       | Drinks catalog                            |
| `/others`          | Gifts / decor catalog                     |
| `/store/{id\|slug}` | Individual partner store page             |
| `/login`           | Customer login / sign-up                  |
| `/checkout`        | Cart review + delivery details            |
| `/orders`          | Order history + live tracking             |
| `/profile`         | Account profile + KYC upload              |
| `/contact`         | Contact form + team directory             |
| `/admin`           | Admin dashboard (categories, orders, catalog, stores, vendors, riders, KYC, contacts, messages, users, settings) |
| `/vendor`          | Partner kitchen order dashboard           |
| `/rider`           | Delivery rider dashboard                  |
| `/api`             | JSON catalog API                          |
| `/demo`            | Interactive ordering-flow walkthrough     |

---

## Project layout

```
├── *.php                 Customer storefront & auth pages
├── admin.php / admin_*.php   Admin panel pages
├── vendor*.php / rider.php   Delivery dashboards (shared: delivery_inc.php)
├── api.php + api/        JSON catalog + live notification feed
├── css/style.css         Shared stylesheet
├── js/                   Vanilla JS (cart, catalog, notify, lightbox, admin variants, scroll memory)
├── uploads/              Uploaded images (products, avatars, KYC, hero slides)
├── db.php                PDO connection (env-var driven)
├── site_config.php       Settings helpers + runtime table auto-creation
├── schema.sql            MySQL schema + seed data
├── migrate.php           One-time data.json → MySQL importer
├── .htaccess             Pretty-URL routing
└── .cpanel.yml           cPanel deployment config
```

---

## Security notes

- All database access uses **prepared statements** (PDO).
- Admin, vendor, rider and checkout actions are protected by **CSRF tokens**.
- Passwords are stored as **bcrypt hashes**.
- Admin, vendor and rider sessions use separate session cookies.
- Output is HTML-escaped (`htmlspecialchars`) on rendered pages.

## Before public deployment

- Move admin/vendor/rider credentials to a proper account-management system.
- Replace simulated checkout with a real payment gateway.
- Add HTTPS, rate limiting on auth, and server-side image validation.
- Back up the database and rotate any seeded credentials.
