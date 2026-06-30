<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

date_default_timezone_set('Asia/Tehran');

$PAID = "Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold','removeTime','removevolume')";

$jMonthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function fin_fmt(int $v): string {
    if ($v >= 1_000_000) return number_format($v / 1_000_000, 1) . '<small> م&nbsp;ت</small>';
    if ($v >= 1_000)     return number_format((int)($v / 1_000)) . '<small> ک&nbsp;ت</small>';
    return number_format($v) . '<small> ت</small>';
}
function fin_fmts(int $v): string {
    if ($v >= 1_000_000) return number_format($v / 1_000_000, 1) . ' م ت';
    if ($v >= 1_000)     return number_format((int)($v / 1_000)) . ' ک ت';
    return number_format($v) . ' ت';
}

// ── Find start of current Jalali month as Unix timestamp ─────────────────
$now         = time();
$thisMonthKey = jdate('Y/m', $now);
$probe       = $now;
for ($i = 1; $i <= 45; $i++) {
    if (jdate('Y/m', $probe - 86400) !== $thisMonthKey) break;
    $probe -= 86400;
}
$monthStartTs = mktime(0, 0, 0, (int)date('n', $probe), (int)date('j', $probe), (int)date('Y', $probe));
$lastMonthKey = jdate('Y/m', $monthStartTs - 86400);

// ── Load all paid invoices from last 24 months ───────────────────────────
$monthlyData = [];
try {
    $rows = db_fetchAll($pdo,
        "SELECT time_sell, price_product FROM invoice
         WHERE $PAID AND time_sell > 0
         ORDER BY time_sell ASC"
    );
    foreach ($rows as $row) {
        $ts = (int)$row['time_sell'];
        if ($ts < 1_000_000) continue;
        $key = jdate('Y/m', $ts);
        $monthlyData[$key]['rev']   = ($monthlyData[$key]['rev']   ?? 0) + (int)$row['price_product'];
        $monthlyData[$key]['count'] = ($monthlyData[$key]['count'] ?? 0) + 1;
    }
} catch (Exception $e) {}
ksort($monthlyData);

// ── Summary numbers ──────────────────────────────────────────────────────
$thisRev  = $monthlyData[$thisMonthKey]['rev']   ?? 0;
$thisCnt  = $monthlyData[$thisMonthKey]['count'] ?? 0;
$lastRev  = $monthlyData[$lastMonthKey]['rev']   ?? 0;
$lastCnt  = $monthlyData[$lastMonthKey]['count'] ?? 0;

$thisYear = jdate('Y');
$yearRev  = 0; $yearCnt = 0;
foreach ($monthlyData as $k => $d) {
    if (substr($k, 0, 4) === $thisYear) { $yearRev += $d['rev']; $yearCnt += $d['count']; }
}

$allTimeRev = 0; $allTimeCnt = 0;
try {
    $allTimeRev = (int)db_query($pdo, "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE $PAID")->fetchColumn();
    $allTimeCnt = (int)db_query($pdo, "SELECT COUNT(*) FROM invoice WHERE $PAID")->fetchColumn();
} catch (Exception $e) {}

$growth = ($lastRev > 0) ? round(($thisRev - $lastRev) / $lastRev * 100, 1) : null;

// ── Last 12 months for chart ─────────────────────────────────────────────
$last12  = array_slice($monthlyData, -12, 12, true);
$maxRev  = max(array_merge(array_column($last12, 'rev'), [1]));

// ── Daily breakdown for current month ────────────────────────────────────
$dailyData = [];
try {
    $rows2 = db_fetchAll($pdo,
        "SELECT time_sell, price_product FROM invoice
         WHERE $PAID AND time_sell >= ?",
        [$monthStartTs]
    );
    foreach ($rows2 as $row) {
        $ts = (int)$row['time_sell'];
        if ($ts <= 0) continue;
        $d = (int)jdate('j', $ts);
        $dailyData[$d] = ($dailyData[$d] ?? 0) + (int)$row['price_product'];
    }
} catch (Exception $e) {}
ksort($dailyData);
$maxDaily = max(array_merge(array_values($dailyData), [1]));

// ── Top products this month ───────────────────────────────────────────────
$topProducts = [];
try {
    $topProducts = db_fetchAll($pdo,
        "SELECT name_product, COUNT(*) as cnt, SUM(price_product) as rev
         FROM invoice WHERE $PAID AND time_sell >= ?
         GROUP BY name_product ORDER BY rev DESC LIMIT 8",
        [$monthStartTs]
    );
} catch (Exception $e) {}

// ── Payment methods breakdown ─────────────────────────────────────────────
$byMethod   = [];
$methodTotal = 0;
try {
    $byMethod = db_fetchAll($pdo,
        "SELECT Payment_Method, COUNT(*) as cnt, SUM(price) as rev
         FROM Payment_report WHERE payment_Status = 'paid'
         GROUP BY Payment_Method ORDER BY rev DESC"
    );
    foreach ($byMethod as $m) $methodTotal += (int)$m['rev'];
} catch (Exception $e) {}

$methodLabels = [
    'cart to cart'         => 'کارت به کارت',
    'low balance by admin' => 'کسر توسط ادمین',
    'add balance by admin' => 'افزایش توسط ادمین',
    'Currency Rial 1'      => 'درگاه ریالی ۱',
    'Currency Rial tow'    => 'درگاه ریالی ۲',
    'Currency Rial 3'      => 'درگاه ریالی ۳',
    'aqayepardakht'        => 'آقای پرداخت',
    'zarinpal'             => 'زرین‌پال',
    'plisio'               => 'Plisio',
    'arze digital offline' => 'ارز دیجیتال',
    'Star Telegram'        => 'تلگرام استار',
    'nowpayment'           => 'NowPayment',
];

// ── Month-over-month table (last 24 months newest first) ──────────────────
$tableMonths = array_reverse(array_slice($monthlyData, -24, 24, true), true);
$tableKeys   = array_keys($tableMonths);

$pageTitle    = 'گزارش مالی';
$activeNav    = 'finance';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<style>
.fin-grid{display:grid;gap:16px;grid-template-columns:repeat(4,1fr)}
.fin-grid.two{grid-template-columns:repeat(2,1fr)}
@media(max-width:900px){.fin-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.fin-grid{grid-template-columns:1fr}.fin-grid.two{grid-template-columns:1fr}}
.fin-stat{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;display:flex;flex-direction:column;gap:6px;transition:box-shadow .2s}
.fin-stat:hover{box-shadow:0 4px 24px rgba(0,0,0,.18)}
.fin-stat-label{font-size:.72rem;color:var(--mute);font-weight:600;letter-spacing:.03em;text-transform:uppercase}
.fin-stat-val{font-size:1.7rem;font-weight:800;color:var(--text);letter-spacing:-.04em;line-height:1.1}
.fin-stat-val small{font-size:.85rem;font-weight:500;color:var(--mute)}
.fin-stat-meta{font-size:.73rem;color:var(--dim);margin-top:2px}
.up{color:var(--ok);font-weight:700}
.dn2{color:var(--no);font-weight:700}
.month-bar-wrap{display:flex;align-items:flex-end;gap:6px;height:100px;padding-bottom:2px}
.month-bar{flex:1;border-radius:5px 5px 0 0;min-width:0;position:relative;transition:height .4s cubic-bezier(.4,0,.2,1);cursor:default}
.month-bar:hover .month-bar-tip{opacity:1;transform:translateY(-4px)}
.month-bar-tip{position:absolute;top:-34px;left:50%;transform:translateX(-50%);background:var(--sf3);border:1px solid var(--bd);border-radius:7px;padding:3px 7px;font-size:.62rem;color:var(--text);white-space:nowrap;opacity:0;transition:opacity .15s,transform .15s;pointer-events:none;z-index:10}
.month-label-row{display:flex;gap:6px;margin-top:8px;border-top:1px solid var(--bd);padding-top:8px}
.month-label{flex:1;text-align:center;font-size:.58rem;color:var(--dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.month-label.cur{color:var(--ac);font-weight:700}
.day-bar-wrap{display:flex;align-items:flex-end;gap:3px;height:60px}
.day-bar{flex:1;min-width:2px;border-radius:3px 3px 0 0;background:var(--ac);opacity:.7;position:relative;transition:opacity .2s,height .35s}
.day-bar:hover{opacity:1}
.tbl-finance td:first-child{font-weight:700;color:var(--text)}
.tbl-finance .cur-month td{background:color-mix(in srgb,var(--ac) 7%,transparent)}
.method-bar{height:6px;border-radius:3px;background:var(--ac);margin-top:4px;transition:width .5s}
.pct-label{font-size:.65rem;color:var(--mute)}
</style>

<!-- Page title -->
<div class="welcome-bar fade-up" style="margin-bottom:20px">
  <div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px">
      <?= icon('trend', 16) ?>&nbsp;گزارش مالی جامع
    </div>
    <div style="font-size:.75rem;color:var(--mute);margin-top:3px">
      سال شمسی <?= $thisYear ?> &nbsp;·&nbsp; <?= jdate('Y/m/d') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <span class="tag tag-info">درآمد ماهانه</span>
    <?php if ($growth !== null): ?>
      <span class="tag <?= $growth >= 0 ? 'tag-ok' : 'tag-no' ?>">
        <?= $growth >= 0 ? '↑' : '↓' ?> <?= abs($growth) ?>٪ نسبت به ماه قبل
      </span>
    <?php endif; ?>
  </div>
</div>

<!-- ══ Row 1: Summary Stats ══════════════════════════════════════════════ -->
<div class="fin-grid fade-up" style="margin-bottom:16px">

  <div class="fin-stat" style="border-color:color-mix(in srgb,var(--ac) 35%,var(--bd))">
    <div class="fin-stat-label"><?= icon('chart', 12) ?>&nbsp;ماه جاری</div>
    <div class="fin-stat-val"><?= fin_fmt($thisRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($thisCnt) ?> فاکتور
      <?php if ($thisCnt > 0): ?>
        &nbsp;·&nbsp; میانگین <?= fin_fmts((int)($thisRev / $thisCnt)) ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('chart', 12) ?>&nbsp;ماه گذشته</div>
    <div class="fin-stat-val"><?= fin_fmt($lastRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($lastCnt) ?> فاکتور
      <?php if ($growth !== null): ?>
        &nbsp;·&nbsp; <span class="<?= $growth >= 0 ? 'up' : 'dn2' ?>"><?= $growth >= 0 ? '+' : '' ?><?= $growth ?>٪</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="fin-stat" style="border-color:color-mix(in srgb,var(--ok) 30%,var(--bd))">
    <div class="fin-stat-label"><?= icon('wallet', 12) ?>&nbsp;سال <?= $thisYear ?></div>
    <div class="fin-stat-val"><?= fin_fmt($yearRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($yearCnt) ?> فاکتور
      &nbsp;·&nbsp; میانگین ماهانه <?= fin_fmts($yearCnt > 0 ? (int)($yearRev / max(1, (int)jdate('m'))) : 0) ?>
    </div>
  </div>

  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('invoice', 12) ?>&nbsp;کل درآمد</div>
    <div class="fin-stat-val"><?= fin_fmt($allTimeRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($allTimeCnt) ?> فاکتور ثبت‌شده
    </div>
  </div>

</div>

<!-- ══ Row 2: 12-Month Chart + Daily Chart ══════════════════════════════ -->
<div class="fin-grid two fade-up" style="margin-bottom:16px">

  <!-- 12-month bar chart -->
  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('chart', 15) ?>&nbsp;روند درآمد ۱۲ ماه اخیر</div>
        <div class="card-subtitle">مجموع: <?= fin_fmts(array_sum(array_column($last12, 'rev'))) ?></div>
      </div>
      <span class="tag tag-info">ماهانه</span>
    </div>
    <div class="card-body" style="padding-top:16px">
      <div class="month-bar-wrap">
        <?php foreach ($last12 as $key => $d):
          $pct   = $maxRev > 0 ? ($d['rev'] / $maxRev * 100) : 0;
          $barH  = max(4, (int)round($pct));
          $isCur = ($key === $thisMonthKey);
          [$jy, $jm] = explode('/', $key);
        ?>
        <div class="month-bar" style="
          height:<?= $barH ?>%;
          background:<?= $isCur ? 'var(--ac)' : 'var(--sf3)' ?>;
          box-shadow:<?= $isCur ? '0 0 14px var(--acg)' : 'none' ?>;
        ">
          <div class="month-bar-tip">
            <?= $jMonthNames[(int)$jm] ?><br>
            <strong><?= fin_fmts($d['rev']) ?></strong><br>
            <?= $d['count'] ?> فاکتور
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="month-label-row">
        <?php foreach ($last12 as $key => $d):
          [,$jm] = explode('/', $key);
          $isCur = ($key === $thisMonthKey);
        ?>
          <div class="month-label <?= $isCur ? 'cur' : '' ?>"><?= $jMonthNames[(int)$jm] ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Daily chart for current month -->
  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('chart', 15) ?>&nbsp;درآمد روزانه — <?= $jMonthNames[(int)jdate('m')] ?></div>
        <div class="card-subtitle">
          مجموع ماه: <?= fin_fmts($thisRev) ?>
          <?php if ($thisCnt > 0): ?>
            &nbsp;·&nbsp; <?= number_format($thisCnt) ?> فاکتور
          <?php endif; ?>
        </div>
      </div>
      <span class="tag tag-ok">ماه جاری</span>
    </div>
    <div class="card-body" style="padding-top:16px">
      <?php if (empty($dailyData)): ?>
        <div style="text-align:center;color:var(--dim);padding:20px 0;font-size:.8rem">هنوز فروشی در این ماه ثبت نشده</div>
      <?php else: ?>
        <div class="day-bar-wrap">
          <?php
          $todayJ = (int)jdate('j');
          $curM   = (int)jdate('m');
          $daysInMonth = ($curM <= 6) ? 31 : (($curM <= 11) ? 30 : 29);
          for ($d = 1; $d <= $daysInMonth; $d++):
            $rev  = $dailyData[$d] ?? 0;
            $pct  = $maxDaily > 0 ? ($rev / $maxDaily * 100) : 0;
            $barH = $rev > 0 ? max(5, (int)round($pct)) : 1;
          ?>
            <div class="day-bar" style="
              height:<?= $barH ?>%;
              opacity:<?= $d == $todayJ ? '1' : ($rev > 0 ? '.75' : '.15') ?>;
              background:<?= $d == $todayJ ? 'var(--ac)' : 'var(--ac)' ?>;
              box-shadow:<?= $d == $todayJ ? '0 0 8px var(--acg)' : 'none' ?>;
              position:relative;
            " title="روز <?= $d ?>: <?= fin_fmts($rev) ?>"></div>
          <?php endfor; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:.62rem;color:var(--dim)">
          <span>۱ <?= $jMonthNames[(int)jdate('m')] ?></span>
          <span>امروز: <?= fin_fmts($dailyData[$todayJ] ?? 0) ?></span>
          <span><?= $daysInMonth ?> <?= $jMonthNames[(int)jdate('m')] ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ══ Row 3: Top Products + Payment Methods ════════════════════════════ -->
<div class="fin-grid two fade-up" style="margin-bottom:16px">

  <!-- Top products this month -->
  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('package', 15) ?>&nbsp;پرفروش‌ترین محصولات ماه جاری</div>
        <div class="card-subtitle">بر اساس درآمد</div>
      </div>
    </div>
    <?php if (empty($topProducts)): ?>
      <div class="card-body" style="color:var(--dim);font-size:.8rem;text-align:center;padding:20px">فروشی ثبت نشده</div>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-md">
        <thead>
          <tr>
            <th>#</th>
            <th>نام محصول</th>
            <th style="text-align:left">تعداد</th>
            <th style="text-align:left">درآمد</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topProducts as $i => $p): ?>
          <tr>
            <td style="color:var(--mute);font-size:.72rem"><?= $i + 1 ?></td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($p['name_product'] ?? '—') ?>
            </td>
            <td style="text-align:left"><span class="tag tag-plain"><?= number_format((int)$p['cnt']) ?></span></td>
            <td style="text-align:left;font-weight:700;color:var(--ac)"><?= fin_fmts((int)$p['rev']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Payment methods breakdown -->
  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('card', 15) ?>&nbsp;روش‌های پرداخت</div>
        <div class="card-subtitle">کل دریافتی: <?= fin_fmts($methodTotal) ?></div>
      </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
      <?php if (empty($byMethod)): ?>
        <div style="color:var(--dim);font-size:.8rem;text-align:center;padding:10px">داده‌ای موجود نیست</div>
      <?php else: ?>
        <?php foreach ($byMethod as $m):
          $rev = (int)$m['rev'];
          $pct = $methodTotal > 0 ? round($rev / $methodTotal * 100, 1) : 0;
          $pm    = $m['Payment_Method'] ?? '';
          $label = $methodLabels[$pm] ?? htmlspecialchars($pm ?: '—');
        ?>
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
            <span style="font-size:.78rem;color:var(--text);font-weight:600"><?= $label ?></span>
            <div style="text-align:left">
              <span style="font-size:.78rem;font-weight:700;color:var(--ac)"><?= fin_fmts($rev) ?></span>
              <span class="pct-label">&nbsp;(<?= $pct ?>٪)</span>
            </div>
          </div>
          <div style="background:var(--sf3);border-radius:3px;height:6px;overflow:hidden">
            <div class="method-bar" style="width:<?= $pct ?>%"></div>
          </div>
          <div class="pct-label" style="margin-top:2px"><?= number_format((int)$m['cnt']) ?> تراکنش</div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ══ Row 4: Monthly Breakdown Table ═══════════════════════════════════ -->
<div class="card fade-up" style="margin-bottom:20px">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('invoice', 15) ?>&nbsp;جزئیات درآمد ماهانه</div>
      <div class="card-subtitle">تاریخچه <?= count($tableMonths) ?> ماه اخیر</div>
    </div>
    <span class="tag tag-plain">تقویم شمسی</span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md tbl-finance">
      <thead>
        <tr>
          <th>ماه</th>
          <th style="text-align:left">درآمد</th>
          <th style="text-align:left">فاکتور</th>
          <th style="text-align:left">میانگین فروش</th>
          <th style="text-align:left">رشد</th>
          <th style="text-align:left">سهم از امسال</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $prevRev  = null;
        $monthArr = array_values($tableMonths);
        $monthKeys = array_keys($tableMonths);
        foreach ($monthKeys as $idx => $key):
            $d       = $tableMonths[$key];
            $rev     = (int)$d['rev'];
            $cnt     = (int)$d['count'];
            $avg     = $cnt > 0 ? (int)($rev / $cnt) : 0;
            [$jy, $jm] = explode('/', $key);

            // Growth vs next row (which is previous month because reversed)
            $nextKey = $monthKeys[$idx + 1] ?? null;
            $nextRev = $nextKey ? (int)($tableMonths[$nextKey]['rev'] ?? 0) : null;
            $grw     = ($nextRev !== null && $nextRev > 0)
                       ? round(($rev - $nextRev) / $nextRev * 100, 1)
                       : null;

            // Share of this year
            $share = ($yearRev > 0 && substr($key, 0, 4) === $thisYear)
                     ? round($rev / $yearRev * 100, 1)
                     : null;

            $isCur  = ($key === $thisMonthKey);
            $isLast = ($key === $lastMonthKey);
        ?>
        <tr class="<?= $isCur ? 'cur-month' : '' ?>">
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <?php if ($isCur): ?>
                <span class="tag tag-ok" style="font-size:.58rem;padding:1px 5px">جاری</span>
              <?php elseif ($isLast): ?>
                <span class="tag tag-plain" style="font-size:.58rem;padding:1px 5px">قبلی</span>
              <?php endif; ?>
              <span><?= $jMonthNames[(int)$jm] ?> <?= $jy ?></span>
            </div>
          </td>
          <td style="text-align:left;font-weight:700;color:var(--ac)"><?= fin_fmts($rev) ?></td>
          <td style="text-align:left"><?= number_format($cnt) ?></td>
          <td style="text-align:left;color:var(--mute)"><?= $avg > 0 ? fin_fmts($avg) : '—' ?></td>
          <td style="text-align:left">
            <?php if ($grw !== null): ?>
              <span class="<?= $grw >= 0 ? 'up' : 'dn2' ?>"><?= $grw >= 0 ? '+' : '' ?><?= $grw ?>٪</span>
            <?php else: ?>
              <span style="color:var(--dim)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:left">
            <?php if ($share !== null): ?>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="flex:1;height:4px;background:var(--sf3);border-radius:2px;min-width:50px">
                  <div style="width:<?= $share ?>%;height:100%;background:var(--ac);border-radius:2px"></div>
                </div>
                <span style="font-size:.7rem;color:var(--mute)"><?= $share ?>٪</span>
              </div>
            <?php else: ?>
              <span style="color:var(--dim);font-size:.7rem">سال دیگر</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--sf2);font-weight:700">
          <td>جمع سال <?= $thisYear ?></td>
          <td style="text-align:left;color:var(--ac)"><?= fin_fmts($yearRev) ?></td>
          <td style="text-align:left"><?= number_format($yearCnt) ?></td>
          <td style="text-align:left;color:var(--mute)"><?= $yearCnt > 0 ? fin_fmts((int)($yearRev / $yearCnt)) : '—' ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
