<?php

require_once __DIR__ . '/admin_inc.php';
admin_require_login();
require_once __DIR__ . '/db.php';

lyaideu_ensure_kyc_tables();

$tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
$tab = (string)($_GET['tab'] ?? 'pending');
if (!isset($tabs[$tab])) {
    $tab = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token.');
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: admin_kyc?error=' . urlencode('Missing user.'));
        exit;
    }

    if (isset($_POST['approve'])) {
        try {
            $st = $pdo->prepare(
                'UPDATE users SET kyc_status = \'approved\', kyc_reason = \'\', kyc_reviewed_at = :now, kyc_reviewer = :who WHERE id = :id'
            );
            $st->execute([
                ':now' => date('Y-m-d H:i:s'),
                ':who' => admin_username(),
                ':id' => $userId,
            ]);
            header('Location: admin_kyc?tab=' . $tab . '&saved=1');
            exit;
        } catch (Throwable $e) {
            header('Location: admin_kyc?tab=' . $tab . '&error=' . urlencode('Could not approve the user.'));
            exit;
        }
    }

    if (isset($_POST['reject'])) {
        $reason = trim(strip_tags((string)($_POST['kyc_reason'] ?? '')));
        if ($reason === '') {
            header('Location: admin_kyc?tab=' . $tab . '&error=' . urlencode('Please provide a rejection reason.'));
            exit;
        }
        try {
            $st = $pdo->prepare(
                'UPDATE users SET kyc_status = \'rejected\', kyc_reason = :reason, kyc_reviewed_at = :now, kyc_reviewer = :who WHERE id = :id'
            );
            $st->execute([
                ':reason' => mb_substr($reason, 0, 500),
                ':now' => date('Y-m-d H:i:s'),
                ':who' => admin_username(),
                ':id' => $userId,
            ]);
            header('Location: admin_kyc?tab=' . $tab . '&saved=1');
            exit;
        } catch (Throwable $e) {
            header('Location: admin_kyc?tab=' . $tab . '&error=' . urlencode('Could not reject the user.'));
            exit;
        }
    }

    header('Location: admin_kyc?tab=' . $tab);
    exit;
}

$users = [];
try {
    $sql = 'SELECT u.id, u.name, u.email, u.phone, u.dob, u.address, u.avatar,
                   u.kyc_status, u.kyc_reason, u.kyc_submitted_at, u.kyc_reviewed_at, u.kyc_reviewer,
                   (SELECT COUNT(*) FROM user_documents d WHERE d.user_id = u.id) AS doc_count
            FROM users u';
    if ($tab !== 'all') {
        $sql .= " WHERE u.kyc_status = :st";
    }
    $sql .= ' ORDER BY (u.kyc_status = \'pending\') DESC, u.kyc_submitted_at DESC, u.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($tab !== 'all' ? [':st' => $tab] : []);
    $users = $st->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load KYC applications.');
}

$docStmt = $pdo->prepare('SELECT id, doc_type, file, uploaded_at FROM user_documents WHERE user_id = ? ORDER BY id');
$docsByUser = [];
foreach ($users as $u) {
    $docStmt->execute([(int)$u['id']]);
    $docsByUser[(int)$u['id']] = $docStmt->fetchAll();
}

$statusLabels = ['none' => 'Not submitted', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$statusClasses = ['none' => 'kyc-none', 'pending' => 'kyc-pending', 'approved' => 'kyc-approved', 'rejected' => 'kyc-rejected'];
$csrf = admin_csrf_token();

admin_page_start('KYC', 'kyc', 'KYC Verification');
?>
<section class="admin-section">
    <div class="admin-section-top">
        <p class="section-sub">Customers must be verified before they can place orders. Review their identity documents and approve or reject them.</p>
        <span class="admin-count-badge" id="kycShown"><?= count($users) ?> shown</span>
    </div>

    <div class="admin-order-tools">
        <div class="admin-order-search">
            <span><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="search" id="kycSearch" placeholder="Search by name, email, phone or status…" autocomplete="off">
        </div>
    </div>

    <div class="admin-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="admin-tab<?= $tab === $key ? ' active' : '' ?>" href="admin_kyc?tab=<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$users): ?>
        <p class="admin-empty-note">No users in this view.</p>
    <?php endif; ?>

    <div class="admin-grid">
        <?php foreach ($users as $u):
            $uid = (int)$u['id'];
            $av = htmlspecialchars((string)$u['avatar'], ENT_QUOTES, 'UTF-8');
            $st = (string)($u['kyc_status'] ?? 'none');
            $parts = preg_split('/\s+/', trim((string)$u['name']));
            $ini = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            $docs = $docsByUser[$uid] ?? [];
            $searchTxt = mb_strtolower(implode(' ', [
                (string)$u['name'], (string)$u['email'], (string)$u['phone'], (string)$u['dob'], (string)$u['address'],
                (string)($statusLabels[$st] ?? $st), (string)$u['kyc_reviewer'],
            ]));
        ?>
        <div class="admin-card admin-kyc-card" data-search="<?= htmlspecialchars($searchTxt, ENT_QUOTES, 'UTF-8') ?>">
            <div class="kyc-card-head">
                <div class="avatar-preview"<?= $av !== '' ? ' style="background-image:url(\'' . $av . '\')" data-lightbox="' . $av . '" data-lightbox-caption="' . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= $av === '' ? htmlspecialchars($ini) : '' ?></div>
                <div>
                    <h3><?= htmlspecialchars($u['name']) ?></h3>
                    <span class="order-status-pill <?= $statusClasses[$st] ?? 'kyc-none' ?>"><?= htmlspecialchars($statusLabels[$st] ?? $st) ?></span>
                </div>
            </div>
            <div class="kyc-meta">
                <p><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($u['email']) ?></p>
                <p><i class="fa-solid fa-mobile-screen"></i> +977 <?= htmlspecialchars($u['phone']) ?></p>
                <p><i class="fa-solid fa-cake-candles"></i> <?= htmlspecialchars($u['dob']) ?></p>
                <p><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars((string)$u['address'] ?: '—') ?></p>
                <p><i class="fa-solid fa-paper-plane"></i> Submitted: <?= htmlspecialchars((string)$u['kyc_submitted_at'] ?: '—') ?></p>
                <?php if ($st === 'rejected'): ?>
                    <p class="kyc-reject-note"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars((string)$u['kyc_reason'] ?: 'No reason given.') ?></p>
                <?php endif; ?>
                <?php if (in_array($st, ['approved', 'rejected'], true) && (string)$u['kyc_reviewer'] !== ''): ?>
                    <p class="small-note"><i class="fa-solid fa-user-check"></i> Reviewed by <?= htmlspecialchars((string)$u['kyc_reviewer']) ?> on <?= htmlspecialchars((string)$u['kyc_reviewed_at']) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($docs): ?>
                <ul class="kyc-doc-list">
                <?php foreach ($docs as $doc):
                    $f = htmlspecialchars((string)$doc['file'], ENT_QUOTES, 'UTF-8');
                    $isPdf = strtolower(pathinfo((string)$doc['file'], PATHINFO_EXTENSION)) === 'pdf';
                    $caption = htmlspecialchars((string)$doc['doc_type'] . ' — ' . $u['name'], ENT_QUOTES, 'UTF-8');
                ?>
                    <li>
                        <span class="kyc-doc-ico"><?= $isPdf ? '<i class="fa-solid fa-file-pdf"></i>' : '<i class="fa-solid fa-file-image"></i>' ?></span>
                        <span class="kyc-doc-name"><?= htmlspecialchars((string)$doc['doc_type']) ?></span>
                        <?php if ($isPdf): ?>
                        <a class="btn btn-outline btn-sm" href="<?= $f ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> View</a>
                        <?php else: ?>
                        <a class="btn btn-outline btn-sm" href="<?= $f ?>" data-lightbox="<?= $f ?>" data-lightbox-caption="<?= $caption ?>"><i class="fa-solid fa-expand"></i> View</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small-note">No documents uploaded.</p>
            <?php endif; ?>

            <?php if ($st === 'pending'): ?>
                <form method="POST" class="kyc-actions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <button type="submit" name="approve" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-circle-check"></i> Approve</button>
                    <textarea name="kyc_reason" placeholder="Rejection reason (required to reject)"></textarea>
                    <button type="submit" name="reject" value="1" class="btn btn-outline btn-block"><i class="fa-solid fa-circle-xmark"></i> Reject</button>
                </form>
            <?php else: ?>
                <form method="POST" class="kyc-actions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <?php if ($st !== 'approved'): ?>
                        <button type="submit" name="approve" value="1" class="btn btn-primary btn-block"><i class="fa-solid fa-circle-check"></i> Approve</button>
                    <?php endif; ?>
                    <?php if ($st !== 'rejected'): ?>
                        <textarea name="kyc_reason" placeholder="Rejection reason (required to reject)"></textarea>
                        <button type="submit" name="reject" value="1" class="btn btn-outline btn-block"><i class="fa-solid fa-circle-xmark"></i> Reject</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <p class="admin-empty-note" id="kycSearchEmpty" style="display:none">No users match your search.</p>
    </div>
</section>
<script>
(function () {
    var input = document.getElementById('kycSearch');
    if (!input) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.admin-kyc-card'));
    var shown = document.getElementById('kycShown');
    var emptyEl = document.getElementById('kycSearchEmpty');
    function apply() {
        var q = input.value.trim().toLowerCase();
        var n = 0;
        cards.forEach(function (card) {
            var hay = (card.getAttribute('data-search') || '').toLowerCase();
            var show = !q || hay.indexOf(q) !== -1;
            card.style.display = show ? '' : 'none';
            if (show) n++;
        });
        if (shown) shown.textContent = n + ' shown';
        if (emptyEl) emptyEl.style.display = (cards.length > 0 && n === 0) ? '' : 'none';
    }
    input.addEventListener('input', apply);
})();
</script>
<script src="js/lightbox.js?v=2"></script>
<?php
admin_page_end();