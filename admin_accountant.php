<?php
// Accountant — read-only order accounts view (totals, day / date+time filters, CSV).
// No POST handling on purpose: accountants can view order details but never edit them.

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
admin_require_page('accountant');
require_once __DIR__ . '/db.php';

lyaideu_ensure_delivery_tables();

$allowedStatus = ['Pending', 'Confirmed', 'Preparing', 'Ready for pickup', 'Out for delivery', 'Delivered', 'Cancelled'];
$ce = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/** Convert a Nepal-wall-clock date+time to UTC 'Y-m-d H:i:s' for SQL comparison. */
function accountant_npt_to_utc(string $date, string $clock): ?string {
    try {
        $dt = new DateTimeImmutable($date . ' ' . $clock, new DateTimeZone('Asia/Kathmandu'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------- Filters (GET only) ----------------
$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, $allowedStatus, true)) {
    $status = '';
}
$payment = trim((string)($_GET['payment'] ?? ''));
if (mb_strlen($payment) > 60) {
    $payment = mb_substr($payment, 0, 60);
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$fromTime = trim((string)($_GET['from_time'] ?? ''));
$toTime = trim((string)($_GET['to_time'] ?? ''));
if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}
if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = '';
}
if ($fromTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $fromTime)) {
    $fromTime = '';
}
if ($toTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $toTime)) {
    $toTime = '';
}
// Time without a date is ignored (TIME() on UTC rows would mislead NPT users).
if ($fromDate === '') {
    $fromTime = '';
}
if ($toDate === '') {
    $toTime = '';
}

$rangeFromUtc = $fromDate !== ''
    ? accountant_npt_to_utc($fromDate, ($fromTime !== '' ? $fromTime . ':00' : '00:00:00'))
    : null;
$rangeToUtc = $toDate !== ''
    ? accountant_npt_to_utc($toDate, ($toTime !== '' ? $toTime . ':59' : '23:59:59'))
    : null;
if ($rangeFromUtc !== null && $rangeToUtc !== null && $rangeFromUtc > $rangeToUtc) {
    // Swapped range: keep it usable instead of returning zero rows.
    [$rangeFromUtc, $rangeToUtc] = [$rangeToUtc, $rangeFromUtc];
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(o.customer_name LIKE :q OR o.phone LIKE :q2 OR CAST(o.id AS CHAR) LIKE :q3)';
    $params[':q'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'o.status = :st';
    $params[':st'] = $status;
}
if ($payment !== '') {
    $where[] = 'o.payment = :pay';
    $params[':pay'] = $payment;
}
if ($rangeFromUtc !== null) {
    $where[] = 'o.created_at >= :rf';
    $params[':rf'] = $rangeFromUtc;
}
if ($rangeToUtc !== null) {
    $where[] = 'o.created_at <= :rt';
    $params[':rt'] = $rangeToUtc;
}
$whereSql = implode(' AND ', $where);

// Payment dropdown options.
$payOptions = [];
try {
    $payOptions = $pdo->query("SELECT DISTINCT payment FROM orders WHERE payment <> '' ORDER BY payment LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $payOptions = [];
}

/** Vendor names + item rows for a set of order ids. */
function accountant_enrich(PDO $pdo, array $ids): array {
    $vendorsByOrder = [];
    $itemsByOrder = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [$vendorsByOrder, $itemsByOrder];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT ovs.order_id, COALESCE(NULLIF(v.name,''), '') AS vname
             FROM order_vendor_status ovs LEFT JOIN vendors v ON v.id = ovs.vendor_id
             WHERE ovs.order_id IN ($ph)"
        );
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $oid = (int)$r['order_id'];
            $nm = trim((string)$r['vname']);
            if ($nm === '') {
                continue;
            }
            $vendorsByOrder[$oid][$nm] = true;
        }
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare(
            "SELECT order_id, name, hotel, price, qty, line_total, variant, vendor_id
             FROM order_items WHERE order_id IN ($ph) ORDER BY id"
        );
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $oid = (int)$it['order_id'];
            $itemsByOrder[$oid][] = $it;
        }
        // Fallback: items whose vendor never got an order_vendor_status row.
        try {
            $st2 = $pdo->prepare(
                "SELECT DISTINCT oi.order_id, v.name AS vname
                 FROM order_items oi JOIN vendors v ON v.id = oi.vendor_id
                 WHERE oi.order_id IN ($ph) AND oi.vendor_id IS NOT NULL"
            );
            $st2->execute($ids);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nm = trim((string)$r['vname']);
                if ($nm !== '') {
                    $vendorsByOrder[(int)$r['order_id']][$nm] = true;
                }
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    $vendorNames = [];
    foreach ($vendorsByOrder as $oid => $set) {
        $vendorNames[$oid] = array_keys($set);
    }
    return [$vendorNames, $itemsByOrder];
}

function accountant_items_summary(array $items): string {
    $parts = [];
    foreach ($items as $it) {
        $nm = trim((string)($it['name'] ?? ''));
        if ($nm === '') {
            continue;
        }
        $p = (int)$it['qty'] . 'x ' . $nm;
        if (trim((string)($it['variant'] ?? '')) !== '') {
            $p .= ' (' . trim((string)$it['variant']) . ')';
        }
        $p .= ' Rs.' . (int)$it['line_total'];
        $parts[] = $p;
    }
    return implode(' | ', $parts);
}

// ---------------- CSV export (same filters, read-only) ----------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!$pdo instanceof PDO) {
        http_response_code(500);
        exit('DB unavailable');
    }
    try {
        $sql = "SELECT o.id, o.customer_name, o.phone, o.address, o.payment,
                       o.subtotal, o.delivery_fee, o.discount, o.total, o.status, o.created_at,
                       r.name AS rider_name, r.phone AS rider_phone
                FROM orders o LEFT JOIN riders r ON r.id = o.rider_id
                WHERE $whereSql ORDER BY o.created_at DESC LIMIT 5000";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Could not load orders.');
    }
    $ids = array_column($rows, 'id');
    [$vendorNames, $itemsByOrder] = accountant_enrich($pdo, $ids);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="accountant_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'ordered_at_npt', 'customer_name', 'phone', 'address', 'payment', 'subtotal', 'delivery_fee', 'discount', 'total', 'status', 'rider_name', 'rider_phone', 'vendor_names', 'items_summary']);
    foreach ($rows as $r) {
        $oid = (int)$r['id'];
        $items = $itemsByOrder[$oid] ?? [];
        fputcsv($out, [
            $oid,
            function_exists('lyaideu_np_time') ? lyaideu_np_time((string)$r['created_at']) : (string)$r['created_at'],
            (string)$r['customer_name'],
            (string)$r['phone'],
            (string)$r['address'],
            (string)$r['payment'],
            (string)$r['subtotal'],
            (string)$r['delivery_fee'],
            (string)$r['discount'],
            (string)$r['total'],
            (string)$r['status'],
            (string)($r['rider_name'] ?? ''),
            (string)($r['rider_phone'] ?? ''),
            implode(', ', $vendorNames[$oid] ?? []),
            accountant_items_summary($items),
        ]);
    }
    fclose($out);
    exit;
}

// ---------------- Summaries + page rows ----------------
$totalOrders = 0;
$totalRevenue = 0;
$todayOrders = 0;
$todayRevenue = 0;
$filteredOrders = 0;
$filteredRevenue = 0;
$filteredAvg = 0;
$payBreakdown = [];
$rows = [];
$pageCount = 0;
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;
$off = ($page - 1) * $per;
$baseQuery = http_build_query(array_filter([
    'q' => $q !== '' ? $q : null,
    'status' => $status !== '' ? $status : null,
    'payment' => $payment !== '' ? $payment : null,
    'from_date' => $fromDate !== '' ? $fromDate : null,
    'to_date' => $toDate !== '' ? $toDate : null,
    'from_time' => $fromTime !== '' ? $fromTime : null,
    'to_time' => $toTime !== '' ? $toTime : null,
]));

try {
    $r = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total ELSE 0 END),0) AS rev FROM orders")->fetch();
    $totalOrders = (int)($r['c'] ?? 0);
    $totalRevenue = (int)round((float)($r['rev'] ?? 0));

    // Today in Nepal time (DB stores UTC).
    $nptToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');
    $todayStartUtc = accountant_npt_to_utc($nptToday, '00:00:00');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $tst = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN total ELSE 0 END),0) AS rev FROM orders WHERE created_at >= :s AND created_at <= :e");
    $tst->execute([':s' => $todayStartUtc, ':e' => $nowUtc]);
    $t = $tst->fetch();
    $todayOrders = (int)($t['c'] ?? 0);
    $todayRevenue = (int)round((float)($t['rev'] ?? 0));

    $fst = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN o.status <> 'Cancelled' THEN o.total ELSE 0 END),0) AS rev, COALESCE(AVG(CASE WHEN o.status <> 'Cancelled' THEN o.total END),0) AS avgv FROM orders o WHERE $whereSql");
    $fst->execute($params);
    $f = $fst->fetch();
    $filteredOrders = (int)($f['c'] ?? 0);
    $filteredRevenue = (int)round((float)($f['rev'] ?? 0));
    $filteredAvg = (int)round((float)($f['avgv'] ?? 0));
    $pageCount = (int)ceil($filteredOrders / $per);

    $pst = $pdo->prepare("SELECT o.payment AS pay, COUNT(*) AS c, COALESCE(SUM(CASE WHEN o.status <> 'Cancelled' THEN o.total ELSE 0 END),0) AS rev FROM orders o WHERE $whereSql GROUP BY o.payment ORDER BY c DESC LIMIT 20");
    $pst->execute($params);
    $payBreakdown = $pst->fetchAll(PDO::FETCH_ASSOC);

    $lst = $pdo->prepare(
        "SELECT o.id, o.customer_name, o.phone, o.address, o.note, o.payment,
                o.subtotal, o.delivery_fee, o.discount, o.total, o.status, o.created_at,
                r.name AS rider_name, r.phone AS rider_phone
         FROM orders o LEFT JOIN riders r ON r.id = o.rider_id
         WHERE $whereSql ORDER BY o.created_at DESC LIMIT $per OFFSET $off"
    );
    $lst->execute($params);
    $rows = $lst->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load accountant data.');
}

[$vendorNames, $itemsByOrder] = accountant_enrich($pdo, array_column($rows, 'id'));

admin_page_start('Accountant', 'accountant', 'Accountant — Orders & Revenue');
?>
<style>
.acct-filters{display:flex;flex-wrap:wrap;gap:.55rem;margin-top:.9rem;align-items:center}
.acct-filters input,.acct-filters select{padding:.5rem .7rem;border:2px solid var(--orange-200);border-radius:999px;font:inherit;font-size:.84rem;min-width:0;background:#fff}
.acct-filters input[type="search"]{flex:1 1 170px}
.acct-filters input[type="date"],.acct-filters input[type="time"]{flex:0 1 auto}
.acct-filters .btn{border-radius:999px}
.acct-hint{font-size:.76rem;color:var(--muted);font-weight:700}
.admin-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.admin-table{width:100%;border-collapse:collapse;font-size:.84rem}
.admin-table th,.admin-table td{padding:.6rem .7rem;border-bottom:1px solid var(--orange-100);text-align:left;vertical-align:top}
.admin-table th{font-weight:900;background:var(--orange-50);position:sticky;top:0;white-space:nowrap}
.admin-table td.num{white-space:nowrap;font-variant-numeric:tabular-nums}
.pay-chips{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.7rem}
.pay-chip{background:var(--orange-100);color:var(--orange-800);font-weight:800;font-size:.76rem;padding:.32rem .7rem;border-radius:999px}
.acct-items{font-size:.8rem;color:var(--muted)}
.acct-items summary{cursor:pointer;font-weight:800;color:var(--orange-700)}
.acct-items ul{margin:.4rem 0 0;padding-left:1.1rem}
@media (max-width:700px){
  .acct-filters{flex-direction:column;align-items:stretch}
  .acct-filters input,.acct-filters select{width:100%}
  .admin-table{font-size:.78rem}
  .hide-sm{display:none}
  .admin-table th,.admin-table td{padding:.45rem .5rem}
}
</style>

<div class="admin-stats">
    <div class="stat-total"><span class="stat-ico"><i class="fa-solid fa-receipt"></i></span><strong><?= number_format($totalOrders) ?></strong><span>Total Orders</span></div>
    <div class="stat-delivered"><span class="stat-ico"><i class="fa-solid fa-sack-dollar"></i></span><strong>Rs. <?= number_format($totalRevenue) ?></strong><span>Total Revenue (excl. Cancelled)</span></div>
    <div class="stat-preparing"><span class="stat-ico"><i class="fa-solid fa-calendar-day"></i></span><strong><?= number_format($todayOrders) ?></strong><span>Today's Orders (NPT)</span></div>
    <div class="stat-confirmed"><span class="stat-ico"><i class="fa-solid fa-coins"></i></span><strong>Rs. <?= number_format($todayRevenue) ?></strong><span>Today's Revenue (NPT)</span></div>
    <div class="stat-pending"><span class="stat-ico"><i class="fa-solid fa-filter"></i></span><strong><?= number_format($filteredOrders) ?></strong><span>Filtered Orders</span></div>
    <div class="stat-ready"><span class="stat-ico"><i class="fa-solid fa-wallet"></i></span><strong>Rs. <?= number_format($filteredRevenue) ?></strong><span>Filtered Revenue · Avg Rs. <?= number_format($filteredAvg) ?></span></div>
</div>

<section class="admin-section">
    <div class="admin-section-top" style="flex-wrap:wrap;gap:.7rem">
        <p class="section-sub">View-only accounts ledger. Pick a date range, optionally narrow to a time window within those days, then export the exact same rows to CSV.</p>
        <a class="btn btn-outline" href="admin_accountant?<?= $ce($baseQuery !== '' ? $baseQuery . '&' : '') ?>export=csv"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    </div>
    <form method="GET" action="admin_accountant" class="acct-filters">
        <input type="search" name="q" value="<?= $ce($q) ?>" placeholder="Search name, phone or order #…" autocomplete="off">
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach ($allowedStatus as $st): ?>
            <option value="<?= $ce($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= $ce($st) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="payment" aria-label="Filter by payment">
            <option value="">All payments</option>
            <?php foreach ($payOptions as $po): ?>
            <option value="<?= $ce($po) ?>"<?= $payment === (string)$po ? ' selected' : '' ?>><?= $ce($po) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from_date" value="<?= $ce($fromDate) ?>" aria-label="From date">
        <input type="date" name="to_date" value="<?= $ce($toDate) ?>" aria-label="To date">
        <input type="time" name="from_time" value="<?= $ce($fromTime) ?>" aria-label="From time (optional)">
        <input type="time" name="to_time" value="<?= $ce($toTime) ?>" aria-label="To time (optional)">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <a class="btn btn-outline" href="admin_accountant">Reset</a>
    </form>
    <p class="acct-hint">Dates &amp; times are Nepal time (NPT). Time alone does nothing — pair it with a From/To date, e.g. Sept 10 + 10:00–14:00 for the lunch rush.</p>
    <?php if ($payBreakdown): ?>
    <div class="pay-chips" aria-label="Payment breakdown for current filter">
        <?php foreach ($payBreakdown as $pb): ?>
        <span class="pay-chip"><i class="fa-solid fa-credit-card"></i> <?= $ce($pb['pay'] !== '' ? $pb['pay'] : 'Unknown') ?> · <?= number_format((int)$pb['c']) ?> · Rs. <?= number_format((int)round((float)$pb['rev'])) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="admin-section" style="padding:0;overflow:hidden">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>#</th><th>Ordered (NPT)</th><th>Customer</th><th>Items &amp; Vendors</th><th>Rider</th><th>Payment</th><th>Totals</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" style="text-align:center;padding:1.2rem;color:var(--muted)">No orders match this filter.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $o):
                    $oid = (int)$o['id'];
                    $items = $itemsByOrder[$oid] ?? [];
                    $vnames = $vendorNames[$oid] ?? [];
                    $pill = function_exists('lyaideu_order_pill_class') ? lyaideu_order_pill_class((string)$o['status']) : 'pending';
                ?>
                <tr>
                    <td class="num"><strong>#<?= $oid ?></strong></td>
                    <td style="white-space:nowrap"><?= $ce(function_exists('lyaideu_np_time') ? lyaideu_np_time((string)$o['created_at']) : (string)$o['created_at']) ?></td>
                    <td>
                        <strong><?= $ce($o['customer_name']) ?></strong><br>
                        <a href="tel:+977<?= $ce($o['phone']) ?>"><?= $ce($o['phone']) ?></a>
                        <span class="hide-sm"><br><?= $ce($o['address']) ?><?php if (trim((string)$o['note']) !== ''): ?><br><em>Note: <?= $ce($o['note']) ?></em><?php endif; ?></span>
                    </td>
                    <td>
                        <span class="acct-hint"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
                        <?php if ($vnames): ?><br><span class="pay-chip" style="margin-top:.25rem;display:inline-block"><i class="fa-solid fa-store"></i> <?= $ce(implode(', ', $vnames)) ?></span><?php endif; ?>
                        <?php if ($items): ?>
                        <details class="acct-items">
                            <summary>View items</summary>
                            <ul>
                                <?php foreach ($items as $it): ?>
                                <li><?= (int)$it['qty'] ?>x <?= $ce($it['name']) ?><?php if (trim((string)($it['variant'] ?? '')) !== ''): ?> (<?= $ce($it['variant']) ?>)<?php endif; ?> — Rs. <?= (int)$it['line_total'] ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td><?= ($o['rider_name'] ?? '') !== '' ? $ce($o['rider_name']) . '<br><span class="acct-hint">' . $ce($o['rider_phone']) . '</span>' : '<span class="acct-hint">—</span>' ?></td>
                    <td><?= $ce($o['payment']) ?></td>
                    <td class="num">Sub Rs. <?= (int)$o['subtotal'] ?><br>Del Rs. <?= (int)$o['delivery_fee'] ?><br>Disc Rs. <?= (int)$o['discount'] ?><br><strong>Total Rs. <?= (int)$o['total'] ?></strong></td>
                    <td><span class="order-status-pill status-<?= $ce($pill) ?>"><?= $ce($o['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:.8rem 1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem">
        <small style="color:var(--muted)">Total <?= number_format($filteredOrders) ?> · Page <?= $page ?><?= $pageCount > 0 ? ' of ' . number_format($pageCount) : '' ?> · View only — editing stays on the Orders page.</small>
        <div style="display:flex;gap:.4rem">
            <?php if ($page > 1): ?><a class="btn btn-outline" href="admin_accountant?<?= $ce($baseQuery) ?>&amp;p=<?= $page - 1 ?>">Prev</a><?php endif; ?>
            <?php if ($pageCount > 0 && $page < $pageCount): ?><a class="btn btn-outline" href="admin_accountant?<?= $ce($baseQuery) ?>&amp;p=<?= $page + 1 ?>">Next</a><?php endif; ?>
        </div>
    </div>
</section>
<?php
admin_page_end();
