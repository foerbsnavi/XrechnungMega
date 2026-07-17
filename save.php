<?php

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';
require __DIR__ . '/config.php';

$acceptJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function j($http, $arr){
  http_response_code($http);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function fail($msg, $http=400, $code=null, $extra=[]){
  global $wantsJson;
  if($wantsJson) j($http, array_merge(['ok'=>false,'msg'=>$msg], $code?['code'=>$code]:[], $extra));
  http_response_code($http); exit($msg);
}
function normPhone($v){
  $v=trim((string)$v);
  $digits=preg_replace('/\D+/','',$v);
  if(strlen($digits)<3) return '000';
  return $digits;
}

// Escaped Textinhalt korrekt (auch &, <, >, ", '): NIE Text als 2. Argument von
// createElement uebergeben (das wird nicht escaped und bricht bei & ab), sondern
// per createTextNode anhaengen.
function el($dom, $name, $val = ''){
  // Fuer XML 1.0 unzulaessige Steuerzeichen entfernen -> Dokument bleibt immer wohlgeformt/valide
  $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$val);
  $e = $dom->createElement($name);
  $e->appendChild($dom->createTextNode($val));
  return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
  header('Content-Type: application/json; charset=UTF-8');
  $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!$token || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) j(403,['ok'=>false,'msg'=>'Ungültiges Token']);
  $raw = (string)($_GET['rn'] ?? '');
  $rn = trim(preg_replace('/[^a-zA-Z0-9_-]/','_', $raw), '_-');
  if ($rn === '') j(400,['ok'=>false,'msg'=>'Rechnungsnummer ungültig']);
  $path = OUTBOX_DIR . DIRECTORY_SEPARATOR . $rn . '.xml';
  j(200,['ok'=>true,'exists'=>is_file($path),'file'=>$rn.'.xml']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Ungültige Anfrage'); }
if (empty($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) { http_response_code(403); exit('Ungültiges Token'); }

$wantsJson = (!empty($_POST['ajax']) || $acceptJson);

$det  = (array)($_POST['details'] ?? []);
$abs  = (array)($_POST['absender'] ?? []);
$emp  = (array)($_POST['empfaenger'] ?? []);
$pos  = (array)($_POST['positionen'] ?? []);
$bank = (array)($_POST['bankverbindung'] ?? []);
$pay  = (array)($_POST['payment'] ?? []);
$sum  = (array)($_POST['zusammenfassung'] ?? []);

if (empty($det['rechnungsnummer'])) fail('Rechnungsnummer fehlt',400);

function cleanCurrency($v){
  $v=str_replace(['€',' ',"\xC2\xA0"],'',trim((string)$v));
  // Deutsches Format "1.234,56": Punkt = Tausendertrenner, Komma = Dezimaltrenner.
  // Ohne diese Unterscheidung wurde "1.234,56" zu "1.234.56" und still zu 0.
  if(strpos($v,',')!==false && strpos($v,'.')!==false) $v=str_replace('.','',$v);
  $v=str_replace(',','.',$v);
  $v=preg_replace('/[^0-9\.\-]/','',$v);
  return is_numeric($v)?$v:'0';
}
function isoDate($d){
  $d=trim((string)$d);
  if($d==='') return '';
  // checkdate statt DateTime-Überlauf: aus "31.02.2026" darf nicht still der 03.03. werden
  if(preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/',$d,$m)){
    if(checkdate((int)$m[2],(int)$m[1],(int)$m[3])) return sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]);
    return '';
  }
  if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$d,$m)){
    if(checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return $d;
    return '';
  }
  return '';
}
function s2($n){ return number_format((float)$n,2,'.',''); }
// Read-Modify-Write an status.json unter EINEM exklusiven Lock auf der Zieldatei —
// verhindert Lost Updates zwischen parallelen Schreibern (save/status_update/delete/import).
function statusRmw(string $file, callable $fn): bool {
  $h=@fopen($file,'c+'); if(!$h) return false;
  if(!@flock($h,LOCK_EX)){ fclose($h); return false; }
  $map=json_decode((string)stream_get_contents($h),true);
  if(!is_array($map)) $map=[];
  $map=$fn($map);
  ftruncate($h,0); rewind($h);
  fwrite($h,json_encode($map,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  fflush($h); @flock($h,LOCK_UN); fclose($h);
  return true;
}
function unitCode($v){
  $c=strtoupper(trim((string)$v));
  $allow=['HUR','H87','C62','LS','DAY','WEE','MON','ANN','MIN','SEC'];
  return in_array($c,$allow,true)?$c:'HUR';
}
function invoiceTypeCode($v){
  $c=trim((string)$v);
  $allow=['380','326','384','381'];
  return in_array($c,$allow,true)?$c:'380';
}
function valid_bic($bic){ return (bool)preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper((string)$bic)); }
function valid_ust($id){ $id=strtoupper(trim((string)$id)); return $id==='' || (bool)preg_match('/^DE[0-9]{9}$/',$id); }
function valid_iban($iban){
  $iban=strtoupper(preg_replace('/\s+/','',(string)$iban));
  if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/',$iban)) return false;
  $r=substr($iban,4).substr($iban,0,4);
  $n='';
  for($i=0,$l=strlen($r);$i<$l;$i++) $n.=ctype_alpha($r[$i])?(ord($r[$i])-55):$r[$i];
  $m=0;
  for($i=0,$l=strlen($n);$i<$l;$i++) $m=($m*10+intval($n[$i]))%97;
  return $m===1;
}

$rn = trim(preg_replace('/[^a-zA-Z0-9_-]/','_', (string)$det['rechnungsnummer']), '_-');
if ($rn === '') fail('Rechnungsnummer ungültig',400);

$newBase = $rn . '.xml';
$file = rtrim(OUTBOX_DIR,'/\\') . DIRECTORY_SEPARATOR . $newBase;

$prev = basename((string)($_POST['file'] ?? ''));
$force = !empty($_POST['force']);
$targetExists = is_file($file);

// Plan-Limit (Plattform): neue Rechnung nur, wenn das Kontingent reicht.
// Überschreiben (targetExists) und Umbenennen (prev = bestehende Rechnung) zählen nicht.
$xrMax = 0;
if (defined('XR_MODE') && XR_MODE === 'platform' && function_exists('current_user') && function_exists('plan_limits')) {
  $xrU = current_user();
  if ($xrU) $xrMax = (int)plan_limits((string)($xrU['plan'] ?? 'Basic'))['max_invoices'];
}
$xrRename = ($prev !== '' && $prev !== 'vorlage.xml' && $prev !== $newBase && is_file(OUTBOX_DIR . DIRECTORY_SEPARATOR . $prev));
if ($xrMax > 0 && !$targetExists && !$xrRename) {
  $xrCount = count(glob(OUTBOX_DIR . '/*.xml') ?: []);
  if ($xrCount >= $xrMax) {
    fail('Rechnungs-Limit deines Plans erreicht (' . $xrMax . '). Bitte upgraden oder Rechnungen löschen.', 403, 'limit', ['limit' => $xrMax, 'anzahl' => $xrCount]);
  }
}

if ($targetExists && !$force && ($prev === '' || $prev === 'vorlage.xml' || $prev !== $newBase)) {
  fail('Eine Rechnung mit dieser Nummer ist bereits vorhanden. Überschreiben?',409,'exists',['file'=>$newBase]);
}

// Beim Umbenennen wird die alte Datei erst NACH erfolgreichem Schreiben der neuen
// entfernt — schlägt Validierung oder Konvertierung fehl, bleibt die Rechnung erhalten.
$oldXmlToDelete = null;
$oldRnForStatus = null;
if ($prev !== '' && preg_match('/^[\w.\-]+\.xml$/i',$prev) && $prev !== 'vorlage.xml' && $prev !== $newBase) {
  $oldXml = realpath(OUTBOX_DIR . DIRECTORY_SEPARATOR . $prev);
  $base = realpath(OUTBOX_DIR);
  if ($oldXml && $base && strpos($oldXml,$base . DIRECTORY_SEPARATOR)===0 && is_file($oldXml)) {
    $oldXmlToDelete = $oldXml;
    $oldRnForStatus = preg_replace('/\.xml$/i','', basename($oldXml));
  }
}

$iban = strtoupper(preg_replace('/\s+/','', (string)($bank['iban'] ?? '')));
$bic  = strtoupper(preg_replace('/\s+/','', (string)($bank['bic'] ?? '')));
$ustid= strtoupper(trim((string)($abs['ustid'] ?? '')));

if (!valid_iban($iban)) fail('Fehler: Ungültige IBAN',400);
if ($bic !== '' && !valid_bic($bic)) fail('Fehler: Ungültige BIC',400);
if (!valid_ust($ustid)) fail('Fehler: Ungültige USt-IdNr.',400);

$payeeName = trim((string)($bank['name'] ?? ''));
if ($payeeName === '') $payeeName = trim((string)($abs['name'] ?? ''));
$bankName = trim((string)($bank['bank'] ?? ''));

$issueDate = isoDate($det['rechnungsdatum'] ?? '');
$dueDate   = isoDate($det['faelligkeitsdatum'] ?? '');
$serviceDate = isoDate($det['leistungsdatum'] ?? ($det['rechnungsdatum'] ?? ''));

if ($issueDate === '') fail('Fehler: Rechnungsdatum ungültig',400);
if ($dueDate === '') fail('Fehler: Fälligkeitsdatum ungültig',400);

$abs_plzort = (string)($abs['plzOrt'] ?? '');
$abs_plz=''; $abs_ort='';
if (preg_match('/^(\d{5})\s*(.*)$/', trim($abs_plzort), $m1)) { $abs_plz=$m1[1] ?? ''; $abs_ort=trim($m1[2] ?? ''); } else { $abs_ort=trim($abs_plzort); }

$emp_plzort = (string)($emp['plzOrt'] ?? '');
$emp_plz=''; $emp_ort='';
if (preg_match('/^(\d{5})\s*(.*)$/', trim($emp_plzort), $m2)) { $emp_plz=$m2[1] ?? ''; $emp_ort=trim($m2[2] ?? ''); } else { $emp_ort=trim($emp_plzort); }

$buyerEid = trim((string)($emp['email'] ?? ''));
if ($buyerEid === '') fail('Fehler: Käufer elektronische Adresse (BT-34) fehlt.',400);
 if (!filter_var($buyerEid, FILTER_VALIDATE_EMAIL)) fail('Fehler: Käufer elektronische Adresse ist keine gültige E-Mail.',400);

$dom = new DOMDocument('1.0','UTF-8');
$dom->formatOutput = true;

$inv = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2','Invoice');
$inv->setAttribute('xmlns:cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
$inv->setAttribute('xmlns:cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$inv->setAttribute('xmlns:ext','urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
$dom->appendChild($inv);

$inv->appendChild(el($dom, 'cbc:CustomizationID','urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0'));
$inv->appendChild(el($dom, 'cbc:ProfileID','urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'));
$inv->appendChild(el($dom, 'cbc:ID',$rn));
$inv->appendChild(el($dom, 'cbc:IssueDate',$issueDate));
$inv->appendChild(el($dom, 'cbc:DueDate',$dueDate));
$invType=invoiceTypeCode($det['typ'] ?? '380');
$inv->appendChild(el($dom, 'cbc:InvoiceTypeCode',$invType));

$preceding=trim((string)($det['vorgaenger_rechnung'] ?? ''));
// BR-DE-26: Eine korrigierte Rechnung (384) MUSS auf die Vorgängerrechnung verweisen.
// Geschrieben wird die Referenz (BT-25) aber bei JEDEM Typ, wenn sie gefüllt ist —
// EN 16931 erlaubt das generell; API-Weg und Konverter behandeln sie ebenso typ-frei.
if($invType==='384' && $preceding==='') fail('Fehler: Korrigierte Rechnung (Typ 384) benötigt die Rechnungsnummer der Vorgängerrechnung.',400);

if (!empty($det['beschreibung'])) $inv->appendChild(el($dom, 'cbc:Note', (string)$det['beschreibung']));
$inv->appendChild(el($dom, 'cbc:DocumentCurrencyCode','EUR'));
$buyerRef = trim((string)($det['buyer_reference'] ?? ''));
if ($buyerRef === '') $buyerRef = $buyerEid;
$buyerRef = preg_replace('/[\x00-\x1F\x7F]/', '', $buyerRef);
$inv->appendChild(el($dom, 'cbc:BuyerReference', $buyerRef));

// cac:BillingReference gehört laut UBL-2.1-Sequenz HINTER den cbc-Block
// (…InvoiceTypeCode → Note → DocumentCurrencyCode → BuyerReference → BillingReference)
if($preceding!==''){
  $br=$dom->createElement('cac:BillingReference');
  $idr=$dom->createElement('cac:InvoiceDocumentReference');
  $idr->appendChild(el($dom, 'cbc:ID',$preceding));
  $br->appendChild($idr);
  $inv->appendChild($br);
}


$supp = $dom->createElement('cac:AccountingSupplierParty');
$sp = $dom->createElement('cac:Party');

$sellerEid = trim((string)($abs['email'] ?? ''));
if ($sellerEid !== '' && !filter_var($sellerEid, FILTER_VALIDATE_EMAIL)) fail('Fehler: Absender-E-Mail ist keine gültige E-Mail.',400);
if ($sellerEid !== '') {
  $eid = el($dom, 'cbc:EndpointID', $sellerEid);
  $eid->setAttribute('schemeID','EM');
  $sp->appendChild($eid);
}

$pn = $dom->createElement('cac:PartyName');
$pn->appendChild(el($dom, 'cbc:Name',(string)($abs['name'] ?? '')));
$sp->appendChild($pn);

$sellerPhone = normPhone($abs['telefon'] ?? '');
$contact = $dom->createElement('cac:Contact');
$contact->appendChild(el($dom, 'cbc:Name', (string)($abs['name'] ?? '')));
$contact->appendChild(el($dom, 'cbc:Telephone', $sellerPhone));
if ($sellerEid !== '') $contact->appendChild(el($dom, 'cbc:ElectronicMail', $sellerEid));
$sp->appendChild($contact);



$addr = $dom->createElement('cac:PostalAddress');
$addr->appendChild(el($dom, 'cbc:StreetName',(string)($abs['adresse'] ?? '')));
$addr->appendChild(el($dom, 'cbc:CityName',$abs_ort));
$addr->appendChild(el($dom, 'cbc:PostalZone',$abs_plz));
$cty = $dom->createElement('cac:Country');
$cty->appendChild(el($dom, 'cbc:IdentificationCode','DE'));
$addr->appendChild($cty);
$sp->appendChild($addr);

if ($ustid !== '') {
  $pts = $dom->createElement('cac:PartyTaxScheme');
  $pts->appendChild(el($dom, 'cbc:CompanyID',$ustid));
  $ts= $dom->createElement('cac:TaxScheme');
  $ts->appendChild(el($dom, 'cbc:ID','VAT'));
  $pts->appendChild($ts);
  $sp->appendChild($pts);
}

$ple = $dom->createElement('cac:PartyLegalEntity');
$ple->appendChild(el($dom, 'cbc:RegistrationName',(string)($abs['name'] ?? '')));
$sp->appendChild($ple);

$supp->appendChild($sp);
$inv->appendChild($supp);

$cust = $dom->createElement('cac:AccountingCustomerParty');
$cp = $dom->createElement('cac:Party');

$ceid = el($dom, 'cbc:EndpointID', $buyerEid);
$ceid->setAttribute('schemeID','EM');
$cp->appendChild($ceid);

$cpn = $dom->createElement('cac:PartyName');
$cpn->appendChild(el($dom, 'cbc:Name',(string)($emp['name'] ?? '')));
$cp->appendChild($cpn);

$caddr = $dom->createElement('cac:PostalAddress');
$caddr->appendChild(el($dom, 'cbc:StreetName',(string)($emp['adresse'] ?? '')));
$caddr->appendChild(el($dom, 'cbc:CityName',$emp_ort));
$caddr->appendChild(el($dom, 'cbc:PostalZone',$emp_plz));
$ccty = $dom->createElement('cac:Country');
$ccty->appendChild(el($dom, 'cbc:IdentificationCode','DE'));
$caddr->appendChild($ccty);
$cp->appendChild($caddr);

$cle = $dom->createElement('cac:PartyLegalEntity');
$cle->appendChild(el($dom, 'cbc:RegistrationName',(string)($emp['name'] ?? '')));
$cp->appendChild($cle);

$cust->appendChild($cp);
$inv->appendChild($cust);

$delNode = null;
if ($serviceDate !== '') {
  $delNode = $dom->createElement('cac:Delivery');
  $delNode->appendChild(el($dom, 'cbc:ActualDeliveryDate', $serviceDate));
  $inv->appendChild($delNode);
}

$pm = $dom->createElement('cac:PaymentMeans');
$pm->appendChild(el($dom, 'cbc:PaymentMeansCode', (string)($pay['code'] ?? '58')));
$payId = (string)($pay['id'] ?? $rn);
if ($payId !== '') $pm->appendChild(el($dom, 'cbc:PaymentID', $payId));

$acc = $dom->createElement('cac:PayeeFinancialAccount');
$acc->appendChild(el($dom, 'cbc:ID', $iban));
$acc->appendChild(el($dom, 'cbc:Name', $payeeName));

if ($bic !== '' || $bankName !== '') {
  $branch = $dom->createElement('cac:FinancialInstitutionBranch');
  if ($bic !== '') $branch->appendChild(el($dom, 'cbc:ID', $bic));
  if ($bankName !== '') $branch->appendChild(el($dom, 'cbc:Name', $bankName));
  $acc->appendChild($branch);
}

$pm->appendChild($acc);
$inv->appendChild($pm);

$net = 0.0;
$taxBuckets = [];
$lineNodes = [];
$idx = 0;

foreach ($pos as $p) {
  if (!is_array($p)) continue;

  $desc = trim((string)($p['beschreibung'] ?? ''));
  $qty  = (float)cleanCurrency($p['menge'] ?? '0');
  $price= (float)cleanCurrency($p['einzelpreis'] ?? '0');

  if ($desc === '' && abs($qty) < 0.00001 && abs($price) < 0.00001) continue;
  if ($desc === '') $desc = 'Position';

  $rate = 19.0;
  $lineNet = round($qty * $price, 2);

  $net += $lineNet;
  $taxBuckets[$rate] = ($taxBuckets[$rate] ?? 0.0) + $lineNet;

  $il = $dom->createElement('cac:InvoiceLine');
  $il->appendChild(el($dom, 'cbc:ID', ++$idx));

  $qtyEl = el($dom, 'cbc:InvoicedQuantity', s2($qty));
  $qtyEl->setAttribute('unitCode', unitCode((string)($p['einheit'] ?? 'HUR')));
  $il->appendChild($qtyEl);

  $le = el($dom, 'cbc:LineExtensionAmount', s2($lineNet));
  $le->setAttribute('currencyID','EUR');
  $il->appendChild($le);

  $item = $dom->createElement('cac:Item');
  $item->appendChild(el($dom, 'cbc:Description', $desc));
  $item->appendChild(el($dom, 'cbc:Name', $desc));

  $ctg = $dom->createElement('cac:ClassifiedTaxCategory');
  $ctg->appendChild(el($dom, 'cbc:ID', $rate>0?'S':'Z'));
  $ctg->appendChild(el($dom, 'cbc:Percent', s2($rate)));
  $ts2 = $dom->createElement('cac:TaxScheme');
  $ts2->appendChild(el($dom, 'cbc:ID','VAT'));
  $ctg->appendChild($ts2);
  $item->appendChild($ctg);

  $il->appendChild($item);

  $priceEl = $dom->createElement('cac:Price');
  $pa = el($dom, 'cbc:PriceAmount', s2($price));
  $pa->setAttribute('currencyID','EUR');
  $priceEl->appendChild($pa);
  $il->appendChild($priceEl);

  $lineNodes[] = $il;
}

if ($idx === 0) fail('Fehler: Keine Positionen.',400);

$allow = (float)cleanCurrency($sum['rabatt'] ?? '0');
$prepaid = (float)cleanCurrency($sum['vorauszahlung'] ?? '0');

if ($net <= 0.0) $allow = 0.0;
if ($allow < 0.0) $allow = 0.0;
if ($allow > $net) $allow = $net;
if ($prepaid < 0.0) $prepaid = 0.0;

$taxBases = $taxBuckets;

if ($allow > 0.0) {
  $rates = array_keys($taxBuckets);
  sort($rates, SORT_NUMERIC);
  $remainAllow = $allow;
  $remainBase = $net;
  $reduced = [];
  $sumReduced = 0.0;

  for ($i=0,$l=count($rates); $i<$l; $i++) {
    $r = $rates[$i];
    $b = (float)($taxBuckets[$r] ?? 0.0);

    if ($i === $l-1) {
      $b2 = round(($net - $allow) - $sumReduced, 2);
      if ($b2 < 0.0) $b2 = 0.0;
      $reduced[$r] = $b2;
      break;
    }

    if ($remainBase <= 0.0) { $reduced[$r] = 0.0; continue; }

    $share = $b / $remainBase;
    $cut = round($remainAllow * $share, 2);
    if ($cut > $b) $cut = $b;

    $b2 = round($b - $cut, 2);
    if ($b2 < 0.0) $b2 = 0.0;

    $reduced[$r] = $b2;
    $sumReduced += $b2;

    $remainAllow = round($remainAllow - $cut, 2);
    $remainBase  = round($remainBase - $b, 2);
  }

  $taxBases = $reduced;
}

$totalTax = 0.0;
foreach ($taxBases as $rate=>$taxable) $totalTax += round(((float)$taxable) * ((float)$rate) / 100, 2);
$totalTax = round($totalTax, 2);

$taxTotal = $dom->createElement('cac:TaxTotal');
$taxAmt = el($dom, 'cbc:TaxAmount', s2($totalTax));
$taxAmt->setAttribute('currencyID','EUR');
$taxTotal->appendChild($taxAmt);

foreach ($taxBases as $rate=>$taxable) {
  $sub = $dom->createElement('cac:TaxSubtotal');

  $ta = el($dom, 'cbc:TaxableAmount', s2($taxable));
  $ta->setAttribute('currencyID','EUR');
  $sub->appendChild($ta);

  $t = el($dom, 'cbc:TaxAmount', s2(round(((float)$taxable)*((float)$rate)/100,2)));
  $t->setAttribute('currencyID','EUR');
  $sub->appendChild($t);

  $cat = $dom->createElement('cac:TaxCategory');
  $cat->appendChild(el($dom, 'cbc:ID', ((float)$rate)>0?'S':'Z'));
  $cat->appendChild(el($dom, 'cbc:Percent', s2($rate)));
  $scheme = $dom->createElement('cac:TaxScheme');
  $scheme->appendChild(el($dom, 'cbc:ID','VAT'));
  $cat->appendChild($scheme);
  $sub->appendChild($cat);

  $taxTotal->appendChild($sub);
}
$inv->appendChild($taxTotal);

$taxExclusive = round($net - $allow, 2);
if ($taxExclusive < 0.0) $taxExclusive = 0.0;

$taxInclusive = round($taxExclusive + $totalTax, 2);
$payable = round($taxInclusive - $prepaid, 2);
if ($payable < 0.0) $payable = 0.0;

$legal = $dom->createElement('cac:LegalMonetaryTotal');
$e = el($dom, 'cbc:LineExtensionAmount', s2($net)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$e = el($dom, 'cbc:TaxExclusiveAmount', s2($taxExclusive)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$e = el($dom, 'cbc:TaxInclusiveAmount', s2($taxInclusive)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$e = el($dom, 'cbc:AllowanceTotalAmount', s2($allow)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$e = el($dom, 'cbc:PrepaidAmount', s2($prepaid)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$e = el($dom, 'cbc:PayableAmount', s2($payable)); $e->setAttribute('currencyID','EUR'); $legal->appendChild($e);
$inv->appendChild($legal);

foreach ($lineNodes as $n) $inv->appendChild($n);

$tmpUbl = tempnam(sys_get_temp_dir(), 'xrmg_');
if ($tmpUbl === false) fail('Fehler: Temp-Datei nicht anlegbar',500);
if (!$dom->save($tmpUbl)) { @unlink($tmpUbl); fail('Fehler beim Speichern',500); }

require_once __DIR__ . '/ubl_to_cii.php';

$ciiOk = ubl_to_cii($tmpUbl, $file);
@unlink($tmpUbl);

if (!$ciiOk) fail('Fehler beim Speichern',500);

// Umbenennen: neue Datei steht — jetzt alte Datei entfernen und Status-Eintrag mitnehmen
if ($oldXmlToDelete !== null) {
  @unlink($oldXmlToDelete);
  statusRmw(DATA_ROOT . '/status.json', function(array $map) use ($oldRnForStatus, $rn) {
    if ($oldRnForStatus !== null && $oldRnForStatus !== '' && isset($map[$oldRnForStatus])) {
      $map[$rn] = $map[$oldRnForStatus];
      unset($map[$oldRnForStatus]);
    }
    return $map;
  });
}

j(200,['ok'=>true,'msg'=>'XML gespeichert','file'=>basename($file)]);
