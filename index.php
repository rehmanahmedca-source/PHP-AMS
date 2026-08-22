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
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid request token'); } }
function table_exists(string $t): bool {
    return (bool)db()->query("SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name=" . db()->quote($t))->fetchColumn();
}
function cols(string $t): array { return db()->query('PRAGMA table_info("' . str_replace('"','""',$t) . '")')->fetchAll(); }
function label(string $s): string { return ucwords(str_replace('_',' ', preg_replace('/_minor$/','',$s))); }
function money($n): string { return 'Rs ' . number_format((float)$n, 2); }
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type,$msg]; }
function redirect(string $url): never { header("Location: $url"); exit; }
function next_bill(string $namespace, string $prefix): string {
    $s=db()->prepare("SELECT count FROM bill_counter WHERE namespace=?"); $s->execute([$namespace]); $count=$s->fetchColumn();
    if ($count===false) { db()->prepare("INSERT INTO bill_counter(namespace,count) VALUES(?,1)")->execute([$namespace]); $count=1; }
    else { $count=(int)$count+1; db()->prepare("UPDATE bill_counter SET count=? WHERE namespace=?")->execute([$count,$namespace]); }
    return $prefix . '-' . str_pad((string)$count, 5, '0', STR_PAD_LEFT);
}

$modules = [
 'dashboard'=>['label'=>'Dashboard','icon'=>'⌂','table'=>null],
 'sales'=>['label'=>'Sales','icon'=>'▣','table'=>'sales'],
 'bookings'=>['label'=>'Bookings','icon'=>'◫','table'=>'bookings'],
 'purchases'=>['label'=>'Purchases / GRN','icon'=>'▤','table'=>'purchases'],
 'payments'=>['label'=>'Payments','icon'=>'↔','table'=>'payments'],
 'returns'=>['label'=>'Returns','icon'=>'↩','table'=>'returns'],
 'pending_bills'=>['label'=>'Pending Bills','icon'=>'◷','table'=>'pending_bills'],
 'materials'=>['label'=>'Materials & Stock','icon'=>'▥','table'=>'materials'],
 'clients'=>['label'=>'Clients','icon'=>'◎','table'=>'clients'],
 'suppliers'=>['label'=>'Suppliers','icon'=>'◇','table'=>'suppliers'],
 'delivery_persons'=>['label'=>'Delivery Team','icon'=>'♧','table'=>'delivery_persons'],
 'accounts'=>['label'=>'Accounts','icon'=>'◉','table'=>'accounts'],
 'reports'=>['label'=>'Reports','icon'=>'▥','table'=>null],
 'users'=>['label'=>'Users & Roles','icon'=>'♙','table'=>'users'],
 'audit_log'=>['label'=>'Audit Trail','icon'=>'◌','table'=>'audit_log'],
];
$key = $_GET['module'] ?? 'dashboard';
if (!isset($modules[$key])) $key='dashboard';
$table = $modules[$key]['table'];

// Seed a session user for the existing local database. Login can be added without changing modules.
if (!isset($_SESSION['user'])) {
    $u = db()->query("SELECT u.*, r.name role_name, r.is_admin_role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active=1 ORDER BY u.id LIMIT 1")->fetch();
    if ($u) $_SESSION['user'] = $u;
}
if (isset($_GET['logout'])) { session_destroy(); redirect('?'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $action=$_POST['action'] ?? '';
    try {
        if ($action==='save' && $table && table_exists($table)) {
            $schema=cols($table); $id=(int)($_POST['id']??0); $data=[];
            // Transaction quick-add: a header without an item is not a valid sale/purchase.
            if (!$id && in_array($table,['sales','purchases'],true)) {
                $material=(int)($_POST['line_material_id']??0); $qty=(float)($_POST['line_qty']??0); $rate=(float)($_POST['line_rate']??0);
                if (!$material || $qty<=0 || $rate<0) throw new RuntimeException('Add a material, quantity and rate before saving.');
                $date=$_POST[$table==='sales'?'sale_date':'purchase_date'] ?: date('Y-m-d');
                $header=$table==='sales' ? [
                  'auto_bill_no'=>next_bill('sales','SB'),'manual_bill_no'=>$_POST['manual_bill_no']??'','client_id'=>(int)($_POST['client_id']??0),
                  'sale_date'=>$date,'sale_type'=>$_POST['sale_type']??'cash','subtotal'=>0,'subtotal_minor'=>0,'discount'=>(float)($_POST['discount']??0),'discount_minor'=>round((float)($_POST['discount']??0)*100),
                  'tax_amount'=>0,'total_amount'=>0,'total_amount_minor'=>0,'payment_method'=>$_POST['payment_method']??'cash','status'=>'active','revision'=>0,'notes'=>$_POST['notes']??''
                ] : [
                  'auto_bill_no'=>next_bill('purchases','GRN'),'manual_bill_no'=>$_POST['manual_bill_no']??'','supplier_id'=>(int)($_POST['supplier_id']??0),
                  'purchase_date'=>$date,'bill_date'=>$_POST['bill_date']??$date,'subtotal'=>0,'subtotal_minor'=>0,'loading_cost'=>(float)($_POST['loading_cost']??0),'freight_cost'=>(float)($_POST['freight_cost']??0),'other_expense'=>(float)($_POST['other_expense']??0),'adjustment_amount'=>0,'discount'=>(float)($_POST['discount']??0),'discount_minor'=>round((float)($_POST['discount']??0)*100),
                  'tax_percent'=>0,'tax_amount'=>0,'total_amount'=>0,'total_amount_minor'=>0,'paid_amount'=>0,'paid_amount_minor'=>0,'status'=>'active','revision'=>0,'notes'=>$_POST['notes']??''
                ];
                if ($header['client_id']??$header['supplier_id'] ? true : false) { /* required-party validation handled by SQLite */ }
                $names=array_keys($header); $q=implode(',',array_fill(0,count($names),'?'));
                db()->beginTransaction();
                db()->prepare('INSERT INTO "'.$table.'" ('.implode(',',$names).') VALUES ('.$q.')')->execute(array_values($header));
                $newId=(int)db()->lastInsertId(); $item=$table==='sales'?'sale_items':'purchase_items';
                db()->prepare("INSERT INTO $item (".($table==='sales'?'sale_id':'purchase_id').",material_id,qty,rate,rate_minor,amount,amount_minor) VALUES (?,?,?,?,?,?,?)")
                  ->execute([$newId,$material,$qty,$rate,round($rate*100),$qty*$rate,round($qty*$rate*100)]);
                db()->commit(); flash('success',label($table).' and line item created successfully.'); redirect('?module='.$key);
            }
            foreach($schema as $c) {
                $n=$c['name'];
                if (!in_array($n, form_fields($table), true)) continue;
                if ($n==='id' || in_array($n,['created_at','updated_at','password_hash','password_plain','last_login_at','approved_at','approved_by','created_by'])) continue;
                if (isset($_POST[$n])) {
                    $v=$_POST[$n];
                    if (($c['type']==='INTEGER' && in_array($n,['active','approved','is_paid','is_cash','refund_required','cash_deposited','require_manual_invoice','restrict_backdated_edit'])) ) $v=$v?1:0;
                    if (str_ends_with($n,'_minor') && isset($_POST[preg_replace('/_minor$/','',$n)])) $v=round((float)$_POST[preg_replace('/_minor$/','',$n)]*100);
                    $data[$n]=($v===''?null:$v);
                }
            }
            if ($table==='users' && isset($_POST['new_password']) && $_POST['new_password']!=='') $data['password_hash']=password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            if (!$id) {
                $names=array_keys($data); $q=implode(',',array_fill(0,count($names),'?'));
                db()->prepare('INSERT INTO "'.$table.'" ('.implode(',',$names).') VALUES ('.$q.')')->execute(array_values($data));
                $id=(int)db()->lastInsertId(); flash('success',label($table).' created successfully.');
            } else {
                $set=implode(',',array_map(fn($n)=>'"'.$n.'"=?',array_keys($data)));
                db()->prepare('UPDATE "'.$table.'" SET '.$set.' WHERE id=?')->execute([...array_values($data),$id]);
                flash('success',label($table).' updated successfully.');
            }
            redirect('?module='.$key);
        }
        if ($action==='delete' && $table && !in_array($table,['audit_log','users','accounts'])) {
            $id=(int)$_POST['id']; db()->prepare('DELETE FROM "'.$table.'" WHERE id=?')->execute([$id]); flash('success','Record deleted.');
            redirect('?module='.$key);
        }
    } catch (Throwable $e) { flash('error','Could not save: '.$e->getMessage()); redirect('?module='.$key.'&edit='.(int)($_POST['id']??0)); }
}

function scalar(string $sql): float { return (float)(db()->query($sql)->fetchColumn() ?: 0); }
function dashboard(): array {
    return [
      ['label'=>'Sales this month','value'=>money(scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE strftime('%Y-%m',sale_date)=strftime('%Y-%m','now')")),'tone'=>'blue','meta'=>'Revenue posted'],
      ['label'=>'Outstanding receivables','value'=>money(scalar("SELECT COALESCE(SUM(total_amount-total_paid_cache),0) FROM sales WHERE status NOT IN ('cancelled')")),'tone'=>'amber','meta'=>'Across active sales'],
      ['label'=>'Inventory items','value'=>number_format(scalar("SELECT COUNT(*) FROM materials WHERE active=1")),'tone'=>'teal','meta'=>'Active materials'],
      ['label'=>'Pending bills','value'=>number_format(scalar("SELECT COUNT(*) FROM pending_bills WHERE is_paid=0")),'tone'=>'rose','meta'=>'Needs attention'],
    ];
}
function rows_for(string $t, string $search='', int $limit=100): array {
    $cs=cols($t); $names=array_map(fn($c)=>$c['name'],$cs); $where=''; $params=[];
    if ($search!=='') { $parts=[]; foreach(array_slice($names,0,12) as $n){$parts[]='CAST("'.$n.'" AS TEXT) LIKE ?';$params[]='%'.$search.'%';} $where=' WHERE '.implode(' OR ',$parts); }
    $order=in_array('id',$names)?' ORDER BY id DESC':'';
    return db()->prepare('SELECT * FROM "'.$t.'"'.$where.$order.' LIMIT '.$limit) ? (function() use($t,$where,$order,$params){$s=db()->prepare('SELECT * FROM "'.$t.'"'.$where.$order.' LIMIT 100');$s->execute($params);return $s->fetchAll();})() : [];
}
function fk_options(string $type): array {
    $map=['client_id'=>['clients','name'],'supplier_id'=>['suppliers','name'],'material_id'=>['materials','name'],'category_id'=>['categories','name'],'role_id'=>['roles','name'],'payment_account_id'=>['accounts','name'],'receive_in_account_id'=>['accounts','name'],'linked_user_id'=>['users','full_name']];
    if (!isset($map[$type])) return [];
    [$t,$n]=$map[$type]; return db()->query("SELECT id, $n name FROM $t ORDER BY name")->fetchAll();
}
function form_fields(string $table): array {
    $sets = [
      'sales'=>['client_id','sale_date','sale_type','manual_bill_no','payment_method','payment_account_id','discount','discount_reason','notes'],
      'bookings'=>['client_id','booking_date','manual_bill_no','discount','discount_reason','receive_in_account_id','notes'],
      'purchases'=>['supplier_id','supplier_invoice_no','bill_date','purchase_date','due_date','loading_cost','freight_cost','other_expense','discount','payment_type','payment_account_id','notes'],
      'payments'=>['payment_date','direction','party_type','party_id','amount','discount','payment_mode','payment_account_id','reference','notes'],
      'returns'=>['return_date','return_type','client_id','material_id','qty','rate','refund_required','refund_status','notes'],
      'pending_bills'=>['client_id','bill_no','bill_kind','transaction_type','amount','reason','is_paid','is_cash','notes'],
      'materials'=>['code','name','unit','category_id','current_rate','reorder_level','active'],
      'clients'=>['code','name','phone','cnic','address','location_url','category_id','default_type','opening_balance','opening_balance_date','book_no','page_notes','require_manual_invoice','active'],
      'suppliers'=>['code','name','phone','address','category_id','opening_balance','opening_balance_date','active'],
      'delivery_persons'=>['code','name','phone','rate_per_trip','opening_balance','opening_balance_date','active'],
      'accounts'=>['name','account_type','account_class','category_id','source_category','bank_name','account_holder_name','account_number','branch_code','opening_balance','opening_balance_date','note','active'],
      'users'=>['username','full_name','role_id','phone','email','status','restrict_backdated_edit','active'],
    ];
    return $sets[$table] ?? array_map(fn($c)=>$c['name'], cols($table));
}
function list_fields(string $table): array {
    $sets = [
      'sales'=>['auto_bill_no','sale_date','client_id','sale_type','total_amount','total_paid_cache','status'],
      'bookings'=>['auto_bill_no','booking_date','client_id','total_amount','paid_amount','status'],
      'purchases'=>['auto_bill_no','purchase_date','supplier_id','total_amount','paid_amount','status'],
      'payments'=>['auto_bill_no','payment_date','direction','party_name_snapshot','amount','payment_mode'],
      'returns'=>['auto_bill_no','return_date','return_type','client_id','material_id','qty','amount','refund_status'],
      'pending_bills'=>['bill_no','created_at','client_name_snapshot','bill_kind','amount','is_paid','source_module'],
      'materials'=>['code','name','unit','category_id','current_rate','reorder_level','active'],
      'clients'=>['code','name','phone','default_type','opening_balance','active'],
      'suppliers'=>['code','name','phone','opening_balance','active'],
      'delivery_persons'=>['code','name','phone','rate_per_trip','opening_balance','active'],
      'accounts'=>['name','account_type','balance','active'],
      'users'=>['username','full_name','role_id','status','last_login_at'],
      'audit_log'=>['timestamp','username','action','details'],
    ];
    return $sets[$table] ?? array_map(fn($c)=>$c['name'], cols($table));
}
function render_field(array $c, $value): string {
    $n=$c['name']; if(in_array($n,['id','created_at','updated_at','password_hash','password_plain','approved_at','approved_by','created_by','last_login_at'])) return '';
    $v=h($value); $type=strtoupper($c['type']); $required=$c['notnull']?' required':'';
    if ($n==='new_password') return '';
    if ($type==='INTEGER' && in_array($n,['active','approved','is_paid','is_cash','refund_required','cash_deposited','require_manual_invoice','restrict_backdated_edit'])) return '<label class="check"><input type="hidden" name="'.h($n).'" value="0"><input type="checkbox" name="'.h($n).'" value="1" '.($value?'checked':'').'> <span>'.label($n).'</span></label>';
    $enums = [
      'sale_type'=>['cash','credit','booking'], 'payment_method'=>['cash','bank','credit'],
      'payment_type'=>['cash','credit'], 'direction'=>['in','out'], 'party_type'=>['client','supplier','delivery_person','lender','expense','other'],
      'payment_mode'=>['cash','bank','online','adjustment'], 'return_type'=>['sale','booking'], 'refund_status'=>['pending','refunded','not_required'],
      'default_type'=>['cash','credit','booking'], 'status'=>['active','suspended','disabled'], 'bill_kind'=>['sale','purchase','booking','manual'],
      'transaction_type'=>['sale','purchase','payment','expense','other'], 'account_type'=>['cash_drawer','bank','expense','revenue','loan','owner','other'],
      'account_class'=>['asset','liability','equity','income','expense'], 'unit'=>['bag','kg','ton','piece'],
    ];
    if (isset($enums[$n])) { $o='<option value="">Select…</option>'; foreach($enums[$n] as $x)$o.='<option value="'.h($x).'" '.((string)$value===$x?'selected':'').'>'.h(ucwords(str_replace('_',' ',$x))).'</option>'; return '<label>'.label($n).'<select name="'.h($n).'">'.$o.'</select></label>'; }
    if (str_ends_with($n,'_id') && $opts=fk_options($n)) { $o='<option value="">Select…</option>'; foreach($opts as $x)$o.='<option value="'.$x['id'].'" '.((string)$value===(string)$x['id']?'selected':'').'>'.h($x['name']).'</option>'; return '<label>'.label($n).'<select name="'.h($n).'">'.$o.'</select></label>'; }
    $input=($type==='REAL'||str_ends_with($n,'_amount')||str_ends_with($n,'_rate')||$n==='amount'||$n==='discount'||str_contains($n,'balance'))?'number':'text';
    if (str_contains($n,'date')) $input='date';
    if (in_array($n,['notes','address','description','reason','discount_reason','page_notes'])) return '<label>'.label($n).'<textarea name="'.h($n).'">'.$v.'</textarea></label>';
    return '<label>'.label($n).'<input type="'.$input.'" name="'.h($n).'" value="'.$v.'"'.$required.'></label>';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AMSCOPY9 · Cement ERP</title><link rel="stylesheet" href="style.css"></head><body>
<aside class="sidebar"><div class="brand"><div class="brandmark">A</div><div><b>AMSCOPY9</b><small>CEMENT ERP</small></div></div>
<nav><?php foreach($modules as $k=>$m): if($k==='reports') echo '<div class="nav-label">INSIGHTS</div>'; if(in_array($k,['users','audit_log'])) { if($k==='users') echo '<div class="nav-label">ADMINISTRATION</div>'; } ?><a class="<?= $key===$k?'active':'' ?>" href="?module=<?=$k?>"><span class="nav-icon"><?=$m['icon']?></span><?=$m['label']?></a><?php endforeach;?></nav>
<div class="sidebar-footer"><div class="avatar"><?=h(strtoupper(substr($_SESSION['user']['full_name']??'A',0,1)))?></div><div><b><?=h($_SESSION['user']['full_name']??'Administrator')?></b><small><?=h($_SESSION['user']['role_name']??'Admin')?></small></div><a href="?logout=1" class="logout">↪</a></div></aside>
<main><header><button class="menu" onclick="document.body.classList.toggle('open')">☰</button><div><div class="eyebrow">OPERATIONS / <?=strtoupper(h($modules[$key]['label']))?></div><h1><?=h($modules[$key]['label'])?></h1></div><div class="header-actions"><span class="status-dot"></span> System online <a class="user-pill" href="?module=users"><?=h($_SESSION['user']['full_name']??'Admin')?> <span>⌄</span></a></div></header>
<?php if(!empty($_SESSION['flash'])): [$ft,$fm]=$_SESSION['flash']; unset($_SESSION['flash']); ?><div class="flash <?=$ft?>"><?=h($fm)?></div><?php endif;?>
<?php if($key==='dashboard'): $cards=dashboard(); ?>
<section class="welcome"><div><div class="eyebrow">SUNDAY, AUGUST 23, 2026</div><h2>Good morning, <?=h(explode(' ', $_SESSION['user']['full_name']??'Admin')[0])?>.</h2><p>Here’s what is happening across your cement operations today.</p></div><a class="button primary" href="?module=sales&new=1">＋ New sale</a></section>
<div class="cards"><?php foreach($cards as $c):?><div class="card"><div class="card-top"><span><?=h($c['label'])?></span><i class="<?=$c['tone']?>">◈</i></div><strong><?=h($c['value'])?></strong><small><?=h($c['meta'])?></small></div><?php endforeach;?></div>
<div class="grid-two"><section class="panel"><div class="panel-head"><div><div class="eyebrow">RECENT ACTIVITY</div><h3>Latest sales</h3></div><a href="?module=sales">View all →</a></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Client</th><th>Date</th><th class="right">Total</th><th>Status</th></tr></thead><tbody><?php $recent=db()->query("SELECT s.auto_bill_no,s.sale_date,s.total_amount,s.status,c.name client_name FROM sales s LEFT JOIN clients c ON c.id=s.client_id ORDER BY s.id DESC LIMIT 6")->fetchAll(); foreach($recent as $r):?><tr><td><b><?=h($r['auto_bill_no'])?></b></td><td><?=h($r['client_name']??'Walk-in')?></td><td><?=h(substr($r['sale_date'],0,10))?></td><td class="right"><?=money($r['total_amount'])?></td><td><span class="badge <?=h($r['status'])?>"><?=h(ucwords(str_replace('_',' ',$r['status'])))?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><div class="eyebrow">QUICK ACTIONS</div><h3>Work faster</h3></div></div><div class="quick-grid"><a href="?module=clients&new=1">＋<b>Add client</b><small>Register a new customer</small></a><a href="?module=purchases&new=1">＋<b>Record purchase</b><small>Add stock to inventory</small></a><a href="?module=payments&new=1">＋<b>Record payment</b><small>Update account balances</small><a href="?module=reports">↗<b>Open reports</b><small>Review business insights</small></a></div></section></div>
<?php elseif($key==='reports'): $from=$_GET['from']??date('Y-m-01'); $to=$_GET['to']??date('Y-m-d'); ?>
<section class="report-hero"><div><div class="eyebrow">BUSINESS INTELLIGENCE</div><h2>Know what is moving the business.</h2><p>Live summaries from sales, purchases, bookings and account balances.</p></div><form class="date-filter"><label>From<input type="date" name="from" value="<?=h($from)?>"></label><label>To<input type="date" name="to" value="<?=h($to)?>"></label><input type="hidden" name="module" value="reports"><button class="button primary">Apply filter</button></form></section>
<div class="report-metrics"><div class="card"><span>Filtered sales</span><strong><?=money(scalar("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE date(sale_date) BETWEEN ".db()->quote($from)." AND ".db()->quote($to)))?></strong><small>Gross sales in selected period</small></div><div class="card"><span>Payments received</span><strong><?=money(scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE direction='in' AND date(payment_date) BETWEEN ".db()->quote($from)." AND ".db()->quote($to)))?></strong><small>Cash and bank receipts</small></div><div class="card"><span>New bookings</span><strong><?=number_format(scalar("SELECT COUNT(*) FROM bookings WHERE date(booking_date) BETWEEN ".db()->quote($from)." AND ".db()->quote($to)))?></strong><small>Bookings created</small></div><div class="card"><span>Open bills</span><strong><?=number_format(scalar("SELECT COUNT(*) FROM pending_bills WHERE is_paid=0"))?></strong><small>Require follow-up</small></div></div>
<section class="report-grid"><?php foreach([['v_profit_by_material','Profit by material','material_name','profit'],['v_profit_by_client','Profit by client','client_name','profit'],['v_supplier_balances','Supplier balances','name','balance'],['v_pending_bookings_followup','Booking follow-ups','client_name','outstanding']] as [$vt,$title,$name,$amount]):?><section class="panel"><div class="panel-head"><div><div class="eyebrow">LIVE DATABASE VIEW</div><h3><?=h($title)?></h3></div><span class="muted">Top 8</span></div><div class="table-wrap"><table><thead><tr><th><?=label($name)?></th><th class="right"><?=label($amount)?></th></tr></thead><tbody><?php $dateCol=in_array($vt,['v_profit_by_material','v_profit_by_client'])?'sale_date':($vt==='v_pending_bookings_followup'?'next_followup_date':null); $sql='SELECT * FROM "'.$vt.'"'; $params=[]; if($dateCol){$sql.=' WHERE date("'.$dateCol.'") BETWEEN ? AND ?';$params=[$from,$to];} $sql.=' LIMIT 8'; $st=db()->prepare($sql);$st->execute($params); foreach($st as $r):?><tr><td><?=h($r[$name]??'—')?></td><td class="right"><?=is_numeric($r[$amount]??null)?money($r[$amount]):h($r[$amount]??'—')?></td></tr><?php endforeach;?></tbody></table></div></section><?php endforeach;?></section>
<?php else: $schema=cols($table); $byName=[]; foreach($schema as $sc) $byName[$sc['name']]=$sc; $edit=(int)($_GET['edit']??0); $record=$edit?db()->query('SELECT * FROM "'.$table.'" WHERE id='.$edit)->fetch():[]; $search=trim($_GET['q']??''); $data=rows_for($table,$search); $visible=list_fields($table); ?>
<div class="toolbar"><form><input type="hidden" name="module" value="<?=h($key)?>"><input class="search" name="q" value="<?=h($search)?>" placeholder="Search <?=h(strtolower($modules[$key]['label']))?>…"><button class="button">Search</button></form><?php if(!in_array($table,['audit_log'])):?><a class="button primary" href="?module=<?=$key?>&new=1">＋ Add <?=h(rtrim($modules[$key]['label'],'s'))?></a><?php endif;?></div>
<?php if(isset($_GET['new'])||$edit):?><section class="panel form-panel"><div class="panel-head"><div><div class="eyebrow"><?= $edit?'EDIT RECORD':'NEW RECORD'?></div><h3><?= $edit?'Update':'Create'?> <?=h(rtrim($modules[$key]['label'],'s'))?></h3><p class="helper">Enter the operational details below. Totals and audit fields are maintained by the system.</p></div><a href="?module=<?=$key?>" class="close">×</a></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=$edit?>"><?php foreach(form_fields($table) as $fn) if(isset($byName[$fn])) echo render_field($byName[$fn],$record[$fn]??''); ?><?php if(!$edit && in_array($table,['sales','purchases'],true)):?><div class="line-item"><div class="eyebrow">FIRST LINE ITEM</div><h4>Add material to this <?= $table==='sales'?'sale':'purchase'?></h4><div class="line-grid"><label>Material<select name="line_material_id" required><option value="">Select material…</option><?php foreach(db()->query("SELECT id,name,unit FROM materials WHERE active=1 ORDER BY name") as $mat):?><option value="<?=$mat['id']?>"><?=h($mat['name'])?> (<?=h($mat['unit'])?>)</option><?php endforeach;?></select></label><label>Quantity<input type="number" step="0.01" min="0.01" name="line_qty" required></label><label>Rate<input type="number" step="0.01" min="0" name="line_rate" required></label></div></div><?php endif;?><?php if($table==='users'):?><label>New password<input type="password" name="new_password" placeholder="Leave blank to keep current"></label><?php endif;?><div class="form-actions"><a class="button" href="?module=<?=$key?>">Cancel</a><button class="button primary">Save changes</button></div></form></section><?php endif;?>
<section class="panel"><div class="panel-head"><div><div class="eyebrow"><?=count($data)?> RECORDS SHOWN</div><h3><?=h($modules[$key]['label'])?></h3></div><span class="muted">Live SQLite data · showing latest 100</span></div><div class="table-wrap"><table><thead><tr><?php foreach($visible as $n) { if(isset($byName[$n])) { ?><th><?=h(label($n))?></th><?php } } ?><th></th></tr></thead><tbody><?php foreach($data as $r):?><tr><?php foreach($visible as $n) { if(isset($byName[$n])) { ?><td><?=is_numeric($r[$n]??null)&&($n==='amount'||str_contains($n,'total')||str_contains($n,'balance')||str_contains($n,'subtotal')||str_contains($n,'rate'))?money($r[$n]):h(mb_strimwidth((string)($r[$n]??'—'),0,34,'…'))?></td><?php } } ?><td class="actions"><?php if($table!=='audit_log'):?><a href="?module=<?=$key?>&edit=<?=$r['id']?>">Edit</a><?php endif;?><?php if(!in_array($table,['audit_log','users','accounts'])):?><form method="post" onsubmit="return confirm('Delete this record?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button>Delete</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?></main></body></html>