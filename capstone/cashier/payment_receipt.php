<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'cashier'){ header("Location: ../login.php"); exit; }

include '../config/db.php';
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$payment_id = (int)($_GET['payment_id'] ?? 0);
if($payment_id <= 0){
  die("Invalid payment.");
}

$companyName = "DE ORO HIYS GENERAL MERCHANDISE";
$companyAddr = "V Castro St, Carmen";
$vatTin      = "000-000-000-000";
$posSn       = "POS01-SN: XXXXXXXX";
$minNo       = "MIN#XXXXXXXXXXXX";

$discountType = strtoupper(trim((string)($_GET['discount'] ?? ''))); // PWD | SC | ''
$hasDiscount  = in_array($discountType, ['PWD','SC'], true);

$stmt = $conn->prepare("
  SELECT
    p.payment_id, p.sale_id, p.amount, p.method, p.status, p.paid_at, p.external_ref,
    s.total_amount, s.status AS sale_status, s.sale_date,
    c.first_name, c.last_name, c.phone, c.address,
    u.username AS cashier_name,
    ar.amount_paid, ar.balance, ar.due_date, ar.status AS ar_status
  FROM payments p
  JOIN sales s ON p.sale_id = s.sale_id
  LEFT JOIN account_receivable ar ON ar.sales_id = s.sale_id
  LEFT JOIN customers c ON s.customer_id = c.customer_id
  LEFT JOIN users u ON s.user_id = u.user_id
  WHERE p.payment_id = ?
  LIMIT 1
");
if(!$stmt){ die("Prepare failed: ".$conn->error); }

$stmt->bind_param("i", $payment_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$data){
  die("Payment not found.");
}

$customer = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
if($customer === '') $customer = 'Walk-in';

$remaining = (float)($data['balance'] ?? 0);
$arStatus  = strtolower(trim((string)($data['ar_status'] ?? ''))); // unpaid/partial/paid
$fullyPaid = ($arStatus === 'paid' || $remaining <= 0.00001);

$payLabel = $fullyPaid ? "PAYMENT RECEIPT (FULLY PAID)" : "PAYMENT RECEIPT (PARTIAL)";

$cashGiven = null;
$change = null;
if(isset($_GET['cash']) && is_numeric($_GET['cash'])){
  $cashGiven = (float)$_GET['cash'];
  $change = $cashGiven - (float)$data['amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment Receipt #<?= (int)$payment_id ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
  --paper-w: 420px;
  --muted: #6c757d;
}
body{ background:#f4f6f9; }
.paper{
  max-width: var(--paper-w);
  margin: 18px auto;
  background: #fff;
  padding: 18px 18px 14px;
  border-radius: 14px;
  box-shadow: 0 10px 24px rgba(0,0,0,.12);
}
.mono{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
               "Liberation Mono", "Courier New", monospace;
}
.small2{ font-size: 12px; line-height: 1.25; }
.small3{ font-size: 11px; line-height: 1.2; color: var(--muted); }
.hr-dash{ border-top:1px dashed #888; margin: 10px 0; }
.kv{
  display:flex; justify-content:space-between; gap:10px;
}
.kv span:first-child{ color: var(--muted); }
.badge-pill{
  display:inline-block; padding:4px 8px; border-radius:999px; font-size:11px;
}
.badge-paid{ background:#e7f7ee; color:#127a3c; border:1px solid #bfe7ce; }
.badge-partial{ background:#fff3cd; color:#7a5a00; border:1px solid #ffe69c; }
.totalBox{
  border:1px solid #e9ecef;
  border-radius: 12px;
  padding: 10px 12px;
  background: #fafbfc;
}
.totalDue{
  font-size: 22px;
  font-weight: 800;
  letter-spacing: .2px;
}
@media print{
  body{ background:#fff; }
  .no-print{ display:none !important; }
  .paper{ box-shadow:none; border-radius:0; margin:0; max-width:100%; }
}
</style>
</head>

<body>
<div class="paper mono">

  <!-- HEADER -->
  <div class="text-center">
    <div class="fw-bold"><?= h($companyName) ?></div>
    <div class="small3"><?= h($companyAddr) ?></div>
    <div class="small3">VAT REG TIN: <?= h($vatTin) ?></div>
    <div class="small3"><?= h($posSn) ?> • <?= h($minNo) ?></div>
    <div class="mt-2 fw-bold"><?= h($payLabel) ?></div>

    <div class="mt-2 d-flex justify-content-center gap-2 flex-wrap">
      <?php if($fullyPaid): ?>
        <span class="badge-pill badge-paid">FULLY PAID</span>
      <?php else: ?>
        <span class="badge-pill badge-partial">PARTIAL PAYMENT</span>
      <?php endif; ?>
      <?php if($hasDiscount): ?>
        <span class="badge-pill" style="background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;">
          <?= h($discountType) ?> DISCOUNT (ON SALE)
        </span>
      <?php endif; ?>
    </div>
  </div>

  <div class="hr-dash"></div>

  <!-- META -->
  <div class="small2">
    <div class="kv"><span>Payment #</span><span><b><?= (int)$data['payment_id'] ?></b></span></div>
    <div class="kv"><span>Sale #</span><span><b><?= (int)$data['sale_id'] ?></b></span></div>
    <div class="kv"><span>Sale Date</span><span><?= !empty($data['sale_date']) ? h(date("m/d/Y h:i A", strtotime($data['sale_date']))) : '—' ?></span></div>
    <div class="kv"><span>Payment Date</span><span><?= !empty($data['paid_at']) ? h(date("m/d/Y h:i A", strtotime($data['paid_at']))) : '—' ?></span></div>
    <div class="kv"><span>Cashier</span><span><?= h($data['cashier_name'] ?: 'Cashier') ?></span></div>
    <div class="kv"><span>Customer</span><span><?= h($customer) ?></span></div>
    <?php if(!empty($data['phone'])): ?>
      <div class="kv"><span>Phone</span><span><?= h($data['phone']) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if($hasDiscount): ?>
    <div class="small3 mt-2">
      <b>Note:</b> Discount applied on original sale: <b>LESS <?= h($discountType) ?> DISC</b>
    </div>
  <?php endif; ?>

  <div class="hr-dash"></div>

  <!-- PAYMENT SUMMARY -->
  <div class="totalBox">
    <div class="d-flex justify-content-between fw-bold">
      <span>PAYMENT AMOUNT</span>
      <span>₱<?= number_format((float)$data['amount'],2) ?></span>
    </div>

    <div class="small2 kv mt-1"><span>Method</span><span><?= h(strtoupper((string)($data['method'] ?? 'CASH'))) ?></span></div>

    <?php if(!empty($data['external_ref'])): ?>
      <div class="small2 kv"><span>Reference</span><span><?= h($data['external_ref']) ?></span></div>
    <?php endif; ?>

    <?php if($cashGiven !== null): ?>
      <div class="small2 kv"><span>Cash</span><span>₱<?= number_format($cashGiven,2) ?></span></div>
      <div class="small2 kv"><span>Change</span><span>₱<?= number_format(max(0,$change),2) ?></span></div>
    <?php endif; ?>
  </div>

  <div class="hr-dash"></div>

  <!-- BALANCE SUMMARY -->
  <div class="totalBox">
    <div class="small2 kv">
      <span>Total Utang (Invoice Total)</span>
      <span>₱<?= number_format((float)$data['total_amount'],2) ?></span>
    </div>
    <div class="small2 kv">
      <span>Total Paid So Far</span>
      <span>₱<?= number_format((float)($data['amount_paid'] ?? 0),2) ?></span>
    </div>
    <div class="hr-dash"></div>
    <div class="d-flex justify-content-between align-items-end">
      <div class="small2 text-muted">REMAINING BALANCE</div>
      <div class="totalDue">₱ <?= number_format(max(0,$remaining),2) ?></div>
    </div>

    <?php if(!empty($data['due_date'])): ?>
      <div class="small3 mt-2">Due: <?= h(date("m/d/Y", strtotime($data['due_date']))) ?></div>
    <?php endif; ?>
  </div>

  <div class="hr-dash"></div>

  <?php if($fullyPaid): ?>
    <div class="small2">
      <div class="fw-bold">STATUS: FULLY PAID</div>
      <div class="small3">Utang cleared. Thank you!</div>
    </div>
  <?php else: ?>
    <div class="small2">
      <div class="fw-bold">STATUS: PARTIAL PAYMENT</div>
      <div class="small3">Please pay remaining balance on/before due date.</div>
    </div>
  <?php endif; ?>

  <div class="text-center small3 mt-3">
    — END OF RECEIPT —
  </div>

  <div class="no-print d-grid gap-2 mt-3">
    <button class="btn btn-dark" onclick="window.print()">Print Receipt</button>
    <a class="btn btn-outline-secondary" href="payments.php">Back to Payments</a>
  </div>

</div>
</body>
</html>
