<?php
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config.php';

if (!isset($_GET['file'])) exit('Datei nicht angegeben.');
$req  = (string)$_GET['file'];
$file = basename($req);
if (!preg_match('/^[\w.\-]+\.xml$/i', $file)) exit('Ungültiger Dateiname');

$isTemplate = ($file === 'vorlage.xml');

if ($isTemplate) {
  $base = realpath(DATA_ROOT);
  $path = realpath(TEMPLATE_FILE);
} else {
  $base = realpath(OUTBOX_DIR);
  $path = $base ? realpath($base . DIRECTORY_SEPARATOR . $file) : false;
}
if ($path === false || $base === false || strpos($path, $base) !== 0 || !is_file($path)) exit('Datei nicht gefunden.');

libxml_use_internal_errors(true);
$dom = new DOMDocument();
if (!$dom->load($path, LIBXML_NONET)) exit('Fehler beim Laden der XML-Datei.');
$root = $dom->documentElement ? $dom->documentElement->localName : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function d2p($d){ $o=DateTime::createFromFormat('Y-m-d',(string)$d); return $o?$o->format('d.m.Y'):(string)$d; }
function d2p102($d){ if(preg_match('/^\d{8}$/',(string)$d)){ $o=DateTime::createFromFormat('Ymd',(string)$d); return $o?$o->format('d.m.Y'):(string)$d; } return (string)$d; }

function unitOptions(){
  return [
    'HUR'=>'Std.',
    'H87'=>'Stck.',
    'C62'=>'Einheit',
    'LS'=>'Pauschal',
    'DAY'=>'Tag',
    'WEE'=>'Woche',
    'MON'=>'Monat',
    'ANN'=>'Jahr',
    'MIN'=>'Minute',
    'SEC'=>'Sekunde',
  ];
}

function moneyParse($v): float {
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  $s = preg_replace('/[^0-9,\.\-]/', '', $s);
  $s = str_replace(',', '.', $s);
  return (float)$s;
}

function moneyFmt($v): string {
  return number_format((float)$v, 2, ',', '');
}

function numInput($v): string {
  $f = moneyParse($v);
  $s = rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
  return $s === '' ? '0' : $s;
}

$id=$issue=$due=$service=$note=$payName=$bankName=$iban=$bic='';
$invType='380';
$types=[
  '380'=>'Rechnung',
  '326'=>'Teilrechnung',
  '384'=>'Korrigierte Rechnung',
  '381'=>'Gutschrift'
];

$suppName=$suppStreet=$suppZip=$suppCity=$suppTel=$suppMail=$suppUst='';
$custName=$custStreet=$custZip=$custCity=$custMail='';
$net=0.0; $ustAmt=0.0; $payableAmt=0.0;
$lines=[];

if ($isTemplate || $root === 'Invoice') {
  $xp = new DOMXPath($dom);
  $xp->registerNamespace('u','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $s = function($q,$c=null) use($xp){ return trim($xp->evaluate('string('.$q.')',$c)); };

  $id = $s('/u:Invoice/cbc:ID');
  $issue = d2p($s('/u:Invoice/cbc:IssueDate'));
  $due = d2p($s('/u:Invoice/cbc:DueDate'));
  $service = d2p($s('/u:Invoice/cac:Delivery/cbc:ActualDeliveryDate'));
  $note = $s('/u:Invoice/cbc:Note');
  $invType = $s('/u:Invoice/cbc:InvoiceTypeCode') ?: '380';

  $suppName = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
  $suppStreet = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $suppZip = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $suppCity = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $suppTel = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:Telephone');
  $suppMail = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:ElectronicMail');
  $suppUst = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');

  $custName = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
  $custStreet = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $custZip = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $custCity = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $custMail = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID');

  $payName = $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:Name');
  $iban = $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
  $bic = $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID');
  $bankName = $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:Name');
  if ($payName === '') $payName = $suppName;

  $net = moneyParse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
  if ($net == 0.0) $net = moneyParse($s('/u:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'));
  $ustAmt = moneyParse($s('/u:Invoice/cac:TaxTotal/cbc:TaxAmount'));
  $payableAmt = moneyParse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
  if ($payableAmt == 0.0) $payableAmt = $net + $ustAmt;

  foreach($xp->query('/u:Invoice/cac:InvoiceLine') as $i=>$li){
    $desc = $s('cac:Item/cbc:Name',$li);
    if ($desc === '') $desc = $s('cac:Item/cbc:Description',$li);

    $qtyRaw = $s('cbc:InvoicedQuantity',$li);
    $unit = strtoupper(trim($xp->evaluate('string(cbc:InvoicedQuantity/@unitCode)',$li))) ?: 'HUR';

    $priceRaw = $s('cac:Price/cbc:PriceAmount',$li);
    $lineRaw = $s('cbc:LineExtensionAmount',$li);

    $qty = numInput($qtyRaw);
    $price = moneyFmt(moneyParse($priceRaw));
    $line = $lineRaw !== '' ? moneyFmt(moneyParse($lineRaw)) : moneyFmt(moneyParse($qtyRaw) * moneyParse($priceRaw));

    $lines[]=['desc'=>$desc,'unit'=>$unit,'qty'=>$qty,'price'=>$price,'line'=>$line];
  }
} else {
  $xp = new DOMXPath($dom);
  $xp->registerNamespace('rsm','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
  $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
  $xp->registerNamespace('udt','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
  $s=function($q,$c=null)use($xp){ return trim($xp->evaluate('string('.$q.')',$c)); };

  $id = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
  $issue = d2p102($s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'));
  $due = d2p102($s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'));
  $service = d2p102($s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'));
  $invType = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode') ?: '380';
  $note = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content');

  $sp='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
  $bp='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';

  $suppName=$s($sp.'/ram:Name');
  $suppStreet=$s($sp.'/ram:PostalTradeAddress/ram:LineOne');
  $suppZip=$s($sp.'/ram:PostalTradeAddress/ram:PostcodeCode');
  $suppCity=$s($sp.'/ram:PostalTradeAddress/ram:CityName');
  $suppTel=$s($sp.'/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber');
  $suppMail=$s($sp.'/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID');
  $suppUst=$s($sp.'/ram:SpecifiedTaxRegistration/ram:ID');

  $custName=$s($bp.'/ram:Name');
  $custStreet=$s($bp.'/ram:PostalTradeAddress/ram:LineOne');
  $custZip=$s($bp.'/ram:PostalTradeAddress/ram:PostcodeCode');
  $custCity=$s($bp.'/ram:PostalTradeAddress/ram:CityName');
  $custMail=$s($bp.'/ram:URIUniversalCommunication/ram:URIID');

  $pay='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
  $iban=$s($pay.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID');
  $bic=$s($pay.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID');
  $bankName=$s($pay.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name');
  $payName=$s($pay.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:AccountName');
  if ($payName === '') $payName = $suppName;

  $sum=$pay.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
  $net = moneyParse($s($sum.'/ram:TaxBasisTotalAmount'));
  if ($net == 0.0) $net = moneyParse($s($sum.'/ram:LineTotalAmount'));
  $ustAmt = moneyParse($s($sum.'/ram:TaxTotalAmount'));
  $payableAmt = moneyParse($s($sum.'/ram:DuePayableAmount'));
  if ($payableAmt == 0.0) $payableAmt = $net + $ustAmt;

  foreach($xp->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') as $li){
    $desc=$s('ram:SpecifiedTradeProduct/ram:Name',$li);

    $qtyRaw = $s('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity',$li);
    $unit = strtoupper(trim($s('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode',$li))) ?: 'HUR';

    $priceRaw = $s('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount',$li);
    $lineRaw = $s('ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount',$li);

    $qty = numInput($qtyRaw);
    $price = moneyFmt(moneyParse($priceRaw));
    $line = $lineRaw !== '' ? moneyFmt(moneyParse($lineRaw)) : moneyFmt(moneyParse($qtyRaw) * moneyParse($priceRaw));

    $lines[]=['desc'=>$desc,'unit'=>$unit,'qty'=>$qty,'price'=>$price,'line'=>$line];
  }
}

if ($service === '') $service = $issue;

if ($isTemplate) {
  $today = (new DateTime('today'))->format('d.m.Y');
  if ($issue === '') $issue = $today;
  if ($service === '') $service = $issue;
  if ($due === '') $due = (new DateTime('today +14 days'))->format('d.m.Y');
  if ($id === '') $id = '';
}
?>
<div class="dina4">
<form id="invoiceForm" action="save.php" method="post">
<input type="hidden" name="file" value="<?php echo h($file); ?>">
<input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf'] ?? ''); ?>">

<div class="header">
  <input type="text" name="absender[name]" value="<?php echo h($suppName); ?>">
  <input type="text" name="absender[adresse]" value="<?php echo h($suppStreet); ?>">
  <input type="text" name="absender[plzOrt]" value="<?php echo h(trim($suppZip.' '.$suppCity)); ?>">
  <input type="text" name="absender[telefon]" value="<?php echo h($suppTel); ?>">
  <input type="text" name="absender[email]" value="<?php echo h($suppMail); ?>">
  <input type="text" name="absender[ustid]" value="<?php echo h($suppUst); ?>">
</div>

<div class="recipient">
  <input type="text" name="empfaenger[name]" value="<?php echo h($custName); ?>">
  <input type="text" name="empfaenger[adresse]" value="<?php echo h($custStreet); ?>">
  <input type="text" name="empfaenger[plzOrt]" value="<?php echo h(trim($custZip.' '.$custCity)); ?>">
  <input type="text" name="empfaenger[email]" required placeholder="Buyer e-Adresse (z. B. Peppol-ID oder E-Mail)" value="<?php echo h($custMail); ?>">
</div>

<div class="invoice-details">
  <select class="grau select" name="details[typ]">
    <?php foreach($types as $code=>$label): ?>
      <option value="<?php echo h($code); ?>" <?php echo ((string)$invType===(string)$code)?'selected':''; ?>><?php echo h($label); ?></option>
    <?php endforeach; ?>
  </select>
  <table>
    <tr><td style="width:140px;text-align:right;">Rechnungsnummer:</td><td style="width:100px"><input type="text" name="details[rechnungsnummer]" value="<?php echo h($id); ?>"></td></tr>
    <tr><td style="text-align:right;">Rechnungsdatum:</td><td><input type="text" name="details[rechnungsdatum]" value="<?php echo h($issue); ?>"></td></tr>
    <tr><td style="text-align:right;">Fälligkeitsdatum:</td><td><input type="text" name="details[faelligkeitsdatum]" value="<?php echo h($due); ?>"></td></tr>
    <tr><td style="text-align:right;">Leistungsdatum:</td><td><input type="text" name="details[leistungsdatum]" value="<?php echo h($service); ?>"></td></tr>
    <tr class="grau"><td style="text-align:right;">Zu Zahlen EUR:</td><td><input class="grau" type="text" id="payableTop" name="details[gesamtbetrag]" value="<?php echo h(moneyFmt($payableAmt).' €'); ?>" readonly></td></tr>
  </table>
</div>

<input class="rechnungsbeschreibung" type="text" name="details[beschreibung]" value="<?php echo h($note); ?>">

<div class="table-scroll" role="region" aria-label="Rechnungspositionen, horizontal scrollbar" tabindex="0">
<table class="invoice-table">
  <thead>
    <tr><th>Beschreibung</th><th>Einheit</th><th>Menge</th><th>Einzelpreis</th><th>Gesamt</th><th class="noprint"></th></tr>
  </thead>
  <tbody>
  <?php foreach($lines as $i=>$ln): ?>
    <tr>
      <td><input type="text" name="positionen[<?php echo (int)$i; ?>][beschreibung]" value="<?php echo h($ln['desc']); ?>"></td>
      <td>
        <select class="plain-select" name="positionen[<?php echo (int)$i; ?>][einheit]">
          <?php foreach(unitOptions() as $code=>$label): ?>
            <option value="<?php echo h($code); ?>" <?php echo (strtoupper($ln['unit'])===strtoupper($code))?'selected':''; ?>><?php echo h($label); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><input type="number" step="0.01" inputmode="decimal" name="positionen[<?php echo (int)$i; ?>][menge]" value="<?php echo h($ln['qty']); ?>"></td>
      <td><input type="text" name="positionen[<?php echo (int)$i; ?>][einzelpreis]" value="<?php echo h($ln['price']); ?>"></td>
      <td style="width:100px;"><span><?php echo h($ln['line']); ?> €</span></td>
      <td style="width:10px;" class="noprint pointer"><p onclick="this.closest('tr').remove()">-</p></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<button type="button" onclick="
  const tb=document.querySelector('.invoice-table tbody');
  const i=tb.querySelectorAll('tr').length;
  const units=`<?php foreach(unitOptions() as $code=>$label): ?><option value='<?php echo h($code); ?>'><?php echo h($label); ?></option><?php endforeach; ?>`;
  const r=document.createElement('tr');
  r.innerHTML=`<td><input type='text' name='positionen[${i}][beschreibung]' value=''></td>
               <td><select class='plain-select' name='positionen[${i}][einheit]'>${units}</select></td>
               <td><input type='number' step='0.01' inputmode='decimal' name='positionen[${i}][menge]' value='0'></td>
               <td><input type='text' name='positionen[${i}][einzelpreis]' value='0,00'></td>
               <td style='width:100px;'><span>0,00 €</span></td>
               <td style='width:10px;' class='noprint pointer'><p onclick='this.closest(&quot;tr&quot;).remove()'>-</p></td>`;
  tb.appendChild(r);
">+</button>

<div class="summary">
  <table>
    <tr><td style="width:140px;text-align:right;">Nettobetrag:</td><td style="width:100px"><input type="text" name="zusammenfassung[nettobetrag]" value="<?php echo h(moneyFmt($net).' €'); ?>" readonly></td></tr>
    <tr><td style="text-align:right;">Umsatzsteuer 19%:</td><td><input type="text" name="zusammenfassung[umsatzsteuer]" value="<?php echo h(moneyFmt($ustAmt).' €'); ?>" readonly></td></tr>
    <tr class="grau"><td style="text-align:right;">Gesamtbetrag:</td><td><input class="grau" type="text" id="payableBottom" value="<?php echo h(moneyFmt($payableAmt).' €'); ?>" readonly></td></tr>
  </table>
</div>

<div class="footer">
  <input class="danke" type="text" name="zusammenfassung[danke]" value="Vielen Dank für den Auftrag!">
  <input type="text" name="bankverbindung[name]" value="<?php echo h($payName); ?>">
  <input type="text" name="bankverbindung[bank]" value="<?php echo h($bankName); ?>">
  <input type="text" name="bankverbindung[iban]" value="<?php echo h($iban); ?>">
  <input type="text" name="bankverbindung[bic]" value="<?php echo h($bic); ?>">
</div>
</form>
</div>

<div class="noprint" style="margin-top:20px" id="actionBar">
  <button id="btnSave" type="submit" form="invoiceForm">Speichern</button>
  <a id="btnXml" href="download.php?file=<?php echo urlencode($file); ?>" class="button" style="padding:10px 20px;font-size:14px;background:#333;color:#fff;border:none;cursor:pointer;text-decoration:none">XML</a>
  <?php if(!$isTemplate): ?>
    <button id="btnPdf" type="button" onclick="openInvoicePdf()">PDF</button>
    <button id="btnDelete" type="button" onclick="deleteInvoice()">Löschen</button>
  <?php else: ?>
    <button id="btnPdf" type="button" onclick="openInvoicePdf()" disabled>PDF</button>
    <button id="btnDelete" type="button" onclick="deleteInvoice()" disabled>Löschen</button>
  <?php endif; ?>
  <div id="save-msg" class="save-msg">gespeichert</div>
</div>

