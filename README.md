# LyaiDeu — Upgraded College Project (V3)

## Included improvements

### Stage 1 — Stability & security
- Fixed admin-save behavior so registered users and future data are preserved.
- CSRF protection for admin and checkout actions.
- Hashed demo admin password.
- Safer data cleaning and output escaping.
- Admin-only order management.

### Stage 2 — Customer ordering
- Shopping cart with quantity controls.
- Favorites stored in the browser.
- Search, category filtering, and price/rating sorting.
- Checkout with delivery details.
- Server-side order total calculation.
- Demo promo code `LYAIDEU` for free delivery.
- Order confirmation and order history.

### Stage 3 — Admin operations
- Dashboard statistics.
- Order management.
- Status workflow: Pending → Confirmed → Preparing → Out for delivery → Delivered.
- Cancellation support.
- Existing dish/hotel/contact management remains available.

### Stage 4 — UI/UX
- Cart drawer.
- Responsive checkout and order pages.
- Favorites buttons.
- Sorting controls.
- Mobile-friendly admin/order screens.
- Interactive `demo.html` workflow.

## Run in XAMPP/WAMP

1. Put this folder inside your web root, for example `htdocs/lyaideu`.
2. Make sure PHP can write to `data.json`.
3. Open `login.php`.
4. Product walkthrough: `demo.html`.
5. Admin panel: `admin.php`.
6. Demo admin password: `admin123`.

## Important
This is a college-project demo. Payment is not real. Before public deployment, move authentication/orders to MySQL and use a proper admin account system.
