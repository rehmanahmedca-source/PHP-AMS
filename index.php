<?php
declare(strict_types=1);
session_start();

const DB_FILE = __DIR__ . '/data/ahmed_cement.db';

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    }
    return $pdo;
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid request token');
    }
}
function money($n): string { return 'Rs ' . number_format((float)$n, 2); }
function minor($n): int { return (int)round(((float)$n) * 100); }
function num($v): float { return (float)str_replace(',', '', (string)($v ?? 0)); }
function post(string $k, $default = null) { return $_POST[$k] ?? $default; }
function post_date(string $k, string $time = ''): string {
    $d = (string)($_POST[$k] ?? '');
    if ($d === '') $d = date('Y-m-d');
    return strlen($d) === 10 ? $d . $time : $d;
}
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function uid(): ?int { return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null; }
function setting(string $k, $default = null) {
    static $row;
    $row ??= db()->query('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
    return $row[$k] ?? $default;
}
function next_bill(string $namespace, string $prefix, int $pad = 4): string {
    $s = db()->prepare('SELECT count FROM bill_counter WHERE namespace=?');
    $s->execute([$namespace]);
    $count = $s->fetchColumn();
    if ($count === false) {
        db()->prepare('INSERT INTO bill_counter(namespace,count) VALUES(?,1)')->execute([$namespace]);
        $count = 1;
    } else {
        $count = (int)$count + 1;
        db()->prepare('UPDATE bill_counter SET count=? WHERE namespace=?')->execute([$count, $namespace]);
    }
    return $prefix . str_pad((string)$count, $pad, '0', STR_PAD_LEFT);
}
function next_code(string $table, string $prefix): string {
    $row = db()->query("SELECT code FROM \"$table\" WHERE code LIKE " . db()->quote($prefix . '%') . " ORDER BY id DESC LIMIT 1")->fetch();
    $n = 1;
    if ($row && preg_match('/(\d+)\s*$/', (string)$row['code'], $m)) $n = (int)$m[1] + 1;
    return $prefix . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}
function audit(string $action, string $details): void {
    db()->prepare('INSERT INTO audit_log(user_id,username,action,details) VALUES(?,?,?,?)')
        ->execute([uid(), $_SESSION['user']['username'] ?? 'system', $action, $details]);
}
function posted_items(): array {
    $out = [];
    foreach ($_POST['items'] ?? [] as $row) {
        $mid = (int)($row['material_id'] ?? 0);
        $qty = num($row['qty'] ?? 0);
        $rate = num($row['rate'] ?? 0);
        if ($mid && $qty > 0) $out[] = ['material_id' => $mid, 'qty' => $qty, 'rate' => $rate];
    }
    return $out;
}
function party_name(string $type, int $id): string {
    $map = ['client' => 'clients', 'supplier' => 'suppliers', 'delivery_person' => 'delivery_persons', 'lender' => 'lenders'];
    if (!$id || !isset($map[$type])) return '';
    $s = db()->prepare("SELECT name FROM {$map[$type]} WHERE id=?");
    $s->execute([$id]);
    return (string)($s->fetchColumn() ?: '');
}
function badge(string $v): string {
    $cls = strtolower(str_replace(' ', '_', $v));
    return '<span class="badge ' . h($cls) . '">' . h(ucwords(str_replace('_', ' ', $v))) . '</span>';
}

$modules = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => '⌂', 'group' => ''],
    'sales' => ['label' => 'Sales', 'icon' => '▣', 'group' => 'OPERATIONS'],
    'bookings' => ['label' => 'Bookings', 'icon' => '◫', 'group' => 'OPERATIONS'],
    'purchases' => ['label' => 'Purchases / GRN', 'icon' => '▤', 'group' => 'OPERATIONS'],
    'payments' => ['label' => 'Payments', 'icon' => '↔', 'group' => 'OPERATIONS'],
    'returns' => ['label' => 'Returns', 'icon' => '↩', 'group' => 'OPERATIONS'],
    'pending_bills' => ['label' => 'Pending Bills', 'icon' => '◷', 'group' => 'OPERATIONS'],
    'materials' => ['label' => 'Materials & Stock', 'icon' => '▥', 'group' => 'MASTERS'],
    'clients' => ['label' => 'Clients', 'icon' => '◎', 'group' => 'MASTERS'],
    'suppliers' => ['label' => 'Suppliers', 'icon' => '◇', 'group' => 'MASTERS'],
    'delivery_persons' => ['label' => 'Delivery Team', 'icon' => '♧', 'group' => 'MASTERS'],
    'accounts' => ['label' => 'Accounts', 'icon' => '◉', 'group' => 'MASTERS'],
    'reports' => ['label' => 'Reports', 'icon' => '▤', 'group' => 'INSIGHTS'],
    'users' => ['label' => 'Users & Roles', 'icon' => '♙', 'group' => 'ADMINISTRATION'],
    'settings' => ['label' => 'Settings', 'icon' => '⚙', 'group' => 'ADMINISTRATION'],
    'audit_log' => ['label' => 'Audit Trail', 'icon' => '◌', 'group' => 'ADMINISTRATION'],
];
$key = $_GET['module'] ?? 'dashboard';
if (!isset($modules[$key])) $key = 'dashboard';

if (!isset($_SESSION['user'])) {
    $u = db()->query("SELECT u.*, r.name role_name, r.is_admin_role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 ORDER BY u.id LIMIT 1")->fetch();
    if ($u) $_SESSION['user'] = $u;
}
if (isset($_GET['logout'])) { session_destroy(); redirect('?'); }

/* ---------------- Lookups for searchable lists ---------------- */
function lookups(): array {
    static $cache;
    if ($cache) return $cache;
    $clients = db()->query("SELECT c.id, c.code, c.name, c.phone, c.default_type, COALESCE(b.balance, c.opening_balance, 0) AS balance
        FROM clients c LEFT JOIN v_client_balances b ON b.client_id=c.id WHERE c.active=1 ORDER BY c.name")->fetchAll();
    $materials = db()->query("SELECT m.id, m.code, m.name, m.unit, cat.name AS category,
        COALESCE(st.current_qty, 0) AS stock,
        CASE WHEN m.current_rate > 0 THEN m.current_rate ELSE COALESCE((
            SELECT si.rate FROM sale_items si WHERE si.material_id=m.id AND si.rate>0 ORDER BY si.id DESC LIMIT 1
        ), 0) END AS rate
        FROM materials m
        LEFT JOIN categories cat ON cat.id=m.category_id
        LEFT JOIN v_material_stock st ON st.material_id=m.id
        WHERE m.active=1 ORDER BY m.name")->fetchAll();
    $suppliers = db()->query("SELECT id, code, name, phone FROM suppliers WHERE active=1 ORDER BY name")->fetchAll();
    $accounts = db()->query("SELECT id, name, account_type, balance FROM accounts WHERE active=1 ORDER BY name")->fetchAll();
    $deliveries = db()->query("SELECT id, code, name, phone FROM delivery_persons WHERE active=1 ORDER BY name")->fetchAll();
    $lenders = db()->query("SELECT id, code, name, phone FROM lenders WHERE active=1 ORDER BY name")->fetchAll();
    $bookings = db()->query("SELECT b.id, b.auto_bill_no, b.manual_bill_no, b.client_id, c.name AS client_name, b.status,
        COALESCE((SELECT SUM(qty_booked-qty_dispatched-qty_cancelled) FROM booking_items bi WHERE bi.booking_id=b.id),0) AS pending
        FROM bookings b JOIN clients c ON c.id=b.client_id
        WHERE b.status IN ('active','partially_cancelled') ORDER BY b.id DESC")->fetchAll();
    $categories = db()->query("SELECT id, category_type, name FROM categories WHERE active=1 ORDER BY category_type, name")->fetchAll();
    $roles = db()->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();
    $cache = compact('clients', 'materials', 'suppliers', 'accounts', 'deliveries', 'lenders', 'bookings', 'categories', 'roles');
    return $cache;
}
function cats(string $type): array {
    return array_values(array_filter(lookups()['categories'], fn($c) => $c['category_type'] === $type));
}

/* ---------------- AJAX ---------------- */
$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'booking_info') {
    header('Content-Type: application/json; charset=utf-8');
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    $clientId = (int)($_GET['client_id'] ?? 0);
    $items = []; $balance = 0; $booking = null;
    if ($bookingId) {
        $sql = "SELECT * FROM v_client_booking_overview WHERE booking_id=?" . ($clientId ? " AND client_id=?" : "") . " ORDER BY booking_item_id";
        $st = db()->prepare($sql);
        $st->execute($clientId ? [$bookingId, $clientId] : [$bookingId]);
        $items = $st->fetchAll();
        $st = db()->prepare("SELECT b.*, c.name client_name FROM bookings b JOIN clients c ON c.id=b.client_id WHERE b.id=?");
        $st->execute([$bookingId]);
        $booking = $st->fetch() ?: null;
        if ($booking && $clientId && (int)$booking['client_id'] !== $clientId) $booking = null;
    }
    if ($clientId) {
        $st = db()->prepare("SELECT balance FROM v_client_balances WHERE client_id=?");
        $st->execute([$clientId]);
        $balance = (float)($st->fetchColumn() ?: 0);
    }
    echo json_encode(['booking' => $booking, 'items' => $items, 'balance' => $balance]);
    exit;
}

/* ---------------- POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            match ($key) {
                'sales' => save_sale(),
                'bookings' => save_booking(),
                'purchases' => save_purchase(),
                'payments' => save_payment(),
                'returns' => save_return(),
                'pending_bills' => save_pending_bill(),
                'materials' => save_material(),
                'clients' => save_client(),
                'suppliers' => save_supplier(),
                'delivery_persons' => save_delivery(),
                'accounts' => save_account(),
                'users' => save_user(),
                'settings' => save_settings(),
                default => throw new RuntimeException('Unknown module'),
            };
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Missing record.');
            if (in_array($key, ['sales', 'bookings', 'purchases'], true)) {
                db()->prepare("UPDATE \"$key\" SET status='cancelled' WHERE id=?")->execute([$id]);
                audit('cancel', "$key #$id cancelled");
                flash('success', 'Record cancelled.');
            } elseif (in_array($key, ['clients', 'suppliers', 'materials', 'delivery_persons', 'accounts'], true)) {
                db()->prepare("UPDATE \"$key\" SET active=0 WHERE id=?")->execute([$id]);
                audit('deactivate', "$key #$id deactivated");
                flash('success', 'Record deactivated. Accounting history is kept.');
            } elseif ($key === 'pending_bills') {
                db()->prepare('UPDATE pending_bills SET is_paid=1 WHERE id=?')->execute([$id]);
                flash('success', 'Bill marked paid.');
            } elseif (!in_array($key, ['audit_log', 'users', 'settings'], true)) {
                db()->prepare("DELETE FROM \"$key\" WHERE id=?")->execute([$id]);
                flash('success', 'Record deleted.');
            }
            redirect('?module=' . $key);
        }
        if ($action === 'mark_paid') {
            db()->prepare('UPDATE pending_bills SET is_paid=1 WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Bill marked as paid.');
            redirect('?module=pending_bills');
        }
    } catch (Throwable $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
        $back = '?module=' . $key;
        if (!empty($_POST['id'])) $back .= '&edit=' . (int)$_POST['id'];
        else $back .= '&new=1';
        redirect($back);
    }
}

function require_client(int $id): void { if ($id <= 0) throw new RuntimeException('Choose a client from the searchable list.'); }
function require_supplier(int $id): void { if ($id <= 0) throw new RuntimeException('Choose a supplier from the searchable list.'); }

function save_sale(): never {
    $id = (int)($_POST['id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    require_client($clientId);
    $saleType = $_POST['sale_type'] ?? 'cash';
    if (!in_array($saleType, ['cash', 'credit', 'booking', 'booking_credit'], true)) throw new RuntimeException('Invalid sale type.');
    $bookingId = in_array($saleType, ['booking', 'booking_credit'], true) ? (int)($_POST['booking_id'] ?? 0) : 0;
    if (in_array($saleType, ['booking', 'booking_credit'], true) && !$bookingId) throw new RuntimeException('Select the booking being dispatched.');
    $date = post_date('sale_date', ' ' . date('H:i:s'));
    $discount = max(0, num($_POST['discount'] ?? 0));
    $payMethod = post('payment_method') ?: ($saleType === 'credit' ? 'credit' : 'cash');
    if (!in_array($payMethod, ['cash', 'bank', 'credit'], true)) $payMethod = 'cash';
    $accountId = (int)($_POST['payment_account_id'] ?? 0) ?: null;
    $manual = trim((string)($_POST['manual_bill_no'] ?? ''));

    if (!$id) {
        $items = posted_items();
        if (!$items) throw new RuntimeException('Add at least one material, with quantity.');
        $subtotal = 0;
        foreach ($items as $it) $subtotal += $it['qty'] * $it['rate'];
        $total = max(0, $subtotal - $discount);
        $paid = ($saleType === 'cash' && $payMethod === 'cash') ? $total : 0;
        $auto = next_bill('SL', 'SB-SL-');
        if ($manual === '') $manual = $auto;
        db()->beginTransaction();
        db()->prepare("INSERT INTO sales (auto_bill_no,manual_bill_no,client_id,sale_date,sale_type,booking_id,subtotal,subtotal_minor,discount,discount_minor,discount_reason,tax_amount,total_amount,total_amount_minor,total_paid_cache,total_paid_cache_minor,payment_method,payment_account_id,status,revision,created_by,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?, 'active',0,?,?)")
            ->execute([$auto, $manual, $clientId, $date, $saleType, $bookingId ?: null, $subtotal, minor($subtotal), $discount, minor($discount), post('discount_reason') ?: null, $total, minor($total), $paid, minor($paid), $payMethod, $accountId, uid(), post('notes') ?: null]);
        $newId = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO sale_items (sale_id,material_id,qty,rate,rate_minor,amount,amount_minor) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $amt = $it['qty'] * $it['rate'];
            $ins->execute([$newId, $it['material_id'], $it['qty'], $it['rate'], minor($it['rate']), $amt, minor($amt)]);
        }
        $dp = (int)($_POST['delivery_person_id'] ?? 0);
        if ($dp) {
            db()->prepare("INSERT INTO sale_delivery_persons (sale_id,delivery_person_id,bags_delivered,rent_amount,rent_amount_minor) VALUES (?,?,0,0,0)")->execute([$newId, $dp]);
        }
        $cname = party_name('client', $clientId);
        db()->prepare("INSERT INTO pending_bills (client_id,client_name_snapshot,bill_no,bill_kind,source_module,source_table,source_id,source_bill_no,transaction_type,amount,amount_minor,reason,is_paid,is_cash,created_by)
            VALUES (?,?,?,'other','sales','sales',?,?,'sale',?,?,'Direct sale',?,?,?)")
            ->execute([$clientId, $cname, $manual, $newId, $auto, $total, minor($total), $saleType === 'cash' ? 1 : 0, $saleType === 'cash' ? 1 : 0, uid()]);
        audit('create', "Sale $auto for $cname");
        db()->commit();
        flash('success', "Sale $auto saved.");
        redirect('?module=sales&view=' . $newId);
    }

    db()->prepare("UPDATE sales SET client_id=?, sale_date=?, sale_type=?, booking_id=?, manual_bill_no=?, discount=?, discount_minor=?, discount_reason=?, payment_method=?, payment_account_id=?, notes=?, updated_by=? WHERE id=?")
        ->execute([$clientId, $date, $saleType, $bookingId ?: null, $manual !== '' ? $manual : 'MB', $discount, minor($discount), post('discount_reason') ?: null, $payMethod, $accountId, post('notes') ?: null, uid(), $id]);
    $row = db()->prepare('SELECT subtotal FROM sales WHERE id=?');
    $row->execute([$id]);
    $subtotal = (float)$row->fetchColumn();
    $total = max(0, $subtotal - $discount);
    db()->prepare('UPDATE sales SET total_amount=?, total_amount_minor=? WHERE id=?')->execute([$total, minor($total), $id]);
    audit('update', "Sale #$id updated");
    flash('success', 'Sale updated.');
    redirect('?module=sales&view=' . $id);
}

function save_booking(): never {
    $id = (int)($_POST['id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    require_client($clientId);
    $date = post_date('booking_date', ' ' . date('H:i:s'));
    $discount = max(0, num($_POST['discount'] ?? 0));
    $paid = max(0, num($_POST['paid_amount'] ?? 0));
    $accountId = (int)($_POST['receive_in_account_id'] ?? 0) ?: null;
    $manual = trim((string)($_POST['manual_bill_no'] ?? ''));

    if (!$id) {
        $items = posted_items();
        if (!$items) throw new RuntimeException('Add at least one booked material.');
        $subtotal = 0;
        foreach ($items as $it) $subtotal += $it['qty'] * $it['rate'];
        $total = max(0, $subtotal - $discount);
        $auto = next_bill('BK', 'SB-BK-');
        if ($manual === '') $manual = $auto;
        db()->beginTransaction();
        db()->prepare("INSERT INTO bookings (auto_bill_no,manual_bill_no,client_id,booking_date,total_amount,total_amount_minor,paid_amount,paid_amount_minor,discount,discount_reason,receive_in_account_id,status,created_by,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?,?)")
            ->execute([$auto, $manual, $clientId, $date, $total, minor($total), $paid, minor($paid), $discount, post('discount_reason') ?: null, $accountId, uid(), post('notes') ?: null]);
        $newId = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO booking_items (booking_id,material_id,qty_booked,rate,rate_minor,amount,amount_minor) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $amt = $it['qty'] * $it['rate'];
            $ins->execute([$newId, $it['material_id'], $it['qty'], $it['rate'], minor($it['rate']), $amt, minor($amt)]);
        }
        $cname = party_name('client', $clientId);
        db()->prepare("INSERT INTO pending_bills (client_id,client_name_snapshot,bill_no,bill_kind,source_module,source_table,source_id,source_bill_no,transaction_type,amount,amount_minor,reason,is_paid,is_cash,created_by)
            VALUES (?,?,?,'booking','booking','bookings',?,?,'booking',?,?, 'Booking',0,0,?)")
            ->execute([$clientId, $cname, $manual, $newId, $auto, $total, minor($total), uid()]);
        audit('create', "Booking $auto for $cname");
        db()->commit();
        flash('success', "Booking $auto saved.");
        redirect('?module=bookings&view=' . $newId);
    }

    db()->prepare("UPDATE bookings SET client_id=?, booking_date=?, manual_bill_no=?, discount=?, discount_reason=?, receive_in_account_id=?, notes=?, updated_by=? WHERE id=?")
        ->execute([$clientId, $date, $manual !== '' ? $manual : 'MB', $discount, post('discount_reason') ?: null, $accountId, post('notes') ?: null, uid(), $id]);
    flash('success', 'Booking updated.');
    redirect('?module=bookings&view=' . $id);
}

function save_purchase(): never {
    $id = (int)($_POST['id'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    require_supplier($supplierId);
    $date = post_date('purchase_date', ' 00:00:00');
    $billDate = post('bill_date') ?: $date;
    if (strlen((string)$billDate) === 10) $billDate .= ' 00:00:00';
    $due = post('due_date') ?: null;
    $loading = max(0, num($_POST['loading_cost'] ?? 0));
    $freight = max(0, num($_POST['freight_cost'] ?? 0));
    $other = max(0, num($_POST['other_expense'] ?? 0));
    $discount = max(0, num($_POST['discount'] ?? 0));
    $payType = post('payment_type') ?: 'credit';
    if (!in_array($payType, ['cash', 'bank', 'credit'], true)) $payType = 'credit';
    $accountId = (int)($_POST['payment_account_id'] ?? 0) ?: null;
    $manual = trim((string)($_POST['manual_bill_no'] ?? ''));

    if (!$id) {
        $items = posted_items();
        if (!$items) throw new RuntimeException('Add at least one purchased material.');
        $subtotal = 0;
        foreach ($items as $it) $subtotal += $it['qty'] * $it['rate'];
        $total = max(0, $subtotal + $loading + $freight + $other - $discount);
        $auto = next_bill('GRN', 'SB-GRN-');
        if ($manual === '') $manual = $auto;
        db()->beginTransaction();
        db()->prepare("INSERT INTO purchases (auto_bill_no,manual_bill_no,supplier_id,supplier_invoice_no,bill_date,purchase_date,due_date,subtotal,subtotal_minor,loading_cost,freight_cost,other_expense,discount,total_amount,total_amount_minor,paid_amount,paid_amount_minor,payment_type,payment_account_id,status,revision,created_by,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,?, 'active',0,?,?)")
            ->execute([$auto, $manual, $supplierId, post('supplier_invoice_no') ?: null, $billDate, $date, $due, $subtotal, minor($subtotal), $loading, $freight, $other, $discount, $total, minor($total), $payType, $accountId, uid(), post('notes') ?: null]);
        $newId = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO purchase_items (purchase_id,material_id,qty,rate,rate_minor,amount,amount_minor) VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $amt = $it['qty'] * $it['rate'];
            $ins->execute([$newId, $it['material_id'], $it['qty'], $it['rate'], minor($it['rate']), $amt, minor($amt)]);
            db()->prepare("INSERT INTO stock_batches (material_id,source_type,source_id,batch_date,qty_in,remaining_qty,cost_rate,cost_rate_minor,created_by)
                VALUES (?,'purchase',?,?,?,?,?,?,?)")
                ->execute([$it['material_id'], $newId, $date, $it['qty'], $it['qty'], $it['rate'], minor($it['rate']), uid()]);
        }
        audit('create', "Purchase $auto");
        db()->commit();
        flash('success', "Purchase $auto saved and stock updated.");
        redirect('?module=purchases&view=' . $newId);
    }

    db()->prepare("UPDATE purchases SET supplier_id=?, supplier_invoice_no=?, bill_date=?, purchase_date=?, due_date=?, loading_cost=?, freight_cost=?, other_expense=?, discount=?, payment_type=?, payment_account_id=?, notes=?, updated_by=? WHERE id=?")
        ->execute([$supplierId, post('supplier_invoice_no') ?: null, $billDate, $date, $due, $loading, $freight, $other, $discount, $payType, $accountId, post('notes') ?: null, uid(), $id]);
    flash('success', 'Purchase updated.');
    redirect('?module=purchases&view=' . $id);
}

function save_payment(): never {
    $id = (int)($_POST['id'] ?? 0);
    $amount = num($_POST['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Amount must be greater than zero.');
    $direction = $_POST['direction'] === 'out' ? 'out' : 'in';
    $partyType = $_POST['party_type'] ?? 'client';
    if (!in_array($partyType, ['client', 'supplier', 'delivery_person', 'lender', 'owner', 'other'], true)) $partyType = 'client';
    $partyId = (int)($_POST['party_id'] ?? 0) ?: null;
    if (in_array($partyType, ['client', 'supplier', 'delivery_person', 'lender'], true) && !$partyId) {
        throw new RuntimeException('Choose the party from the searchable list.');
    }
    $mode = $_POST['payment_mode'] ?? 'cash';
    if (!in_array($mode, ['cash', 'bank', 'adjustment'], true)) $mode = 'cash';
    $accountId = (int)($_POST['payment_account_id'] ?? 0) ?: null;
    if ($mode === 'bank' && !$accountId) throw new RuntimeException('Bank payments need a receiving / paying account.');
    $refType = $_POST['reference_type'] ?? '';
    $allowedRef = ['sale','booking','refund','client_opening','supplier_purchase','supplier_opening','delivery_wage','delivery_rent','expense','loan','loan_repayment','owner_draw','owner_contribution','other'];
    if (!in_array($refType, $allowedRef, true)) {
        $refType = match ($partyType) {
            'supplier' => 'supplier_purchase',
            'delivery_person' => 'delivery_wage',
            'lender' => 'loan',
            'owner' => $direction === 'out' ? 'owner_draw' : 'owner_contribution',
            default => $direction === 'in' ? 'sale' : 'other',
        };
    }
    $date = post_date('payment_date', ' ' . date('H:i:s'));
    $discount = max(0, num($_POST['discount'] ?? 0));
    $manual = trim((string)($_POST['manual_bill_no'] ?? ''));
    $snapshot = party_name($partyType, (int)$partyId) ?: ($_POST['party_name_snapshot'] ?? null);
    $accName = null;
    if ($accountId) {
        $st = db()->prepare('SELECT name FROM accounts WHERE id=?');
        $st->execute([$accountId]);
        $accName = $st->fetchColumn() ?: null;
    }

    if (!$id) {
        $auto = next_bill('CP', 'SPAY-', 6);
        if ($manual === '') $manual = $auto;
        db()->prepare("INSERT INTO payments (auto_bill_no,manual_bill_no,payment_date,direction,party_type,party_id,party_name_snapshot,amount,amount_minor,discount,discount_minor,discount_reason,payment_mode,payment_account_id,account_name,reference,reference_type,created_by,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$auto, $manual, $date, $direction, $partyType, $partyId, $snapshot, $amount, minor($amount), $discount, minor($discount), post('discount_reason') ?: null, $mode, $accountId, $accName, post('reference') ?: null, $refType, uid(), post('notes') ?: null]);
        audit('create', "Payment $auto $direction $amount");
        flash('success', "Payment $auto recorded.");
        redirect('?module=payments');
    }

    db()->prepare("UPDATE payments SET payment_date=?, direction=?, party_type=?, party_id=?, party_name_snapshot=?, amount=?, amount_minor=?, discount=?, discount_minor=?, payment_mode=?, payment_account_id=?, account_name=?, reference=?, reference_type=?, notes=?, updated_by=? WHERE id=?")
        ->execute([$date, $direction, $partyType, $partyId, $snapshot, $amount, minor($amount), $discount, minor($discount), $mode, $accountId, $accName, post('reference') ?: null, $refType, post('notes') ?: null, uid(), $id]);
    flash('success', 'Payment updated.');
    redirect('?module=payments');
}

function save_return(): never {
    $id = (int)($_POST['id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    require_client($clientId);
    $materialId = (int)($_POST['material_id'] ?? 0);
    if (!$materialId) throw new RuntimeException('Choose the returned material.');
    $qty = num($_POST['qty'] ?? 0);
    $rate = num($_POST['rate'] ?? 0);
    if ($qty <= 0) throw new RuntimeException('Quantity must be greater than zero.');
    $type = $_POST['return_type'] ?? 'cash_sale_return';
    if (!in_array($type, ['cash_sale_return', 'credit_sale_return', 'booking_return'], true)) $type = 'cash_sale_return';
    $bookingItem = (int)($_POST['booking_item_id'] ?? 0) ?: null;
    if ($type === 'booking_return' && !$bookingItem) throw new RuntimeException('Booking returns need a booking item.');
    $refundReq = $type === 'cash_sale_return' && !empty($_POST['refund_required']) ? 1 : 0;
    $refundStatus = $refundReq ? (($_POST['refund_status'] ?? 'pending') === 'paid' ? 'paid' : 'pending') : 'not_applicable';
    $date = post_date('return_date', ' ' . date('H:i:s'));
    $amt = $qty * $rate;
    $manual = trim((string)($_POST['manual_bill_no'] ?? ''));

    if (!$id) {
        $auto = next_bill('RTN', 'RET-');
        if ($manual === '') $manual = $auto;
        db()->prepare("INSERT INTO returns (auto_bill_no,manual_bill_no,return_type,client_id,material_id,qty,rate,rate_minor,amount,amount_minor,refund_required,refund_status,return_date,booking_item_id,created_by,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$auto, $manual, $type, $clientId, $materialId, $qty, $rate, minor($rate), $amt, minor($amt), $refundReq, $refundStatus, $date, $bookingItem, uid(), post('notes') ?: null]);
        audit('create', "Return $auto");
        flash('success', "Return $auto saved.");
        redirect('?module=returns');
    }
    db()->prepare("UPDATE returns SET return_type=?, client_id=?, material_id=?, qty=?, rate=?, rate_minor=?, amount=?, amount_minor=?, refund_required=?, refund_status=?, return_date=?, notes=? WHERE id=?")
        ->execute([$type, $clientId, $materialId, $qty, $rate, minor($rate), $amt, minor($amt), $refundReq, $refundStatus, $date, post('notes') ?: null, $id]);
    flash('success', 'Return updated.');
    redirect('?module=returns');
}

function save_pending_bill(): never {
    $id = (int)($_POST['id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $amount = max(0, num($_POST['amount'] ?? 0));
    $kind = $_POST['bill_kind'] ?? 'other';
    if (!in_array($kind, ['sale', 'booking', 'grn', 'refund', 'other'], true)) $kind = 'other';
    $name = $clientId ? party_name('client', $clientId) : ($_POST['client_name_snapshot'] ?? null);
    $paid = !empty($_POST['is_paid']) ? 1 : 0;
    $cash = !empty($_POST['is_cash']) ? 1 : 0;
    if (!$id) {
        db()->prepare("INSERT INTO pending_bills (client_id,client_name_snapshot,bill_no,bill_kind,transaction_type,amount,amount_minor,reason,is_paid,is_cash,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$clientId, $name, post('bill_no') ?: null, $kind, post('transaction_type') ?: null, $amount, minor($amount), post('reason') ?: null, $paid, $cash, post('notes') ?: null, uid()]);
        flash('success', 'Pending bill created.');
    } else {
        db()->prepare("UPDATE pending_bills SET client_id=?, client_name_snapshot=?, bill_no=?, bill_kind=?, transaction_type=?, amount=?, amount_minor=?, reason=?, is_paid=?, is_cash=?, notes=? WHERE id=?")
            ->execute([$clientId, $name, post('bill_no') ?: null, $kind, post('transaction_type') ?: null, $amount, minor($amount), post('reason') ?: null, $paid, $cash, post('notes') ?: null, $id]);
        flash('success', 'Pending bill updated.');
    }
    redirect('?module=pending_bills');
}

function save_material(): never {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Material name is required.');
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') $code = next_code('materials', 'FBM-');
    $rate = max(0, num($_POST['current_rate'] ?? 0));
    $reorder = max(0, num($_POST['reorder_level'] ?? 0));
    $unit = trim((string)($_POST['unit'] ?? 'Bags')) ?: 'Bags';
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    if (!$id) {
        db()->prepare("INSERT INTO materials (code,name,unit,category_id,current_rate,current_rate_minor,reorder_level,active,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$code, $name, $unit, $cat, $rate, minor($rate), $reorder, $active, uid()]);
        flash('success', 'Material created.');
    } else {
        db()->prepare("UPDATE materials SET code=?, name=?, unit=?, category_id=?, current_rate=?, current_rate_minor=?, reorder_level=?, active=? WHERE id=?")
            ->execute([$code, $name, $unit, $cat, $rate, minor($rate), $reorder, $active, $id]);
        flash('success', 'Material updated.');
    }
    redirect('?module=materials');
}

function save_client(): never {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Client name is required.');
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') $code = next_code('clients', 'FBMCL-');
    $ob = num($_POST['opening_balance'] ?? 0);
    $type = $_POST['default_type'] ?? 'cash';
    if (!in_array($type, ['cash', 'credit', 'booking'], true)) $type = 'cash';
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    $fields = [
        $code, $name, post('phone') ?: null, post('cnic') ?: null, post('address') ?: null, post('location_url') ?: null,
        $cat, $type, $ob, minor($ob), post('opening_balance_date') ?: null, post('book_no') ?: null,
        post('financial_book_no') ?: null, post('financial_page') ?: null, post('cement_book_no') ?: null, post('cement_page') ?: null,
        post('steel_book_no') ?: null, post('steel_page') ?: null, post('page_notes') ?: null,
        !empty($_POST['require_manual_invoice']) ? 1 : 0, $active,
    ];
    if (!$id) {
        db()->prepare("INSERT INTO clients (code,name,phone,cnic,address,location_url,category_id,default_type,opening_balance,opening_balance_minor,opening_balance_date,book_no,financial_book_no,financial_page,cement_book_no,cement_page,steel_book_no,steel_page,page_notes,require_manual_invoice,active,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([...$fields, uid()]);
        flash('success', 'Client created.');
    } else {
        db()->prepare("UPDATE clients SET code=?, name=?, phone=?, cnic=?, address=?, location_url=?, category_id=?, default_type=?, opening_balance=?, opening_balance_minor=?, opening_balance_date=?, book_no=?, financial_book_no=?, financial_page=?, cement_book_no=?, cement_page=?, steel_book_no=?, steel_page=?, page_notes=?, require_manual_invoice=?, active=? WHERE id=?")
            ->execute([...$fields, $id]);
        flash('success', 'Client updated.');
    }
    redirect('?module=clients');
}

function save_supplier(): never {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Supplier name is required.');
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') $code = next_code('suppliers', 'SUP-');
    $ob = num($_POST['opening_balance'] ?? 0);
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    if (!$id) {
        db()->prepare("INSERT INTO suppliers (code,name,phone,address,category_id,opening_balance,opening_balance_minor,opening_balance_date,active,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$code, $name, post('phone') ?: null, post('address') ?: null, $cat, $ob, minor($ob), post('opening_balance_date') ?: null, $active, uid()]);
        flash('success', 'Supplier created.');
    } else {
        db()->prepare("UPDATE suppliers SET code=?, name=?, phone=?, address=?, category_id=?, opening_balance=?, opening_balance_minor=?, opening_balance_date=?, active=? WHERE id=?")
            ->execute([$code, $name, post('phone') ?: null, post('address') ?: null, $cat, $ob, minor($ob), post('opening_balance_date') ?: null, $active, $id]);
        flash('success', 'Supplier updated.');
    }
    redirect('?module=suppliers');
}

function save_delivery(): never {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Name is required.');
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') $code = next_code('delivery_persons', 'DP-');
    $rate = max(0, num($_POST['rate_per_trip'] ?? 0));
    $ob = num($_POST['opening_balance'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    $user = (int)($_POST['linked_user_id'] ?? 0) ?: null;
    if (!$id) {
        db()->prepare("INSERT INTO delivery_persons (code,name,phone,linked_user_id,rate_per_trip,opening_balance,opening_balance_minor,opening_balance_date,active)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$code, $name, post('phone') ?: null, $user, $rate, $ob, minor($ob), post('opening_balance_date') ?: null, $active]);
        flash('success', 'Delivery person created.');
    } else {
        db()->prepare("UPDATE delivery_persons SET code=?, name=?, phone=?, linked_user_id=?, rate_per_trip=?, opening_balance=?, opening_balance_minor=?, opening_balance_date=?, active=? WHERE id=?")
            ->execute([$code, $name, post('phone') ?: null, $user, $rate, $ob, minor($ob), post('opening_balance_date') ?: null, $active, $id]);
        flash('success', 'Delivery person updated.');
    }
    redirect('?module=delivery_persons');
}

function save_account(): never {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Account name is required.');
    $type = $_POST['account_type'] ?? 'other';
    if (!in_array($type, ['cash_drawer', 'bank', 'expense', 'revenue', 'loan', 'owner', 'other'], true)) $type = 'other';
    $class = post('account_class') ?: null;
    if ($class && !in_array($class, ['asset', 'liability', 'equity', 'income', 'expense'], true)) $class = null;
    $ob = num($_POST['opening_balance'] ?? 0);
    $bank = post('bank_name') ?: null;
    if ($type === 'bank' && !$bank) throw new RuntimeException('Bank accounts need a bank name.');
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    if (!$id) {
        db()->prepare("INSERT INTO accounts (name,account_type,account_class,category_id,bank_name,account_holder_name,account_number,branch_code,opening_balance,opening_balance_minor,opening_balance_date,balance,balance_minor,note,active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name, $type, $class, $cat, $bank, post('account_holder_name') ?: null, post('account_number') ?: null, post('branch_code') ?: null, $ob, minor($ob), post('opening_balance_date') ?: null, $ob, minor($ob), post('note') ?: null, $active]);
        flash('success', 'Account created.');
    } else {
        db()->prepare("UPDATE accounts SET name=?, account_type=?, account_class=?, category_id=?, bank_name=?, account_holder_name=?, account_number=?, branch_code=?, opening_balance=?, opening_balance_minor=?, opening_balance_date=?, note=?, active=? WHERE id=?")
            ->execute([$name, $type, $class, $cat, $bank, post('account_holder_name') ?: null, post('account_number') ?: null, post('branch_code') ?: null, $ob, minor($ob), post('opening_balance_date') ?: null, post('note') ?: null, $active, $id]);
        flash('success', 'Account updated.');
    }
    redirect('?module=accounts');
}

function save_user(): never {
    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $full = trim((string)($_POST['full_name'] ?? ''));
    if ($username === '' || $full === '') throw new RuntimeException('Username and full name are required.');
    $role = (int)($_POST['role_id'] ?? 0);
    if (!$role) throw new RuntimeException('Choose a role.');
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'suspended', 'disabled'], true)) $status = 'active';
    $active = isset($_POST['active']) ? 1 : 0;
    if (!$id) $active = 1;
    $restrict = !empty($_POST['restrict_backdated_edit']) ? 1 : 0;
    $pass = (string)($_POST['new_password'] ?? '');
    if (!$id) {
        if ($pass === '') throw new RuntimeException('Password is required for a new user.');
        db()->prepare("INSERT INTO users (username,password_hash,full_name,role_id,phone,email,status,restrict_backdated_edit,active)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $full, $role, post('phone') ?: null, post('email') ?: null, $status, $restrict, $active]);
        flash('success', 'User created.');
    } else {
        db()->prepare("UPDATE users SET username=?, full_name=?, role_id=?, phone=?, email=?, status=?, restrict_backdated_edit=?, active=? WHERE id=?")
            ->execute([$username, $full, $role, post('phone') ?: null, post('email') ?: null, $status, $restrict, $active, $id]);
        if ($pass !== '') db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        flash('success', 'User updated.');
    }
    redirect('?module=users');
}

function save_settings(): never {
    db()->prepare("UPDATE settings SET company_name=?, company_address=?, company_phone=?, company_email=?, currency=?, currency_symbol=?, tax_rate=?, invoice_prefix=?, bill_prefix=?, backdate_grace_days=?, allow_global_negative_stock=?, low_stock_alert_enabled=?, updated_by=? WHERE id=1")
        ->execute([
            post('company_name') ?: null, post('company_address') ?: null, post('company_phone') ?: null, post('company_email') ?: null,
            post('currency') ?: 'PKR', post('currency_symbol') ?: 'Rs', num($_POST['tax_rate'] ?? 0),
            post('invoice_prefix') ?: 'INV', post('bill_prefix') ?: 'B', (int)($_POST['backdate_grace_days'] ?? 0),
            !empty($_POST['allow_global_negative_stock']) ? 1 : 0, !empty($_POST['low_stock_alert_enabled']) ? 1 : 0, uid(),
        ]);
    flash('success', 'Settings saved.');
    redirect('?module=settings');
}

/* ---------------- Queries ---------------- */
function scalar(string $sql, array $p = []): float {
    $st = db()->prepare($sql);
    $st->execute($p);
    return (float)($st->fetchColumn() ?: 0);
}
function like_params(string $q, int $n): array { return array_fill(0, $n, '%' . $q . '%'); }

function list_sales(string $q): array {
    $sql = "SELECT s.id,s.auto_bill_no,s.manual_bill_no,s.sale_date,s.sale_type,s.total_amount,s.total_paid_cache,s.status,c.name client_name,
        (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id=s.id) item_count
        FROM sales s LEFT JOIN clients c ON c.id=s.client_id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE s.auto_bill_no LIKE ? OR s.manual_bill_no LIKE ? OR c.name LIKE ? OR s.sale_type LIKE ?"; $p = like_params($q, 4); }
    $sql .= " ORDER BY s.id DESC LIMIT 120";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_bookings(string $q): array {
    $sql = "SELECT b.id,b.auto_bill_no,b.manual_bill_no,b.booking_date,b.total_amount,b.paid_amount,b.status,c.name client_name
        FROM bookings b LEFT JOIN clients c ON c.id=b.client_id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE b.auto_bill_no LIKE ? OR b.manual_bill_no LIKE ? OR c.name LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY b.id DESC LIMIT 120";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_purchases(string $q): array {
    $sql = "SELECT p.id,p.auto_bill_no,p.manual_bill_no,p.purchase_date,p.total_amount,p.subtotal,p.paid_amount,p.status,s.name supplier_name
        FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE p.auto_bill_no LIKE ? OR p.manual_bill_no LIKE ? OR s.name LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY p.id DESC LIMIT 120";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_payments(string $q): array {
    $sql = "SELECT * FROM payments";
    $p = [];
    if ($q !== '') { $sql .= " WHERE auto_bill_no LIKE ? OR manual_bill_no LIKE ? OR party_name_snapshot LIKE ? OR party_type LIKE ? OR reference LIKE ?"; $p = like_params($q, 5); }
    $sql .= " ORDER BY id DESC LIMIT 120";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_returns(string $q): array {
    $sql = "SELECT r.*, c.name client_name, m.name material_name FROM returns r
        LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN materials m ON m.id=r.material_id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE r.auto_bill_no LIKE ? OR r.manual_bill_no LIKE ? OR c.name LIKE ? OR m.name LIKE ?"; $p = like_params($q, 4); }
    $sql .= " ORDER BY r.id DESC LIMIT 120";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_pending(string $q): array {
    $sql = "SELECT p.*, c.name client_name FROM pending_bills p LEFT JOIN clients c ON c.id=p.client_id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE p.bill_no LIKE ? OR p.client_name_snapshot LIKE ? OR c.name LIKE ? OR p.reason LIKE ?"; $p = like_params($q, 4); }
    $sql .= " ORDER BY p.is_paid ASC, p.id DESC LIMIT 150";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_materials(string $q): array {
    $sql = "SELECT m.*, cat.name category_name, COALESCE(st.current_qty,0) stock, COALESCE(st.current_stock_value,0) stock_value
        FROM materials m LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN v_material_stock st ON st.material_id=m.id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE m.name LIKE ? OR m.code LIKE ? OR cat.name LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY m.active DESC, m.name LIMIT 200";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_clients(string $q): array {
    $sql = "SELECT c.*, cat.name category_name, COALESCE(b.balance,c.opening_balance,0) balance
        FROM clients c LEFT JOIN categories cat ON cat.id=c.category_id LEFT JOIN v_client_balances b ON b.client_id=c.id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE c.name LIKE ? OR c.code LIKE ? OR c.phone LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY c.active DESC, c.name LIMIT 200";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_suppliers(string $q): array {
    $sql = "SELECT s.*, COALESCE(b.balance,s.opening_balance,0) balance FROM suppliers s LEFT JOIN v_supplier_balances b ON b.supplier_id=s.id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE s.name LIKE ? OR s.code LIKE ? OR s.phone LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY s.name";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}
function list_delivery(string $q): array {
    $sql = "SELECT d.*, COALESCE(b.balance,d.opening_balance,0) balance FROM delivery_persons d LEFT JOIN v_delivery_person_balances b ON b.delivery_person_id=d.id";
    $p = [];
    if ($q !== '') { $sql .= " WHERE d.name LIKE ? OR d.code LIKE ? OR d.phone LIKE ?"; $p = like_params($q, 3); }
    $sql .= " ORDER BY d.name";
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll();
}

/* ---------------- Render helpers ---------------- */
function combo(string $name, string $source, string $label, $value, bool $req = false, string $ph = 'Type to search…'): string {
    return '<label class="field">' . h($label) . ($req ? ' *' : '') .
        '<div class="combo" data-source="' . h($source) . '">' .
        '<input type="hidden" name="' . h($name) . '" value="' . h((string)$value) . '" class="combo-value"' . ($req ? ' required' : '') . '>' .
        '<input type="text" class="combo-input" placeholder="' . h($ph) . '" autocomplete="off" spellcheck="false">' .
        '<button type="button" class="combo-clear" tabindex="-1" aria-label="Clear">×</button>' .
        '<div class="combo-list" role="listbox"></div></div></label>';
}
function inp(string $name, string $label, $value, string $type = 'text', bool $req = false, string $extra = ''): string {
    $step = $type === 'number' ? ' step="0.01"' : '';
    return '<label class="field">' . h($label) . ($req ? ' *' : '') .
        '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h((string)($value ?? '')) . '"' . $step . ($req ? ' required' : '') . " $extra></label>";
}
function sel(string $name, string $label, $value, array $opts, bool $req = false): string {
    $html = '<label class="field">' . h($label) . ($req ? ' *' : '') . '<select name="' . h($name) . '">';
    foreach ($opts as $k => $lab) {
        $html .= '<option value="' . h((string)$k) . '"' . ((string)$value === (string)$k ? ' selected' : '') . '>' . h($lab) . '</option>';
    }
    return $html . '</select></label>';
}
function area(string $name, string $label, $value, string $cls = 'span-3'): string {
    return '<label class="field ' . h($cls) . '">' . h($label) . '<textarea name="' . h($name) . '">' . h((string)($value ?? '')) . '</textarea></label>';
}
function chk(string $name, string $label, $value): string {
    return '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($value ? ' checked' : '') . '> <span>' . h($label) . '</span></label>';
}
function cat_opts(string $type, $value): string {
    $html = '<label class="field">Category<select name="category_id"><option value="">—</option>';
    foreach (cats($type) as $c) $html .= '<option value="' . $c['id'] . '"' . ((string)$value === (string)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    return $html . '</select></label>';
}
function csrf_fields(string $id = ''): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . h($id) . '">';
}
function form_start(string $title, string $sub, string $mod, $edit): void {
    echo '<section class="panel form-panel"><div class="panel-head"><div><div class="eyebrow">' . ($edit ? 'EDIT RECORD' : 'NEW RECORD') . '</div><h3>' . h($title) . '</h3><p class="helper">' . h($sub) . '</p></div><a href="?module=' . h($mod) . '" class="close">×</a></div><form method="post" class="form-body" id="txn-form">' . csrf_fields((string)$edit);
}
function form_end(string $mod, string $save = 'Save'): void {
    echo '<div class="form-actions"><a class="button" href="?module=' . h($mod) . '">Cancel</a><button class="button primary">' . h($save) . '</button></div></form></section>';
}
function section(string $title): void { echo '<div class="form-section"><h4>' . h($title) . '</h4><div class="form-grid">'; }
function section_end(): void { echo '</div></div>'; }
function lines_editor(bool $showExtras = false): void {
    echo '<div class="form-section"><h4>Materials</h4><p class="hint">Search a material by name or code. Rate fills from the last sale when available.</p>';
    echo '<div class="lines-wrap"><table class="lines"><thead><tr><th>Material</th><th style="width:110px">Qty</th><th style="width:130px">Rate</th><th style="width:130px" class="right">Amount</th><th style="width:40px"></th></tr></thead><tbody id="line-body"></tbody></table></div>';
    echo '<div style="margin-top:10px"><button type="button" class="button" id="add-line">＋ Add material</button></div>';
    echo '<div class="totals"><div><span>Subtotal</span><strong id="sum-subtotal">Rs 0.00</strong></div>';
    if ($showExtras) {
        echo '<div><span>Loading</span><input type="number" step="0.01" min="0" name="loading_cost" value="' . h($_POST['loading_cost'] ?? '0') . '"></div>';
        echo '<div><span>Freight</span><input type="number" step="0.01" min="0" name="freight_cost" value="0"></div>';
        echo '<div><span>Other</span><input type="number" step="0.01" min="0" name="other_expense" value="0"></div>';
    }
    echo '<div><span>Discount</span><input type="number" step="0.01" min="0" name="discount" value="' . h((string)($GLOBALS['record']['discount'] ?? 0)) . '"></div>';
    echo '<div class="grand"><span>Total</span><strong id="sum-total">Rs 0.00</strong></div></div></div>';
}

function existing_items_table(array $rows, array $cols): void {
    if (!$rows) return;
    echo '<div class="form-section"><h4>Existing line items</h4><div class="table-wrap"><table><thead><tr>';
    foreach ($cols as $c) echo '<th>' . h($c) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (array_keys($cols) as $k) {
            $v = $r[$k] ?? '';
            $moneyCols = ['rate', 'amount'];
        echo '<td>' . (in_array($k, $moneyCols, true) ? money($v) : h((string)$v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><p class="hint">Line items stay as posted. Create a new bill to add a fresh set of materials.</p></div>';
}

function toolbar(string $mod, string $q, string $addLabel): void {
    echo '<div class="toolbar"><form><input type="hidden" name="module" value="' . h($mod) . '"><input class="search" name="q" value="' . h($q) . '" placeholder="Search name, code or bill…"><button class="button">Search</button></form>';
    if ($addLabel !== '') echo '<a class="button primary" href="?module=' . h($mod) . '&new=1">＋ ' . h($addLabel) . '</a>';
    echo '</div>';
}
function actions(string $mod, int $id, bool $view = true, bool $edit = true, bool $del = true, string $delLabel = 'Cancel'): void {
    echo '<td class="actions">';
    if ($view) echo '<a href="?module=' . h($mod) . '&view=' . $id . '">View</a>';
    if ($edit) echo '<a href="?module=' . h($mod) . '&edit=' . $id . '">Edit</a>';
    if ($del) {
        echo '<form method="post" onsubmit="return confirm(\'Are you sure?\')">' . csrf_fields((string)$id);
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '"><button>' . h($delLabel) . '</button></form>';
    }
    echo '</td>';
}

function item_rows(string $table, string $fk, int $id): array {
    $sql = match ($table) {
        'sale_items' => "SELECT si.*, m.name material_name, m.unit FROM sale_items si JOIN materials m ON m.id=si.material_id WHERE si.sale_id=? ORDER BY si.id",
        'booking_items' => "SELECT bi.*, m.name material_name, m.unit, (bi.qty_booked-bi.qty_dispatched-bi.qty_cancelled) qty_pending FROM booking_items bi JOIN materials m ON m.id=bi.material_id WHERE bi.booking_id=? ORDER BY bi.id",
        'purchase_items' => "SELECT pi.*, m.name material_name, m.unit FROM purchase_items pi JOIN materials m ON m.id=pi.material_id WHERE pi.purchase_id=? ORDER BY pi.id",
        default => "SELECT * FROM \"$table\" WHERE \"$fk\"=?",
    };
    $st = db()->prepare($sql);
    $st->execute([$id]);
    return $st->fetchAll();
}

/* ============================== HTML ============================== */
$company = setting('company_name') ?: 'Ahmed Cement';
$look = lookups();
$edit = (int)($_GET['edit'] ?? 0);
$view = (int)($_GET['view'] ?? 0);
$isNew = isset($_GET['new']);
$search = trim((string)($_GET['q'] ?? ''));
$record = [];
if ($edit || $view) {
    $table = match ($key) {
        'delivery_persons' => 'delivery_persons',
        'pending_bills' => 'pending_bills',
        'audit_log' => 'audit_log',
        'settings' => 'settings',
        default => $key,
    };
    if ($key !== 'dashboard' && $key !== 'reports' && $key !== 'settings') {
        $st = db()->prepare("SELECT * FROM \"$table\" WHERE id=?");
        $st->execute([$edit ?: $view]);
        $record = $st->fetch() ?: [];
    }
}
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', (string)($_SESSION['user']['full_name'] ?? 'Admin'))[0];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($company) ?> · <?= h($modules[$key]['label']) ?></title>
<link rel="stylesheet" href="style.css">
<script>window.AMS = <?= json_encode($look, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="app.js" defer></script>
</head>
<body>
<aside class="sidebar">
  <div class="brand"><div class="brandmark">A</div><div><b>AMSCOPY9</b><small>CEMENT ERP</small></div></div>
  <nav>
    <?php $last = ''; foreach ($modules as $k => $m):
        if ($m['group'] && $m['group'] !== $last) { echo '<div class="nav-label">' . h($m['group']) . '</div>'; $last = $m['group']; }
    ?>
      <a class="<?= $key === $k ? 'active' : '' ?>" href="?module=<?= h($k) ?>"><span class="nav-icon"><?= $m['icon'] ?></span><?= h($m['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="avatar"><?= h(strtoupper(substr((string)($_SESSION['user']['full_name'] ?? 'A'), 0, 1))) ?></div>
    <div><b><?= h($_SESSION['user']['full_name'] ?? 'Administrator') ?></b><small><?= h($_SESSION['user']['role_name'] ?? 'Admin') ?></small></div>
    <a href="?logout=1" class="logout" title="Sign out">↪</a>
  </div>
</aside>
<main>
<header>
  <button class="menu" onclick="document.body.classList.toggle('open')">☰</button>
  <div>
    <div class="eyebrow"><?= $modules[$key]['group'] ? h($modules[$key]['group']) . ' / ' : '' ?><?= strtoupper(h($modules[$key]['label'])) ?></div>
    <h1><?= h($modules[$key]['label']) ?></h1>
  </div>
  <div class="header-actions"><span class="status-dot"></span> System online
    <a class="user-pill" href="?module=users"><?= h($_SESSION['user']['full_name'] ?? 'Admin') ?> <span>⌄</span></a>
  </div>
</header>
<?php if (!empty($_SESSION['flash'])): [$ft, $fm] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="flash <?= h($ft) ?>"><?= h($fm) ?></div>
<?php endif; ?>

<?php if ($key === 'dashboard'):
    $cards = [
        ['label' => 'Sales this month', 'value' => money(scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE strftime('%Y-%m',sale_date)=strftime('%Y-%m','now') AND status!='cancelled'")), 'tone' => 'blue', 'meta' => 'Revenue posted'],
        ['label' => 'Open receivables', 'value' => money(scalar("SELECT COALESCE(SUM(amount),0) FROM pending_bills WHERE is_paid=0")), 'tone' => 'amber', 'meta' => 'Unpaid pending bills'],
        ['label' => 'Stock on hand', 'value' => number_format(scalar("SELECT COUNT(*) FROM v_material_stock WHERE current_qty>0")), 'tone' => 'teal', 'meta' => 'Materials with quantity'],
        ['label' => 'Open bookings', 'value' => number_format(scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('active','partially_cancelled')")), 'tone' => 'rose', 'meta' => 'Still to dispatch'],
    ];
    $recent = db()->query("SELECT s.id,s.auto_bill_no,s.sale_date,s.total_amount,s.status,s.sale_type,c.name client_name FROM sales s LEFT JOIN clients c ON c.id=s.client_id ORDER BY s.id DESC LIMIT 8")->fetchAll();
    $low = db()->query("SELECT * FROM v_low_stock_alerts WHERE reorder_level > 0 LIMIT 6")->fetchAll();
?>
<section class="welcome">
  <div>
    <div class="eyebrow"><?= strtoupper(date('l, F j, Y')) ?></div>
    <h2><?= h($greet) ?>, <?= h($first) ?>.</h2>
    <p>Live picture of <?= h($company) ?> — sales, bookings, stock and collections.</p>
  </div>
  <a class="button primary" href="?module=sales&new=1">＋ New sale</a>
</section>
<div class="cards"><?php foreach ($cards as $c): ?>
  <div class="card"><div class="card-top"><span><?= h($c['label']) ?></span><i class="<?= h($c['tone']) ?>">◈</i></div><strong><?= h($c['value']) ?></strong><small><?= h($c['meta']) ?></small></div>
<?php endforeach; ?></div>
<div class="grid-two">
  <section class="panel">
    <div class="panel-head"><div><div class="eyebrow">RECENT ACTIVITY</div><h3>Latest sales</h3></div><a href="?module=sales">View all →</a></div>
    <div class="table-wrap"><table><thead><tr><th>Bill</th><th>Client</th><th>Date</th><th class="right">Total</th><th>Type</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><a href="?module=sales&view=<?= $r['id'] ?>"><b><?= h($r['auto_bill_no']) ?></b></a></td>
        <td><?= h($r['client_name'] ?? 'Walk-in') ?></td>
        <td><?= h(substr((string)$r['sale_date'], 0, 10)) ?></td>
        <td class="right"><?= money($r['total_amount']) ?></td>
        <td><?= badge($r['sale_type']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="panel">
    <div class="panel-head"><div><div class="eyebrow">QUICK ACTIONS</div><h3>Work faster</h3></div></div>
    <div class="quick-grid">
      <a href="?module=clients&new=1">＋<b>Add client</b><small>Register a customer</small></a>
      <a href="?module=bookings&new=1">＋<b>New booking</b><small>Advance order</small></a>
      <a href="?module=purchases&new=1">＋<b>Record purchase</b><small>Add stock (GRN)</small></a>
      <a href="?module=payments&new=1">＋<b>Record payment</b><small>Cash in or out</small></a>
    </div>
    <?php if ($low): ?>
      <div class="panel-head"><div><div class="eyebrow">STOCK</div><h3>Below reorder</h3></div></div>
      <div class="table-wrap"><table><thead><tr><th>Material</th><th class="right">Qty</th></tr></thead><tbody>
      <?php foreach ($low as $r): ?><tr><td><?= h($r['name']) ?></td><td class="right"><?= h($r['current_qty']) ?> <?= h($r['unit']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>

<?php elseif ($key === 'reports'):
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
?>
<section class="report-hero">
  <div>
    <div class="eyebrow">BUSINESS INTELLIGENCE</div>
    <h2>Know what is moving the yard.</h2>
    <p>Live summaries from sales, purchases, bookings and collections.</p>
  </div>
  <form class="date-filter">
    <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
    <input type="hidden" name="module" value="reports">
    <button class="button primary">Apply</button>
  </form>
</section>
<div class="report-metrics">
  <div class="card"><span>Filtered sales</span><strong><?= money(scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE date(sale_date) BETWEEN ? AND ? AND status!='cancelled'", [$from, $to])) ?></strong><small>Gross sales</small></div>
  <div class="card"><span>Payments in</span><strong><?= money(scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE direction='in' AND date(payment_date) BETWEEN ? AND ?", [$from, $to])) ?></strong><small>Cash and bank receipts</small></div>
  <div class="card"><span>Purchases</span><strong><?= money(scalar("SELECT COALESCE(SUM(subtotal),0) FROM purchases WHERE date(purchase_date) BETWEEN ? AND ? AND status!='cancelled'", [$from, $to])) ?></strong><small>GRN value</small></div>
  <div class="card"><span>Open bills</span><strong><?= number_format(scalar("SELECT COUNT(*) FROM pending_bills WHERE is_paid=0")) ?></strong><small>Need follow-up</small></div>
</div>
<section class="report-grid">
<?php
$blocks = [
    ['v_profit_by_material', 'Profit by material', 'material_name', 'profit', 'sale_date'],
    ['v_profit_by_client', 'Profit by client', 'client_name', 'profit', 'sale_date'],
    ['v_supplier_balances', 'Supplier balances', 'name', 'balance', null],
    ['v_pending_bookings_followup', 'Open booking follow-ups', 'client_name', 'outstanding', null],
];
foreach ($blocks as [$vt, $title, $name, $amount, $dateCol]):
?>
  <section class="panel">
    <div class="panel-head"><div><div class="eyebrow">LIVE VIEW</div><h3><?= h($title) ?></h3></div><span class="muted">Top 8</span></div>
    <div class="table-wrap"><table><thead><tr><th><?= h(ucwords(str_replace('_', ' ', $name))) ?></th><th class="right"><?= h(ucwords(str_replace('_', ' ', $amount))) ?></th></tr></thead><tbody>
    <?php
      $sql = "SELECT * FROM \"$vt\"";
      $params = [];
      if ($dateCol) { $sql .= " WHERE date(\"$dateCol\") BETWEEN ? AND ?"; $params = [$from, $to]; }
      $sql .= " LIMIT 8";
      $st = db()->prepare($sql); $st->execute($params);
      foreach ($st as $r):
    ?>
      <tr><td><?= h($r[$name] ?? '—') ?></td><td class="right"><?= is_numeric($r[$amount] ?? null) ? money($r[$amount]) : h($r[$amount] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php endforeach; ?>
</section>

<?php elseif ($key === 'settings'):
    $s = db()->query('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
    form_start('Company settings', 'These values appear on the dashboard and printed bills.', 'settings', 1);
    section('Company');
    echo inp('company_name', 'Company name', $s['company_name'] ?? $company);
    echo inp('company_phone', 'Phone', $s['company_phone'] ?? '');
    echo inp('company_email', 'Email', $s['company_email'] ?? '');
    echo area('company_address', 'Address', $s['company_address'] ?? '', 'span-3');
    section_end();
    section('Money & bills');
    echo inp('currency', 'Currency', $s['currency'] ?? 'PKR');
    echo inp('currency_symbol', 'Symbol', $s['currency_symbol'] ?? 'Rs');
    echo inp('tax_rate', 'Tax rate %', $s['tax_rate'] ?? 0, 'number');
    echo inp('invoice_prefix', 'Invoice prefix', $s['invoice_prefix'] ?? 'INV');
    echo inp('bill_prefix', 'Bill prefix', $s['bill_prefix'] ?? 'B');
    echo inp('backdate_grace_days', 'Backdate grace (days)', $s['backdate_grace_days'] ?? 0, 'number');
    echo chk('allow_global_negative_stock', 'Allow negative stock', $s['allow_global_negative_stock'] ?? 0);
    echo chk('low_stock_alert_enabled', 'Low-stock alerts', $s['low_stock_alert_enabled'] ?? 1);
    section_end();
    form_end('settings', 'Save settings');

elseif ($key === 'sales'):
    if ($view && $record):
        $items = item_rows('sale_items', 'sale_id', $view);
        $client = db()->prepare('SELECT * FROM clients WHERE id=?'); $client->execute([$record['client_id']]); $client = $client->fetch() ?: [];
        $dps = db()->prepare('SELECT d.name FROM sale_delivery_persons sdp JOIN delivery_persons d ON d.id=sdp.delivery_person_id WHERE sdp.sale_id=?');
        $dps->execute([$view]); $drivers = $dps->fetchAll(PDO::FETCH_COLUMN);
        ?>
        <div class="toolbar"><a class="button" href="?module=sales">← All sales</a><div><a class="button" href="?module=sales&edit=<?= $view ?>">Edit</a> <button class="button" onclick="window.print()">Print</button></div></div>
        <div class="view-grid">
          <section class="panel">
            <div class="panel-head"><div><div class="eyebrow"><?= h($record['auto_bill_no']) ?></div><h3><?= h($client['name'] ?? 'Client') ?></h3><p class="helper"><?= h($record['manual_bill_no']) ?> · <?= h(substr((string)$record['sale_date'], 0, 16)) ?></p></div><?= badge($record['sale_type']) ?></div>
            <div class="kv">
              <span>Status</span><b><?= badge($record['status']) ?></b>
              <span>Payment</span><b><?= h($record['payment_method'] ?: '—') ?></b>
              <span>Driver</span><b><?= h($drivers ? implode(', ', $drivers) : '—') ?></b>
              <span>Notes</span><b><?= h($record['notes'] ?: '—') ?></b>
            </div>
            <div class="table-wrap"><table><thead><tr><th>Material</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr></thead><tbody>
            <?php foreach ($items as $it): ?>
              <tr><td><b><?= h($it['material_name']) ?></b><span class="sub"><?= h($it['unit']) ?></span></td><td class="right"><?= h($it['qty']) ?></td><td class="right"><?= money($it['rate']) ?></td><td class="right"><?= money($it['amount']) ?></td></tr>
            <?php endforeach; if (!$items): ?><tr><td colspan="4" class="empty">No items</td></tr><?php endif; ?>
            </tbody></table></div>
          </section>
          <section class="panel">
            <div class="panel-head"><div><div class="eyebrow">TOTALS</div><h3>Bill summary</h3></div></div>
            <div class="stat-stack">
              <div><small>Subtotal</small><strong><?= money($record['subtotal']) ?></strong></div>
              <div><small>Discount</small><strong><?= money($record['discount']) ?></strong></div>
              <div><small>Total</small><strong><?= money($record['total_amount']) ?></strong></div>
              <div><small>Paid</small><strong><?= money($record['total_paid_cache']) ?></strong></div>
            </div>
          </section>
        </div>
    <?php else:
        if ($isNew || $edit):
            form_start($edit ? 'Update sale' : 'Create sale', 'Search the client and materials by name. Totals calculate as you type.', 'sales', $edit);
            section('Customer');
            echo combo('client_id', 'clients', 'Client', $record['client_id'] ?? '', true, 'Search client name or code…');
            echo '</div><div id="client-meta" class="client-meta hidden"></div><div class="form-grid">';
            echo inp('sale_date', 'Sale date', substr((string)($record['sale_date'] ?? date('Y-m-d')), 0, 10), 'date', true);
            echo sel('sale_type', 'Sale type', $record['sale_type'] ?? 'cash', ['cash' => 'Cash', 'credit' => 'Credit', 'booking' => 'Booking dispatch', 'booking_credit' => 'Booking credit']);
            echo inp('manual_bill_no', 'Manual bill no', $record['manual_bill_no'] ?? '');
            section_end();
            echo '<div class="form-section' . (in_array($record['sale_type'] ?? '', ['booking', 'booking_credit'], true) ? '' : ' hidden') . '" id="booking-wrap">';
            echo '<h4>Booking dispatch</h4><div class="form-grid">';
            echo combo('booking_id', 'bookings', 'Booking', $record['booking_id'] ?? '', false, 'Search booking bill…');
            echo '</div><div id="booking-panel" class="booking-panel" style="margin-top:10px"><div class="eyebrow">BOOKING FULFILLMENT</div><h4>What is still pending</h4><p id="booking-empty">Choose a client and booking. Remaining quantities appear here.</p><div id="booking-result"></div></div></div>';
            if (!$edit) lines_editor(false);
            else {
                $rows = item_rows('sale_items', 'sale_id', $edit);
                existing_items_table($rows, ['material_name' => 'Material', 'qty' => 'Qty', 'rate' => 'Rate', 'amount' => 'Amount']);
                echo '<div class="form-section"><div class="form-grid">';
                echo inp('discount', 'Discount', $record['discount'] ?? 0, 'number');
                echo '</div></div>';
            }
            section('Payment & delivery');
            echo sel('payment_method', 'Payment method', $record['payment_method'] ?? 'cash', ['' => '—', 'cash' => 'Cash', 'bank' => 'Bank', 'credit' => 'Credit']);
            echo '<div id="account-wrap">' . combo('payment_account_id', 'accounts', 'Account', $record['payment_account_id'] ?? '', false, 'Search account…') . '</div>';
            echo combo('delivery_person_id', 'deliveries', 'Delivery person', '', false, 'Search driver…');
            echo inp('discount_reason', 'Discount reason', $record['discount_reason'] ?? '');
            echo area('notes', 'Notes', $record['notes'] ?? '');
            section_end();
            form_end('sales', $edit ? 'Update sale' : 'Save sale');
        endif;
        toolbar('sales', $search, 'New sale');
        $rows = list_sales($search);
        echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Sales</h3></div><span class="muted">Names, not IDs · latest 120</span></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Date</th><th>Client</th><th>Type</th><th class="right">Items</th><th class="right">Total</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><b>' . h($r['auto_bill_no']) . '</b><span class="sub">' . h($r['manual_bill_no']) . '</span></td><td>' . h(substr((string)$r['sale_date'], 0, 10)) . '</td><td class="name-cell">' . h($r['client_name'] ?? '—') . '</td><td>' . badge($r['sale_type']) . '</td><td class="right">' . h($r['item_count']) . '</td><td class="right">' . money($r['total_amount']) . '</td><td>' . badge($r['status']) . '</td>';
            actions('sales', (int)$r['id']);
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="empty">No sales match this search.</td></tr>';
        echo '</tbody></table></div></section>';
    endif;

elseif ($key === 'bookings'):
    if ($view && $record):
        $items = item_rows('booking_items', 'booking_id', $view);
        $client = db()->prepare('SELECT name FROM clients WHERE id=?'); $client->execute([$record['client_id']]); $cname = $client->fetchColumn();
        ?>
        <div class="toolbar"><a class="button" href="?module=bookings">← All bookings</a><a class="button" href="?module=bookings&edit=<?= $view ?>">Edit</a></div>
        <div class="view-grid">
          <section class="panel">
            <div class="panel-head"><div><div class="eyebrow"><?= h($record['auto_bill_no']) ?></div><h3><?= h($cname) ?></h3><p class="helper"><?= h($record['manual_bill_no']) ?> · <?= h(substr((string)$record['booking_date'], 0, 16)) ?></p></div><?= badge($record['status']) ?></div>
            <div class="table-wrap"><table><thead><tr><th>Material</th><th class="right">Booked</th><th class="right">Dispatched</th><th class="right">Pending</th><th class="right">Rate</th><th class="right">Amount</th></tr></thead><tbody>
            <?php foreach ($items as $it): ?>
              <tr><td><b><?= h($it['material_name']) ?></b></td><td class="right"><?= h($it['qty_booked']) ?></td><td class="right"><?= h($it['qty_dispatched']) ?></td><td class="right"><b><?= h($it['qty_pending']) ?></b></td><td class="right"><?= money($it['rate']) ?></td><td class="right"><?= money($it['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
          </section>
          <section class="panel"><div class="panel-head"><div><div class="eyebrow">TOTALS</div><h3>Booking</h3></div></div>
            <div class="stat-stack">
              <div><small>Total</small><strong><?= money($record['total_amount']) ?></strong></div>
              <div><small>Paid</small><strong><?= money($record['paid_amount']) ?></strong></div>
              <div><small>Outstanding</small><strong><?= money($record['total_amount'] - $record['paid_amount']) ?></strong></div>
            </div>
          </section>
        </div>
    <?php else:
        if ($isNew || $edit):
            form_start($edit ? 'Update booking' : 'Create booking', 'Book material against a client. Dispatch later from Sales → Booking.', 'bookings', $edit);
            section('Customer');
            echo combo('client_id', 'clients', 'Client', $record['client_id'] ?? '', true, 'Search client…');
            echo inp('booking_date', 'Booking date', substr((string)($record['booking_date'] ?? date('Y-m-d')), 0, 10), 'date', true);
            echo inp('manual_bill_no', 'Manual bill no', $record['manual_bill_no'] ?? '');
            section_end();
            if (!$edit) lines_editor(false);
            else existing_items_table(item_rows('booking_items', 'booking_id', $edit), ['material_name' => 'Material', 'qty_booked' => 'Booked', 'qty_dispatched' => 'Dispatched', 'rate' => 'Rate', 'amount' => 'Amount']);
            section('Payment');
            echo inp('paid_amount', 'Amount received now', $record['paid_amount'] ?? 0, 'number');
            echo combo('receive_in_account_id', 'accounts', 'Receive in account', $record['receive_in_account_id'] ?? '', false, 'Search account…');
            echo inp('discount_reason', 'Discount reason', $record['discount_reason'] ?? '');
            echo area('notes', 'Notes', $record['notes'] ?? '');
            section_end();
            form_end('bookings', $edit ? 'Update booking' : 'Save booking');
        endif;
        toolbar('bookings', $search, 'New booking');
        $rows = list_bookings($search);
        echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Bookings</h3></div><span class="muted">Open bookings can be dispatched from Sales</span></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Date</th><th>Client</th><th class="right">Total</th><th class="right">Paid</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><b>' . h($r['auto_bill_no']) . '</b><span class="sub">' . h($r['manual_bill_no']) . '</span></td><td>' . h(substr((string)$r['booking_date'], 0, 10)) . '</td><td class="name-cell">' . h($r['client_name'] ?? '—') . '</td><td class="right">' . money($r['total_amount']) . '</td><td class="right">' . money($r['paid_amount']) . '</td><td>' . badge($r['status']) . '</td>';
            actions('bookings', (int)$r['id']);
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="7" class="empty">No bookings found.</td></tr>';
        echo '</tbody></table></div></section>';
    endif;

elseif ($key === 'purchases'):
    if ($view && $record):
        $items = item_rows('purchase_items', 'purchase_id', $view);
        $sup = db()->prepare('SELECT name FROM suppliers WHERE id=?'); $sup->execute([$record['supplier_id']]); $sname = $sup->fetchColumn();
        ?>
        <div class="toolbar"><a class="button" href="?module=purchases">← All purchases</a><a class="button" href="?module=purchases&edit=<?= $view ?>">Edit</a></div>
        <section class="panel">
          <div class="panel-head"><div><div class="eyebrow"><?= h($record['auto_bill_no']) ?></div><h3><?= h($sname) ?></h3><p class="helper"><?= h($record['manual_bill_no']) ?> · <?= h(substr((string)$record['purchase_date'], 0, 10)) ?></p></div><?= badge($record['status']) ?></div>
          <div class="table-wrap"><table><thead><tr><th>Material</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr></thead><tbody>
          <?php foreach ($items as $it): ?><tr><td><b><?= h($it['material_name']) ?></b></td><td class="right"><?= h($it['qty']) ?></td><td class="right"><?= money($it['rate']) ?></td><td class="right"><?= money($it['amount']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <div class="stat-stack"><div><small>Subtotal / Total</small><strong><?= money($record['subtotal']) ?> · <?= money($record['total_amount']) ?></strong></div></div>
        </section>
    <?php else:
        if ($isNew || $edit):
            form_start($edit ? 'Update purchase' : 'Record purchase / GRN', 'Search the supplier and materials. New stock batches are created automatically.', 'purchases', $edit);
            section('Supplier');
            echo combo('supplier_id', 'suppliers', 'Supplier', $record['supplier_id'] ?? '', true, 'Search supplier…');
            echo inp('purchase_date', 'Purchase date', substr((string)($record['purchase_date'] ?? date('Y-m-d')), 0, 10), 'date', true);
            echo inp('bill_date', 'Bill date', substr((string)($record['bill_date'] ?? date('Y-m-d')), 0, 10), 'date');
            echo inp('due_date', 'Due date', substr((string)($record['due_date'] ?? ''), 0, 10), 'date');
            echo inp('manual_bill_no', 'Manual GRN no', $record['manual_bill_no'] ?? '');
            echo inp('supplier_invoice_no', 'Supplier invoice', $record['supplier_invoice_no'] ?? '');
            section_end();
            if (!$edit) lines_editor(true);
            else existing_items_table(item_rows('purchase_items', 'purchase_id', $edit), ['material_name' => 'Material', 'qty' => 'Qty', 'rate' => 'Rate', 'amount' => 'Amount']);
            section('Payment');
            echo sel('payment_type', 'Payment type', $record['payment_type'] ?? 'credit', ['credit' => 'Credit', 'cash' => 'Cash', 'bank' => 'Bank']);
            echo combo('payment_account_id', 'accounts', 'Account', $record['payment_account_id'] ?? '', false, 'Search account…');
            echo area('notes', 'Notes', $record['notes'] ?? '');
            section_end();
            form_end('purchases', $edit ? 'Update purchase' : 'Save purchase');
        endif;
        toolbar('purchases', $search, 'New purchase');
        $rows = list_purchases($search);
        echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Purchases / GRN</h3></div><span class="muted">Stock is added when you save a new GRN</span></div><div class="table-wrap"><table><thead><tr><th>GRN</th><th>Date</th><th>Supplier</th><th class="right">Subtotal</th><th class="right">Total</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><b>' . h($r['auto_bill_no']) . '</b><span class="sub">' . h($r['manual_bill_no']) . '</span></td><td>' . h(substr((string)$r['purchase_date'], 0, 10)) . '</td><td class="name-cell">' . h($r['supplier_name'] ?? '—') . '</td><td class="right">' . money($r['subtotal']) . '</td><td class="right">' . money($r['total_amount']) . '</td><td>' . badge($r['status']) . '</td>';
            actions('purchases', (int)$r['id']);
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="7" class="empty">No purchases found.</td></tr>';
        echo '</tbody></table></div></section>';
    endif;

elseif ($key === 'payments'):
    if ($isNew || $edit):
        form_start($edit ? 'Update payment' : 'Record payment', 'Choose the party from the searchable list. Bank payments need an account.', 'payments', $edit);
        section('Payment');
        echo inp('payment_date', 'Date', substr((string)($record['payment_date'] ?? date('Y-m-d')), 0, 10), 'date', true);
        echo sel('direction', 'Direction', $record['direction'] ?? 'in', ['in' => 'Money in', 'out' => 'Money out']);
        echo sel('party_type', 'Party type', $record['party_type'] ?? 'client', ['client' => 'Client', 'supplier' => 'Supplier', 'delivery_person' => 'Delivery person', 'lender' => 'Lender', 'owner' => 'Owner', 'other' => 'Other']);
        echo combo('party_id', 'parties', 'Party name', $record['party_id'] ?? '', true, 'Search name…');
        echo inp('amount', 'Amount', $record['amount'] ?? '', 'number', true);
        echo inp('discount', 'Discount / waive', $record['discount'] ?? 0, 'number');
        echo inp('manual_bill_no', 'Manual slip no', $record['manual_bill_no'] ?? '');
        section_end();
        section('Account');
        echo sel('payment_mode', 'Mode', $record['payment_mode'] ?? 'cash', ['cash' => 'Cash', 'bank' => 'Bank', 'adjustment' => 'Adjustment']);
        echo combo('payment_account_id', 'accounts', 'Account', $record['payment_account_id'] ?? '', false, 'Search account…');
        echo sel('reference_type', 'Reference', $record['reference_type'] ?? 'sale', [
            'sale' => 'Sale', 'booking' => 'Booking', 'refund' => 'Refund', 'client_opening' => 'Client opening',
            'supplier_purchase' => 'Supplier purchase', 'supplier_opening' => 'Supplier opening',
            'delivery_wage' => 'Delivery wage', 'delivery_rent' => 'Delivery rent', 'expense' => 'Expense',
            'loan' => 'Loan', 'loan_repayment' => 'Loan repayment', 'owner_draw' => 'Owner draw',
            'owner_contribution' => 'Owner contribution', 'other' => 'Other',
        ]);
        echo inp('reference', 'Reference note', $record['reference'] ?? '');
        echo area('notes', 'Notes', $record['notes'] ?? '');
        section_end();
        form_end('payments', $edit ? 'Update payment' : 'Save payment');
    endif;
    toolbar('payments', $search, 'New payment');
    $rows = list_payments($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Payments</h3></div><span class="muted">In and out · latest 120</span></div><div class="table-wrap"><table><thead><tr><th>Slip</th><th>Date</th><th>Party</th><th>Dir</th><th>Mode</th><th class="right">Amount</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $pname = $r['party_name_snapshot'] ?: party_name((string)$r['party_type'], (int)$r['party_id']);
        echo '<tr><td><b>' . h($r['auto_bill_no']) . '</b><span class="sub">' . h($r['manual_bill_no']) . '</span></td><td>' . h(substr((string)$r['payment_date'], 0, 10)) . '</td><td class="name-cell">' . h($pname ?: '—') . '<span class="sub">' . h($r['party_type']) . '</span></td><td>' . badge($r['direction']) . '</td><td>' . h($r['payment_mode']) . '</td><td class="right">' . money($r['amount']) . '</td>';
        actions('payments', (int)$r['id'], false, true, false);
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="7" class="empty">No payments found.</td></tr>';
    echo '</tbody></table></div></section>';

elseif ($key === 'returns'):
    if ($isNew || $edit):
        form_start($edit ? 'Update return' : 'Record return', 'Use the correct return type. Booking returns need a booking item id.', 'returns', $edit);
        section('Return');
        echo combo('client_id', 'clients', 'Client', $record['client_id'] ?? '', true, 'Search client…');
        echo combo('material_id', 'materials', 'Material', $record['material_id'] ?? '', true, 'Search material…');
        echo sel('return_type', 'Return type', $record['return_type'] ?? 'cash_sale_return', ['cash_sale_return' => 'Cash sale return', 'credit_sale_return' => 'Credit sale return', 'booking_return' => 'Booking return']);
        echo inp('return_date', 'Date', substr((string)($record['return_date'] ?? date('Y-m-d')), 0, 10), 'date', true);
        echo inp('qty', 'Quantity', $record['qty'] ?? '', 'number', true);
        echo inp('rate', 'Rate', $record['rate'] ?? '', 'number');
        echo inp('manual_bill_no', 'Manual bill no', $record['manual_bill_no'] ?? '');
        echo inp('booking_item_id', 'Booking item id', $record['booking_item_id'] ?? '', 'number');
        echo chk('refund_required', 'Refund required (cash returns only)', $record['refund_required'] ?? 0);
        echo sel('refund_status', 'Refund status', $record['refund_status'] ?? 'not_applicable', ['not_applicable' => 'Not applicable', 'pending' => 'Pending', 'paid' => 'Paid']);
        echo area('notes', 'Notes', $record['notes'] ?? '');
        section_end();
        form_end('returns', $edit ? 'Update return' : 'Save return');
    endif;
    toolbar('returns', $search, 'New return');
    $rows = list_returns($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Returns</h3></div></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Date</th><th>Client</th><th>Material</th><th class="right">Qty</th><th class="right">Amount</th><th>Type</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><b>' . h($r['auto_bill_no']) . '</b></td><td>' . h(substr((string)$r['return_date'], 0, 10)) . '</td><td class="name-cell">' . h($r['client_name'] ?? '—') . '</td><td>' . h($r['material_name'] ?? '—') . '</td><td class="right">' . h($r['qty']) . '</td><td class="right">' . money($r['amount']) . '</td><td>' . badge($r['return_type']) . '</td>';
        actions('returns', (int)$r['id'], false);
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="8" class="empty">No returns found.</td></tr>';
    echo '</tbody></table></div></section>';

elseif ($key === 'pending_bills'):
    if ($isNew || $edit):
        form_start($edit ? 'Update pending bill' : 'Add pending bill', 'Collections queue. Mark paid when the customer settles.', 'pending_bills', $edit);
        section('Bill');
        echo combo('client_id', 'clients', 'Client', $record['client_id'] ?? '', false, 'Search client…');
        echo inp('bill_no', 'Bill no', $record['bill_no'] ?? '');
        echo sel('bill_kind', 'Kind', $record['bill_kind'] ?? 'other', ['sale' => 'Sale', 'booking' => 'Booking', 'grn' => 'GRN', 'refund' => 'Refund', 'other' => 'Other']);
        echo inp('amount', 'Amount', $record['amount'] ?? '', 'number', true);
        echo inp('reason', 'Reason', $record['reason'] ?? '');
        echo inp('transaction_type', 'Transaction type', $record['transaction_type'] ?? '');
        echo chk('is_paid', 'Paid', $record['is_paid'] ?? 0);
        echo chk('is_cash', 'Cash', $record['is_cash'] ?? 0);
        echo area('notes', 'Notes', $record['notes'] ?? '');
        section_end();
        form_end('pending_bills');
    endif;
    toolbar('pending_bills', $search, 'New pending bill');
    $rows = list_pending($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Pending bills</h3></div><span class="muted">Unpaid first</span></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Client</th><th>Kind</th><th class="right">Amount</th><th>Paid</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $nm = $r['client_name'] ?: $r['client_name_snapshot'];
        echo '<tr><td><b>' . h($r['bill_no'] ?: '—') . '</b><span class="sub">' . h(substr((string)$r['created_at'], 0, 10)) . '</span></td><td class="name-cell">' . h($nm ?: '—') . '</td><td>' . badge($r['bill_kind']) . '</td><td class="right">' . money($r['amount']) . '</td><td>' . badge($r['is_paid'] ? 'paid' : 'open') . '</td><td class="actions"><a href="?module=pending_bills&edit=' . $r['id'] . '">Edit</a>';
        if (!$r['is_paid']) {
            echo '<form method="post">' . csrf_fields((string)$r['id']) . '<input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="' . $r['id'] . '"><button class="linkish" style="color:#1d8a58">Mark paid</button></form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="6" class="empty">No pending bills.</td></tr>';
    echo '</tbody></table></div></section>';

elseif ($key === 'materials'):
    if ($isNew || $edit):
        form_start($edit ? 'Update material' : 'Add material', 'Code is generated if you leave it blank. Name must be unique inside a category.', 'materials', $edit);
        section('Material');
        echo inp('code', 'Code', $record['code'] ?? '', 'text', false, 'placeholder="Auto if empty"');
        echo inp('name', 'Name', $record['name'] ?? '', 'text', true);
        echo inp('unit', 'Unit', $record['unit'] ?? 'Bags', 'text', true);
        echo cat_opts('material', $record['category_id'] ?? '');
        echo inp('current_rate', 'Current rate', $record['current_rate'] ?? 0, 'number');
        echo inp('reorder_level', 'Reorder level', $record['reorder_level'] ?? 0, 'number');
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        form_end('materials');
    endif;
    toolbar('materials', $search, 'New material');
    $rows = list_materials($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Materials & stock</h3></div><span class="muted">Live FIFO stock</span></div><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th class="right">Rate</th><th class="right">Stock</th><th>Active</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['code']) . '</td><td class="name-cell">' . h($r['name']) . '</td><td>' . h($r['category_name'] ?? '—') . '</td><td>' . h($r['unit']) . '</td><td class="right">' . money($r['current_rate']) . '</td><td class="right">' . h($r['stock']) . '</td><td>' . badge($r['active'] ? 'active' : 'disabled') . '</td>';
        actions('materials', (int)$r['id'], false, true, true, 'Deactivate');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'clients'):
    if ($isNew || $edit):
        form_start($edit ? 'Update client' : 'Add client', 'Name is required. Code is generated if left blank. Book pages stay together at the bottom.', 'clients', $edit);
        section('Identity');
        echo inp('code', 'Code', $record['code'] ?? '', 'text', false, 'placeholder="Auto if empty"');
        echo inp('name', 'Name', $record['name'] ?? '', 'text', true);
        echo inp('phone', 'Phone', $record['phone'] ?? '');
        echo inp('cnic', 'CNIC', $record['cnic'] ?? '');
        echo cat_opts('client', $record['category_id'] ?? '');
        echo sel('default_type', 'Default sale type', $record['default_type'] ?? 'cash', ['cash' => 'Cash', 'credit' => 'Credit', 'booking' => 'Booking']);
        section_end();
        section('Address');
        echo area('address', 'Address', $record['address'] ?? '', 'span-2');
        echo inp('location_url', 'Location URL', $record['location_url'] ?? '');
        section_end();
        section('Opening balance');
        echo inp('opening_balance', 'Opening balance', $record['opening_balance'] ?? 0, 'number');
        echo inp('opening_balance_date', 'As of', substr((string)($record['opening_balance_date'] ?? ''), 0, 10), 'date');
        echo chk('require_manual_invoice', 'Require manual invoice', $record['require_manual_invoice'] ?? 0);
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        section('Khata / book pages');
        echo inp('book_no', 'Book no', $record['book_no'] ?? '');
        echo inp('financial_book_no', 'Financial book', $record['financial_book_no'] ?? '');
        echo inp('financial_page', 'Financial page', $record['financial_page'] ?? '');
        echo inp('cement_book_no', 'Cement book', $record['cement_book_no'] ?? '');
        echo inp('cement_page', 'Cement page', $record['cement_page'] ?? '');
        echo inp('steel_book_no', 'Steel book', $record['steel_book_no'] ?? '');
        echo inp('steel_page', 'Steel page', $record['steel_page'] ?? '');
        echo area('page_notes', 'Page notes', $record['page_notes'] ?? '');
        section_end();
        form_end('clients');
    endif;
    toolbar('clients', $search, 'New client');
    $rows = list_clients($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Clients</h3></div><span class="muted">Search by name, code or phone</span></div><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Type</th><th class="right">Balance</th><th>Active</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['code']) . '</td><td class="name-cell">' . h($r['name']) . '<span class="sub">' . h($r['category_name'] ?? '') . '</span></td><td>' . h($r['phone'] ?: '—') . '</td><td>' . badge($r['default_type'] ?: 'cash') . '</td><td class="right">' . money($r['balance']) . '</td><td>' . badge($r['active'] ? 'active' : 'disabled') . '</td>';
        actions('clients', (int)$r['id'], false, true, true, 'Deactivate');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'suppliers'):
    if ($isNew || $edit):
        form_start($edit ? 'Update supplier' : 'Add supplier', 'Used on purchases / GRN.', 'suppliers', $edit);
        section('Supplier');
        echo inp('code', 'Code', $record['code'] ?? '', 'text', false, 'placeholder="Auto if empty"');
        echo inp('name', 'Name', $record['name'] ?? '', 'text', true);
        echo inp('phone', 'Phone', $record['phone'] ?? '');
        echo cat_opts('account', $record['category_id'] ?? '');
        echo inp('opening_balance', 'Opening balance', $record['opening_balance'] ?? 0, 'number');
        echo inp('opening_balance_date', 'As of', substr((string)($record['opening_balance_date'] ?? ''), 0, 10), 'date');
        echo area('address', 'Address', $record['address'] ?? '');
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        form_end('suppliers');
    endif;
    toolbar('suppliers', $search, 'New supplier');
    $rows = list_suppliers($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Suppliers</h3></div></div><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Phone</th><th class="right">Balance</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['code']) . '</td><td class="name-cell">' . h($r['name']) . '</td><td>' . h($r['phone'] ?: '—') . '</td><td class="right">' . money($r['balance']) . '</td>';
        actions('suppliers', (int)$r['id'], false, true, true, 'Deactivate');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'delivery_persons'):
    if ($isNew || $edit):
        form_start($edit ? 'Update driver' : 'Add delivery person', 'Drivers and loaders attached to sales.', 'delivery_persons', $edit);
        section('Person');
        echo inp('code', 'Code', $record['code'] ?? '', 'text', false, 'placeholder="Auto if empty"');
        echo inp('name', 'Name', $record['name'] ?? '', 'text', true);
        echo inp('phone', 'Phone', $record['phone'] ?? '');
        echo inp('rate_per_trip', 'Rate per trip', $record['rate_per_trip'] ?? 0, 'number');
        echo inp('opening_balance', 'Opening balance', $record['opening_balance'] ?? 0, 'number');
        echo inp('opening_balance_date', 'As of', substr((string)($record['opening_balance_date'] ?? ''), 0, 10), 'date');
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        form_end('delivery_persons');
    endif;
    toolbar('delivery_persons', $search, 'New driver');
    $rows = list_delivery($search);
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Delivery team</h3></div></div><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Phone</th><th class="right">Trip rate</th><th class="right">Balance</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['code']) . '</td><td class="name-cell">' . h($r['name']) . '</td><td>' . h($r['phone'] ?: '—') . '</td><td class="right">' . money($r['rate_per_trip']) . '</td><td class="right">' . money($r['balance']) . '</td>';
        actions('delivery_persons', (int)$r['id'], false, true, true, 'Deactivate');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'accounts'):
    if ($isNew || $edit):
        form_start($edit ? 'Update account' : 'Add account', 'Running balance is maintained by payments. Edit opening balance only.', 'accounts', $edit);
        section('Account');
        echo inp('name', 'Name', $record['name'] ?? '', 'text', true);
        echo sel('account_type', 'Type', $record['account_type'] ?? 'other', ['cash_drawer' => 'Cash drawer', 'bank' => 'Bank', 'expense' => 'Expense', 'revenue' => 'Revenue', 'loan' => 'Loan', 'owner' => 'Owner', 'other' => 'Other']);
        echo sel('account_class', 'Class', $record['account_class'] ?? '', ['' => '—', 'asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense']);
        echo cat_opts('account', $record['category_id'] ?? '');
        echo inp('bank_name', 'Bank name', $record['bank_name'] ?? '');
        echo inp('account_holder_name', 'Holder name', $record['account_holder_name'] ?? '');
        echo inp('account_number', 'Account number', $record['account_number'] ?? '');
        echo inp('branch_code', 'Branch code', $record['branch_code'] ?? '');
        echo inp('opening_balance', 'Opening balance', $record['opening_balance'] ?? 0, 'number');
        echo inp('opening_balance_date', 'As of', substr((string)($record['opening_balance_date'] ?? ''), 0, 10), 'date');
        echo area('note', 'Note', $record['note'] ?? '');
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        form_end('accounts');
    endif;
    toolbar('accounts', $search, 'New account');
    $rows = db()->query("SELECT * FROM accounts ORDER BY active DESC, name")->fetchAll();
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' RECORDS</div><h3>Accounts</h3></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Type</th><th class="right">Balance</th><th>Active</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="name-cell">' . h($r['name']) . '</td><td>' . badge($r['account_type']) . '</td><td class="right">' . money($r['balance']) . '</td><td>' . badge($r['active'] ? 'active' : 'disabled') . '</td>';
        actions('accounts', (int)$r['id'], false, true, true, 'Deactivate');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'users'):
    if ($isNew || $edit):
        form_start($edit ? 'Update user' : 'Add user', 'Roles control what each person can do.', 'users', $edit);
        section('User');
        echo inp('username', 'Username', $record['username'] ?? '', 'text', true);
        echo inp('full_name', 'Full name', $record['full_name'] ?? '', 'text', true);
        $roleOpts = [];
        foreach ($look['roles'] as $r) $roleOpts[$r['id']] = $r['name'];
        echo sel('role_id', 'Role', $record['role_id'] ?? '', $roleOpts, true);
        echo inp('phone', 'Phone', $record['phone'] ?? '');
        echo inp('email', 'Email', $record['email'] ?? '');
        echo sel('status', 'Status', $record['status'] ?? 'active', ['active' => 'Active', 'suspended' => 'Suspended', 'disabled' => 'Disabled']);
        echo inp('new_password', $edit ? 'New password (blank = keep)' : 'Password', '', 'password', !$edit);
        echo chk('restrict_backdated_edit', 'Restrict backdated entries', $record['restrict_backdated_edit'] ?? 0);
        if ($edit) echo chk('active', 'Active', $record['active'] ?? 1);
        section_end();
        form_end('users');
    endif;
    toolbar('users', $search, 'New user');
    $rows = db()->query("SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id")->fetchAll();
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' USERS</div><h3>Users & roles</h3></div></div><div class="table-wrap"><table><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['username']) . '</td><td class="name-cell">' . h($r['full_name']) . '</td><td>' . h($r['role_name']) . '</td><td>' . badge($r['status']) . '</td><td>' . h($r['last_login_at'] ?: '—') . '</td>';
        actions('users', (int)$r['id'], false, true, false);
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

elseif ($key === 'audit_log'):
    toolbar('audit_log', $search, '');
    $sql = "SELECT * FROM audit_log";
    $p = [];
    if ($search !== '') { $sql .= " WHERE username LIKE ? OR action LIKE ? OR details LIKE ?"; $p = like_params($search, 3); }
    $sql .= " ORDER BY id DESC LIMIT 200";
    $st = db()->prepare($sql); $st->execute($p); $rows = $st->fetchAll();
    echo '<section class="panel"><div class="panel-head"><div><div class="eyebrow">' . count($rows) . ' EVENTS</div><h3>Audit trail</h3></div></div><div class="table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['timestamp']) . '</td><td>' . h($r['username']) . '</td><td>' . h($r['action']) . '</td><td>' . h($r['details']) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="4" class="empty">No audit rows yet.</td></tr>';
    echo '</tbody></table></div></section>';
endif; ?>
</main>
</body>
</html>
