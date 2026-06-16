<?php

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';

require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$title = 'XrechnungMega';

$statusMap = [];
if (defined('STATUS_FILE') && is_file(STATUS_FILE)) {
  $h = @fopen(STATUS_FILE, 'r');
  if ($h) {
    @flock($h, LOCK_SH);
    $c = stream_get_contents($h);
    @flock($h, LOCK_UN);
    @fclose($h);
    $a = json_decode((string)$c, true);
    if (is_array($a)) $statusMap = $a;
  }
}

$files = glob(OUTBOX_DIR . '/*.xml') ?: [];
$rechnungen = [];
foreach ($files as $p) {
  [$id,$iso,$emp,$sum,$typ] = inv_meta($p);
  $filename  = basename($p);
  $displayId = $id !== '' ? $id : strip_xml_ext($filename);
  $rechnungen[] = [
    'path'=>$p,
    'name'=>$filename,
    'mtime'=>filemtime($p),
    'id'=>$displayId,
    'iso'=>$iso,
    'emp'=>$emp,
    'sum'=>$sum,
    'typ'=>$typ
  ];
}
usort($rechnungen, fn($a,$b)=>strnatcmp((string)$a['id'], (string)$b['id']));


function strip_xml_ext(string $s): string { return preg_replace('/\.xml$/i','',$s); }

function inv_meta(string $path): array {
  $typeMap = [
    '380'=>'Rechnung',
    '326'=>'Teilrechnung',
    '384'=>'Korrigierte Rechnung',
    '381'=>'Gutschrift'
  ];

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  if (!$dom->load($path, LIBXML_NONET)) return ['','','','',''];

  $root = $dom->documentElement;
  $nsUri = $root ? (string)$root->namespaceURI : '';
  $local = $root ? (string)$root->localName : '';

  if ($local === 'CrossIndustryInvoice' || $nsUri === 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100') {
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('rsm','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xp->registerNamespace('udt','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
    $s=function($q,$c=null)use($xp){return trim($xp->evaluate('string('.$q.')',$c));};

    $id  = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
    $d   = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
    $iso = (preg_match('/^\d{8}$/',$d)) ? (substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2)) : $d;
    $emp = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name');
    $sum = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount');
    $tc  = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');

    $typ = $typeMap[$tc] ?? ($tc !== '' ? $tc : '');
    return [$id,$iso,$emp,$sum,$typ];
  }

  $xp = new DOMXPath($dom);
  $xp->registerNamespace('inv','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

  $id   = trim((string)$xp->evaluate('string(/inv:Invoice/cbc:ID)'));
  $iso  = trim((string)$xp->evaluate('string(/inv:Invoice/cbc:IssueDate)'));
  $name = trim((string)$xp->evaluate('string(/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name)'));
  $sum  = trim((string)$xp->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'));
  $tc   = trim((string)$xp->evaluate('string(/inv:Invoice/cbc:InvoiceTypeCode)'));

  $typ = $typeMap[$tc] ?? ($tc !== '' ? $tc : '');
  return [$id,$iso,$name,$sum,$typ];
}


function fmtDate(?string $iso): string {
  if (!$iso) return '';
  $d = DateTime::createFromFormat('Y-m-d', $iso);
  return $d ? $d->format('d.m.Y') : $iso;
}
function fmtEuro(?string $raw): string {
  if ($raw === null || $raw === '') return '';
  $num = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $raw));
  $f   = (float)$num;
  return number_format($f, 2, ',', '').' €';
}
function print_invoice_rows(array $rechnungen, array $STATUS_OPTIONS, array $statusMap): void {
  foreach ($rechnungen as $r) {
    $filename  = (string)($r['name'] ?? '');
    $displayId = (string)($r['id'] ?? strip_xml_ext($filename));
    $key       = strip_xml_ext($displayId);

    $dateDisp = (($r['iso'] ?? '') !== '') ? fmtDate((string)$r['iso']) : date('d.m.Y', (int)($r['mtime'] ?? time()));
    $sumDisp  = (($r['sum'] ?? '') !== '') ? fmtEuro((string)$r['sum']) : '';
    $typDisp  = (string)($r['typ'] ?? '');
    $emp      = (string)($r['emp'] ?? '');

    $statusVal = (isset($statusMap[$key]) && is_string($statusMap[$key]) && $statusMap[$key] !== '') ? $statusMap[$key] : 'Offen';

    $fnEsc      = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
    $displayEsc = htmlspecialchars($displayId, ENT_QUOTES, 'UTF-8');
    $dateEsc    = htmlspecialchars($dateDisp, ENT_QUOTES, 'UTF-8');
    $typEsc     = htmlspecialchars($typDisp, ENT_QUOTES, 'UTF-8');
    $empEsc     = htmlspecialchars($emp, ENT_QUOTES, 'UTF-8');
    $sumEsc     = htmlspecialchars($sumDisp, ENT_QUOTES, 'UTF-8');
    $keyEsc     = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

    echo "<tr data-file=\"{$fnEsc}\" onclick=\"loadXML('{$fnEsc}')\" style=\"cursor:pointer\">";
    echo "<td>{$displayEsc}</td>";
    echo "<td>{$dateEsc}</td>";
    echo "<td>{$typEsc}</td>";
    echo "<td>{$empEsc}</td>";
    echo "<td>{$sumEsc}</td>";
    echo "<td>";
    echo "<select class=\"statusSel\" onchange=\"updateStatus('{$keyEsc}', this.value)\" onclick=\"event.stopPropagation()\">";
    foreach ($STATUS_OPTIONS as $opt) {
      $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
      $sel = ($opt === $statusVal) ? ' selected' : '';
      echo "<option value=\"{$optEsc}\"{$sel}>{$optEsc}</option>";
    }
    echo "</select>";
    echo "</td>";
    echo "</tr>";
  }
}

$STATUS_OPTIONS = ['Offen','Erinnerung gesendet','Bezahlt','Problem','Entwurf'];
if (isset($_GET['list'])) {
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-store');
  print_invoice_rows($rechnungen, $STATUS_OPTIONS, $statusMap);
  exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__."/style.css"); ?>">
<?php if (defined('XR_MODE') && XR_MODE === 'platform'): ?>
<link rel="stylesheet" href="/app/assets/topbar.css">
<?php endif; ?>
<script>window.CSRF="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8'); ?>";</script>
</head>
<body>
<?php if (defined('XR_MODE') && XR_MODE === 'platform' && function_exists('app_topbar_html')) echo app_topbar_html('rechnungen'); ?>
<main class="page-main">

<div class="noprint" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.25rem;">
  <h1 style="margin:0;">Rechnungsübersicht</h1>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <a href="einstellungen.php" style="font-size:.875rem;color:#6b7280;text-decoration:none;padding:.4rem .9rem;border:1px solid #d1d5db;border-radius:6px;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">⚙ Einstellungen</a>
    <?php
      $xrPlatform = defined('XR_MODE') && XR_MODE === 'platform';
      // API-Button: Standalone immer; Plattform nur, wenn der Account die API nutzen darf (Mega/Admin)
      $xrShowApi = !$xrPlatform || (function_exists('plan_allows_api') && function_exists('current_user') && plan_allows_api(current_user()));
    ?>
    <?php if ($xrShowApi): ?>
    <a href="apikeys.php" style="font-size:.875rem;color:#6b7280;text-decoration:none;padding:.4rem .9rem;border:1px solid #d1d5db;border-radius:6px;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">🔑 API</a>
    <?php endif; ?>
    <?php if (!$xrPlatform): ?>
    <a href="change_credentials.php" style="font-size:.875rem;color:#6b7280;text-decoration:none;padding:.4rem .9rem;border:1px solid #d1d5db;border-radius:6px;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">Zugangsdaten</a>
    <a href="logout.php" style="font-size:.875rem;color:#6b7280;text-decoration:none;padding:.4rem .9rem;border:1px solid #d1d5db;border-radius:6px;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">Abmelden</a>
    <?php endif; ?>
  </div>
</div>
<input id="invoice-filter" class="noprint" type="text" placeholder="Filtern… (alle Spalten)" autocomplete="off" style="width:200px;max-width:100%;box-sizing:border-box;margin:10px 0 12px;border:1px solid #ccc;padding:8px">

<div class="table-scroll noprint" role="region" aria-label="Rechnungsübersicht, horizontal scrollbar" tabindex="0">
<table id="invoice-table" class="noprint">
  <thead>
    <tr>
      <th data-col="0" class="th-sort">Rechnungsnummer</th>
      <th data-col="1" class="th-sort">Datum</th>
      <th data-col="2" class="th-sort">Typ</th>
      <th data-col="3" class="th-sort">Empfänger</th>
      <th data-col="4" class="th-sort">Betrag</th>
      <th data-col="5" class="th-sort">Status</th>
    </tr>
  </thead>
<tbody id="invoice-list">
<?php print_invoice_rows($rechnungen, $STATUS_OPTIONS, $statusMap); ?>
</tbody>

</table>
</div>

<div class="toolbar noprint">
  <button type="button" class="btn-add" onclick="loadTemplate()">+</button>
</div>


<div id="popup-overlay" class="popup-overlay">
  <div class="popup">
    <button class="close" onclick="closePopup()">x</button>
    <div id="popup-content"></div>
  </div>
</div>
<div id="modal-overlay" class="popup-overlay">
  <div class="popup modal-popup">
    <div id="modal-message" class="modal-message"></div>
    <div class="modal-actions">
      <button type="button" id="modal-no">Nein</button>
      <button type="button" id="modal-yes">Ja</button>
    </div>
  </div>
</div>
<div id="pdfpick-overlay" class="popup-overlay">
  <div class="popup modal-popup">
    <div class="modal-message">PDF auswählen</div>
    <div class="modal-actions">
      <button type="button" id="pdfpick-fx">PDF ZUGFeRD</button>
      <button type="button" id="pdfpick-xr">PDF XRechnung</button>
    </div>
  </div>
</div>


<script>
function euroParse(v){
  let s = String(v ?? '').trim();
  if(!s) return 0;
  s = s.replace(/[^\d,.\-]/g,'').replace(',', '.');
  const n = parseFloat(s);
  return Number.isFinite(n) ? n : 0;
}

function euroFmt(n){
  n = Number.isFinite(n) ? n : 0;
  return n.toFixed(2).replace('.', ',');
}

const INVOICE_TABLE_STATE = window.INVOICE_TABLE_STATE || (window.INVOICE_TABLE_STATE = {q:'', sortIdx:0, sortDir:1});

function parseDateToTS(s){
  s = String(s ?? '').trim();
  if(!s) return 0;
  let m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if(m){
    const dd = Number(m[1]), mm = Number(m[2]), yy = Number(m[3]);
    return Date.UTC(yy, mm-1, dd) || 0;
  }
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(m){
    const yy = Number(m[1]), mm = Number(m[2]), dd = Number(m[3]);
    return Date.UTC(yy, mm-1, dd) || 0;
  }
  m = s.match(/^(\d{4})(\d{2})(\d{2})$/);
  if(m){
    const yy = Number(m[1]), mm = Number(m[2]), dd = Number(m[3]);
    return Date.UTC(yy, mm-1, dd) || 0;
  }
  const t = Date.parse(s);
  return Number.isFinite(t) ? t : 0;
}

function getCellText(row, idx){
  const td = row && row.children ? row.children[idx] : null;
  return td ? String(td.textContent ?? '').trim() : '';
}

function rowMatchesFilter(row, q){
  if(!q) return true;
  const hay = String(row.textContent ?? '').toLowerCase();
  return hay.includes(q);
}

function applyInvoiceFilter(){
  const input = document.getElementById('invoice-filter');
  const tbody = document.getElementById('invoice-list');
  if(!tbody) return;
  const q = String(input ? input.value : '').trim().toLowerCase();
  INVOICE_TABLE_STATE.q = q;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  for(const r of rows){
    r.style.display = rowMatchesFilter(r, q) ? '' : 'none';
  }
}

function sortInvoiceRows(idx, dir){
  const tbody = document.getElementById('invoice-list');
  if(!tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));
  const visible = rows.filter(r=>r.style.display !== 'none');
  const hidden  = rows.filter(r=>r.style.display === 'none');

  const cmpText = (a,b)=>a.localeCompare(b, 'de', {numeric:true, sensitivity:'base'});

  visible.sort((ra, rb)=>{
    let av = getCellText(ra, idx);
    let bv = getCellText(rb, idx);

    if(idx===1){
      const at = parseDateToTS(av);
      const bt = parseDateToTS(bv);
      return (at-bt) * dir;
    }
    if(idx===4){
      const an = euroParse(av);
      const bn = euroParse(bv);
      return (an-bn) * dir;
    }
    return cmpText(av, bv) * dir;
  });

  tbody.innerHTML = '';
  for(const r of visible) tbody.appendChild(r);
  for(const r of hidden) tbody.appendChild(r);
}

function updateSortIndicators(){
  const table = document.getElementById('invoice-table');
  if(!table) return;
  const ths = Array.from(table.querySelectorAll('thead th.th-sort'));
  for(const th of ths){
    const c = Number(th.getAttribute('data-col'));
    th.style.cursor = 'pointer';
    th.classList.add('pointer');
    th.textContent = th.textContent.replace(/\s*[▲▼]\s*$/,'');
    if(c === INVOICE_TABLE_STATE.sortIdx){
      th.textContent += INVOICE_TABLE_STATE.sortDir === 1 ? ' ▲' : ' ▼';
    }
  }
}

function applyInvoiceSort(){
  sortInvoiceRows(INVOICE_TABLE_STATE.sortIdx, INVOICE_TABLE_STATE.sortDir);
  updateSortIndicators();
}

function initInvoiceTableUX(){
  const input = document.getElementById('invoice-filter');
  const table = document.getElementById('invoice-table');
  const tbody = document.getElementById('invoice-list');
  if(!table || !tbody) return;

  if(input){
    input.value = INVOICE_TABLE_STATE.q || '';
    input.oninput = ()=>{ applyInvoiceFilter(); applyInvoiceSort(); };
  }

  const ths = Array.from(table.querySelectorAll('thead th.th-sort'));
  for(const th of ths){
    th.onclick = ()=>{
      const idx = Number(th.getAttribute('data-col'));
      if(Number.isNaN(idx)) return;
      if(INVOICE_TABLE_STATE.sortIdx === idx) INVOICE_TABLE_STATE.sortDir *= -1;
      else{ INVOICE_TABLE_STATE.sortIdx = idx; INVOICE_TABLE_STATE.sortDir = 1; }
      applyInvoiceSort();
    };
  }

  applyInvoiceFilter();
  applyInvoiceSort();



}
async function refreshInvoiceList(fileToFocus){
  const tbody = document.getElementById('invoice-list');
  if(!tbody) return;

  try{
    const r = await fetch('index.php?list=1', {cache:'no-store', headers:{'Accept':'text/html'}});
    const html = await r.text();
    tbody.innerHTML = html;
    initStatusColorHooks();
    applyStatusColors(tbody);
    initInvoiceTableUX();

    if(fileToFocus){
      const f = String(fileToFocus).split('/').pop().split('\\').pop();
      const esc = (window.CSS && CSS.escape) ? CSS.escape(f) : f.replace(/["\\]/g,'\\$&');
      const row = tbody.querySelector(`tr[data-file="${esc}"]`);
      if(row){
        row.classList.add('row-flash');
        row.scrollIntoView({block:'nearest'});
        setTimeout(()=>row.classList.remove('row-flash'), 900);
      }
    }
  }catch(e){
    console.error(e);
  }
}

const UI_MODAL = (()=>{
  const overlay = document.getElementById('modal-overlay');
  const msgEl = document.getElementById('modal-message');
  const yesBtn = document.getElementById('modal-yes');
  const noBtn  = document.getElementById('modal-no');
  let resolver = null;

  function hide(){
    overlay.style.display='none';
    msgEl.textContent='';
    yesBtn.onclick=null;
    noBtn.onclick=null;
    resolver=null;
    noBtn.style.display='';
  }

  overlay.addEventListener('click',(e)=>{
    if(e.target!==overlay) return;
    if(resolver){ const r=resolver; hide(); r(false); }
    else hide();
  });

  function show({message, yesText='OK', noText='Nein', showNo=false}){
    msgEl.textContent = String(message ?? '');
    yesBtn.textContent = yesText;
    noBtn.textContent  = noText;
    noBtn.style.display = showNo ? '' : 'none';
    overlay.style.display='flex';
    return new Promise(resolve=>{
      resolver = resolve;
      yesBtn.onclick = ()=>{ const r=resolver; hide(); r && r(true); };
      noBtn.onclick  = ()=>{ const r=resolver; hide(); r && r(false); };
    });
  }

  return {
    alert:  (message, okText='OK') => show({message, yesText: okText, showNo:false}),
    confirm:(message, yesText='Ja', noText='Nein') => show({message, yesText, noText, showNo:true})
  };
})();
const PDF_PICKER = (()=>{
  const overlay = document.getElementById('pdfpick-overlay');
  const fxBtn = document.getElementById('pdfpick-fx');
  const xrBtn = document.getElementById('pdfpick-xr');
  let resolver = null;

  function hide(){
    overlay.style.display='none';
    resolver=null;
  }

  overlay.addEventListener('click',(e)=>{
    if(e.target!==overlay) return;
    if(resolver){ const r=resolver; hide(); r(null); }
    else hide();
  });

  fxBtn.addEventListener('click',()=>{ if(!resolver) return; const r=resolver; hide(); r('fx'); });
  xrBtn.addEventListener('click',()=>{ if(!resolver) return; const r=resolver; hide(); r('xr'); });

  function choose(){
    overlay.style.display='flex';
    return new Promise(resolve=>{ resolver=resolve; });
  }

  return { choose };
})();

function recalcRow(tr){
  const qtyEl = tr.querySelector('input[name*="[menge]"]');
  const priceEl = tr.querySelector('input[name*="[einzelpreis]"]');
  const span = tr.querySelector('td:nth-child(5) span');
  if(!qtyEl || !priceEl || !span) return 0;

  const qty = euroParse(qtyEl.value);
  const price = euroParse(priceEl.value);
  const line = qty * price;

  span.textContent = `${euroFmt(line)} €`;
  return line;
}

function recalcInvoice(root){
  if(!root) return;

  const tbody = root.querySelector('.invoice-table tbody');
  if(!tbody) return;

  let net = 0;
  tbody.querySelectorAll('tr').forEach(tr => { net += recalcRow(tr); });

  const tax = net * 0.19;
  const gross = net + tax;

  const netEl = root.querySelector('input[name="zusammenfassung[nettobetrag]"]');
  const taxEl = root.querySelector('input[name="zusammenfassung[umsatzsteuer]"]');
  const topPayable = root.querySelector('#payableTop');
  const bottomPayable = root.querySelector('#payableBottom');

  if(netEl) netEl.value = `${euroFmt(net)} €`;
  if(taxEl) taxEl.value = `${euroFmt(tax)} €`;
  if(topPayable) topPayable.value = `${euroFmt(gross)} €`;
  if(bottomPayable) bottomPayable.value = `${euroFmt(gross)} €`;
}
function baseNameSafe(v){ return String(v??'').split('/').pop().split('\\').pop(); }
function sanitizeInvoiceNumber(v){ return String(v??'').replace(/[^a-zA-Z0-9_-]/g,'_').replace(/^[_-]+|[_-]+$/g,''); }

async function invoiceExists(rn){
  const url = `save.php?check=1&rn=${encodeURIComponent(rn)}&csrf=${encodeURIComponent(window.CSRF||'')}`;
  const r = await fetch(url, {headers:{'Accept':'application/json'}});
  const j = await r.json();
  return !!(j && j.ok && j.exists);
}

function setSaveMsg(root, on){
  const el = root.querySelector('#save-msg');
  if(!el) return;
  if(on){ el.style.display='block'; el.textContent='gespeichert'; }
  else{ el.style.display='none'; }
}
function setActionButtonsEnabled(root, enabled){
  const pdf = root.querySelector('#btnPdf');
  const del = root.querySelector('#btnDelete');
  if(pdf) pdf.disabled = !enabled;
  if(del) del.disabled = !enabled;
}
function updateXmlLink(root, filename){
  const a = root.querySelector('#btnXml');
  if(a) a.href = `download.php?file=${encodeURIComponent(filename)}`;
}
function updateHiddenFile(root, filename){
  const i = root.querySelector('input[name="file"]');
  if(i) i.value = filename;
}

function wireInvoiceForm(root){
  const form = root.querySelector('#invoiceForm');
  if(!form || form.dataset.wired==='1') return;
  form.dataset.wired='1';

  const onChange = ()=>setSaveMsg(root,false);
  form.addEventListener('input', onChange, {passive:true});
  form.addEventListener('change', onChange, {passive:true});

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    await saveInvoiceAsync(root);
  });
}

async function saveInvoiceAsync(root, opts){
  opts = opts || {};
  const form = root.querySelector('#invoiceForm');
  if(!form) return;

  const btn = root.querySelector('#btnSave');
  const rnInput = form.querySelector('input[name="details[rechnungsnummer]"]');
  const rn = sanitizeInvoiceNumber(rnInput ? rnInput.value : '');
  if(!rn){ UI_MODAL.alert('Rechnungsnummer fehlt oder ist ungültig.'); return; }

  const current = baseNameSafe(form.querySelector('input[name="file"]')?.value || '');
  const target = rn + '.xml';
  let force = !!opts.force;

  if(!force && target !== current){
    let exists=false;
    try{ exists = await invoiceExists(rn); }catch(e){ console.error(e); }
    if(exists){
      const ok = await UI_MODAL.confirm('Eine Rechnung mit dieser Nummer ist bereits vorhanden. Überschreiben?','Ja','Nein');
      if(!ok) return;
      force = true;
    }
  }

  const fd = new FormData(form);
  fd.append('ajax','1');
  if(force) fd.append('force','1');

  if(btn){ btn.disabled=true; btn.dataset._txt=btn.textContent; btn.textContent='Speichere…'; }

  try{
    const r = await fetch('save.php', {method:'POST', body: fd, headers:{'Accept':'application/json'}});
    const j = await r.json();

    if(!r.ok || !j || j.ok !== true){
      if(j && j.code === 'exists'){
        const ok = await UI_MODAL.confirm(j.msg || 'Eine Rechnung mit dieser Nummer ist bereits vorhanden. Überschreiben?','Ja','Nein');
        if(ok) return await saveInvoiceAsync(root, {force:true});
        return;
      }
      UI_MODAL.alert((j && j.msg) ? j.msg : 'Fehler beim Speichern.');
      return;
    }

    const newFile = String(j.file || target);
    updateHiddenFile(root, newFile);
    updateXmlLink(root, newFile);
    setActionButtonsEnabled(root, newFile.toLowerCase() !== 'vorlage.xml');
    setSaveMsg(root,true);
    refreshInvoiceList(newFile);

  }catch(e){
    console.error(e);
    UI_MODAL.alert('Netzwerkfehler beim Speichern.');
  }finally{
    if(btn){ btn.disabled=false; btn.textContent = btn.dataset._txt || 'Speichern'; }
  }
}

async function loadXML(filename){
  const r=await fetch(`read.php?file=${encodeURIComponent(filename)}`);
  const html=await r.text();
  const el=document.getElementById('popup-content');
  el.innerHTML=html;
  document.getElementById('popup-overlay').style.display='flex';
  wireInvoiceForm(el);
  recalcInvoice(el);
}

async function loadTemplate(){
  const r=await fetch('read.php?file=vorlage.xml');
  const html=await r.text();
  const el=document.getElementById('popup-content');
  el.innerHTML=html;
  document.getElementById('popup-overlay').style.display='flex';
  wireInvoiceForm(el);
  recalcInvoice(el);
}

async function openInvoicePdf(){
  const fileInput=document.querySelector('#popup-content input[name="file"]');
  if(!fileInput||!fileInput.value) return;
  const filename=baseNameSafe(fileInput.value);
  if(filename.toLowerCase()==='vorlage.xml'){ UI_MODAL.alert('Vorlage kann kein PDF. Erst speichern.'); return; }

  const choice = await PDF_PICKER.choose();
  if(choice==='fx') window.open(`pdf_generator_pferd.php?xml=${encodeURIComponent(filename)}`, '_blank', 'noopener');
  if(choice==='xr') window.open(`pdf_generator_x.php?xml=${encodeURIComponent(filename)}`, '_blank', 'noopener');
}


function closePopup(){
  document.getElementById('popup-overlay').style.display='none';
  document.getElementById('popup-content').innerHTML='';
}

function preparePrintView(){
  document.querySelectorAll('select.grau, select.plain-select').forEach(s=>{
    const txt = s.options[s.selectedIndex]?.text || '';
    s.setAttribute('data-print-value', txt);

    let span = s.nextElementSibling;
    if(!span || !span.classList.contains('print-select-value')){
      span = document.createElement('span');
      span.className = 'print-select-value';
      s.insertAdjacentElement('afterend', span);
    }
    span.textContent = txt;
  });
}

window.onbeforeprint=preparePrintView;

document.getElementById('popup-content').addEventListener('input', (e)=>{
  const t = e.target;
  if(!(t instanceof HTMLInputElement)) return;
  const n = t.name || '';
  if(!n.includes('[menge]') && !n.includes('[einzelpreis]')) return;
  const tr = t.closest('tr');
  if(tr) recalcRow(tr);
  recalcInvoice(document.getElementById('popup-content'));
}, {passive:true});

document.getElementById('popup-content').addEventListener('click', (e)=>{
  const t = e.target;
  if(!(t instanceof HTMLElement)) return;
  if(t.closest('button') && t.closest('button').getAttribute('type') === 'button') {
    queueMicrotask(()=>recalcInvoice(document.getElementById('popup-content')));
  }
  if(t.matches('p') && t.getAttribute('onclick') && t.getAttribute('onclick').includes('remove')) {
    queueMicrotask(()=>recalcInvoice(document.getElementById('popup-content')));
  }
});

async function deleteInvoice(){
  const fileInput=document.querySelector('#popup-content input[name="file"]');
  if(!fileInput||!fileInput.value) return;

  const filename=baseNameSafe(fileInput.value);
  if(filename.toLowerCase()==='vorlage.xml'){ UI_MODAL.alert('Vorlage wird nicht gelöscht.'); return; }

  const ok=await UI_MODAL.confirm('Eine Rechnung wirklich löschen?','Ja','Nein');
  if(!ok) return;

  try{
    const r=await fetch('delete.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},
      body:`file=${encodeURIComponent(filename)}&csrf=${encodeURIComponent(window.CSRF||'')}`
    });
    const j=await r.json();
    if(!r.ok || !j || j.ok!==true) throw new Error((j&&j.msg)||'Fehler beim Löschen.');

    await UI_MODAL.alert('Gelöscht.');
    closePopup();
    refreshInvoiceList();
  }catch(e){
    console.error(e);
    UI_MODAL.alert(e && e.message ? e.message : 'Fehler beim Löschen.');
  }
}



async function updateStatus(inv, status){
  try{
    const r=await fetch('status_update.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`csrf=${encodeURIComponent(window.CSRF)}&invoice=${encodeURIComponent(inv)}&status=${encodeURIComponent(status)}`
    });
    const j=await r.json();
    applyStatusColors(document.getElementById('invoice-list'));
    if(!j.ok){ UI_MODAL.alert(j.msg||'Fehler beim Aktualisieren des Status.'); }
  }catch(e){
    console.error(e);
    UI_MODAL.alert('Netzwerkfehler beim Aktualisieren des Status.');
  }
}


document.addEventListener('click',(e)=>{
  if(e.target && e.target.matches('select.statusSel')) e.stopPropagation();
});

const STATUS_COLORS = {
  'Offen': { bg:'#cceef0', fg:'#111827' },
  'Erinnerung gesendet': { bg:'#fde68a', fg:'#451a03' },
  'Bezahlt': { bg:'#bbf7d0', fg:'#052e16' },
  'Problem': { bg:'#fecaca', fg:'#450a0a' },
  'Entwurf': { bg:'#bfdbfe', fg:'#0f172a' }
};

function applyStatusColors(root){
  root = root || document;
  root.querySelectorAll('select.statusSel').forEach(sel=>{
    const status = sel.value || 'Offen';
    const td = sel.closest('td');
    if(!td) return;

    const c = STATUS_COLORS[status] || STATUS_COLORS['Offen'];
    td.style.backgroundColor = c.bg;
    td.style.color = c.fg;

    sel.style.backgroundColor = 'transparent';
    sel.style.color = 'inherit';
    sel.style.borderColor = 'rgba(0,0,0,.2)';
  });
}

function initStatusColorHooks(){
  const tbody = document.getElementById('invoice-list');
  if(!tbody || tbody.dataset.statusColorsWired==='1') return;
  tbody.dataset.statusColorsWired='1';

  tbody.addEventListener('change', (e)=>{
    const sel = e.target;
    if(!(sel instanceof HTMLSelectElement)) return;
    if(!sel.classList.contains('statusSel')) return;
    applyStatusColors(tbody);
    initInvoiceTableUX();
  }, {passive:true});
}

document.addEventListener('DOMContentLoaded', ()=>{
  initStatusColorHooks();
  applyStatusColors(document);
  initInvoiceTableUX();
});

</script>
</main>

</body>
</html>
