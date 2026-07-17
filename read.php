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
// BT-10 Leitweg-ID und BT-25 Vorgaengerrechnung: muessen ausgelesen und im Formular
// mitgefuehrt werden. save.php baut die XML komplett neu auf; was das Formular nicht
// sendet, ist danach weg bzw. wird ersetzt (buyer_reference faellt auf die
// Empfaenger-E-Adresse zurueck, und Typ 384 laesst sich ohne Vorgaenger gar nicht
// speichern — BR-DE-26).
$buyerRef=$preceding='';
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
  $buyerRef = $s('/u:Invoice/cbc:BuyerReference');
  $preceding = $s('/u:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID');

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
  $buyerRef = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerReference');
  $preceding = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument/ram:IssuerAssignedID');

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
  // Die Vorlage traegt in BuyerReference den Platzhalter '0' — kein echter Wert.
  // Ungefiltert durchgereicht landete er in jeder neuen Rechnung, weil save.php
  // strikt auf '' prueft ('0' !== '') und der Fallback auf die Empfaenger-E-Adresse
  // dann nicht mehr greift. Der API-Weg (invoice_core) behandelt '0' ebenfalls als
  // Nicht-Wert; hier bleibt es dabei.
  $buyerRef = '';
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
<?php /* BT-10 Leitweg-ID und BT-25 Vorgaengerrechnung unveraendert durchreichen.
        save.php baut die XML komplett neu — was hier nicht mitkommt, ist danach weg:
        eine vorhandene Leitweg-ID wurde sonst durch die Empfaenger-E-Adresse ersetzt
        (save.php-Fallback), und Typ 384 liess sich ohne Vorgaenger gar nicht speichern
        (BR-DE-26). Bewusst hidden: sichtbare Felder wuerden das Rechnungsbild aendern,
        und das ist rechtlich abgenommen (Foerb, 17.07.) — siehe xrechnung_design.md. */ ?>
<input type="hidden" name="details[buyer_reference]" value="<?php echo h($buyerRef); ?>">
<input type="hidden" name="details[vorgaenger_rechnung]" value="<?php echo h($preceding); ?>">

<?php /* aria-label statt sichtbarer <label>: Die Felder tragen ihre Bedeutung hier
        allein ueber die Position im Rechnungsbild (Briefkopf, Anschrift). Sichtbare
        Beschriftungen wuerden das rechtlich abgenommene Layout veraendern (Foerb,
        17.07.) — aria-label ist unsichtbar und aendert kein Pixel. */ ?>
<div class="header">
  <input type="text" name="absender[name]" aria-label="Absender: Name / Firma" value="<?php echo h($suppName); ?>">
  <input type="text" name="absender[adresse]" aria-label="Absender: Straße und Hausnummer" value="<?php echo h($suppStreet); ?>">
  <input type="text" name="absender[plzOrt]" aria-label="Absender: PLZ und Ort" value="<?php echo h(trim($suppZip.' '.$suppCity)); ?>">
  <input type="text" name="absender[telefon]" aria-label="Absender: Telefon" value="<?php echo h($suppTel); ?>">
  <input type="text" name="absender[email]" aria-label="Absender: E-Mail" value="<?php echo h($suppMail); ?>">
  <input type="text" name="absender[ustid]" aria-label="Absender: USt-IdNr." value="<?php echo h($suppUst); ?>">
</div>

<div class="recipient">
  <input type="text" name="empfaenger[name]" aria-label="Empfänger: Name / Firma" value="<?php echo h($custName); ?>">
  <input type="text" name="empfaenger[adresse]" aria-label="Empfänger: Straße und Hausnummer" value="<?php echo h($custStreet); ?>">
  <input type="text" name="empfaenger[plzOrt]" aria-label="Empfänger: PLZ und Ort" value="<?php echo h(trim($custZip.' '.$custCity)); ?>">
  <input type="text" name="empfaenger[email]" required aria-label="Empfänger: E-Adresse (Peppol-ID oder E-Mail), Pflichtfeld" placeholder="Buyer e-Adresse (z. B. Peppol-ID oder E-Mail)" value="<?php echo h($custMail); ?>">
</div>

<div class="invoice-details">
  <select class="grau select" name="details[typ]" aria-label="Rechnungstyp">
    <?php foreach($types as $code=>$label): ?>
      <option value="<?php echo h($code); ?>" <?php echo ((string)$invType===(string)$code)?'selected':''; ?>><?php echo h($label); ?></option>
    <?php endforeach; ?>
  </select>
  <?php /* Die Zelle links ist optisch die Beschriftung, technisch aber nur Text ohne
          Bezug zum Feld. <label for> verknuepft den VORHANDENEN Text — es kommt
          nichts Sichtbares dazu, das Bild bleibt identisch. */ ?>
  <table>
    <tr><td style="width:140px;text-align:right;"><label for="fld-rgnr">Rechnungsnummer:</label></td><td style="width:100px"><input type="text" id="fld-rgnr" name="details[rechnungsnummer]" value="<?php echo h($id); ?>"></td></tr>
    <tr><td style="text-align:right;"><label for="fld-rgdatum">Rechnungsdatum:</label></td><td><input type="text" id="fld-rgdatum" name="details[rechnungsdatum]" value="<?php echo h($issue); ?>"></td></tr>
    <tr><td style="text-align:right;"><label for="fld-faellig">Fälligkeitsdatum:</label></td><td><input type="text" id="fld-faellig" name="details[faelligkeitsdatum]" value="<?php echo h($due); ?>"></td></tr>
    <tr><td style="text-align:right;"><label for="fld-leistung">Leistungsdatum:</label></td><td><input type="text" id="fld-leistung" name="details[leistungsdatum]" value="<?php echo h($service); ?>"></td></tr>
    <tr class="grau"><td style="text-align:right;"><label for="payableTop">Zu Zahlen EUR:</label></td><td><input class="grau" type="text" id="payableTop" name="details[gesamtbetrag]" value="<?php echo h(moneyFmt($payableAmt).' €'); ?>" readonly></td></tr>
  </table>
</div>

<input class="rechnungsbeschreibung" type="text" name="details[beschreibung]" aria-label="Rechnungsbeschreibung" value="<?php echo h($note); ?>">

<div class="table-scroll" role="region" aria-label="Rechnungspositionen, horizontal scrollbar" tabindex="0">
<table class="invoice-table">
  <thead>
    <tr><th>Beschreibung</th><th>Einheit</th><th>Menge</th><th>Einzelpreis</th><th>Gesamt</th><th class="noprint"></th></tr>
  </thead>
  <tbody>
  <?php foreach($lines as $i=>$ln): ?>
    <tr>
      <td><input type="text" name="positionen[<?php echo (int)$i; ?>][beschreibung]" aria-label="Position <?php echo (int)$i+1; ?>: Beschreibung" value="<?php echo h($ln['desc']); ?>"></td>
      <td>
        <select class="plain-select" name="positionen[<?php echo (int)$i; ?>][einheit]" aria-label="Position <?php echo (int)$i+1; ?>: Einheit">
          <?php foreach(unitOptions() as $code=>$label): ?>
            <option value="<?php echo h($code); ?>" <?php echo (strtoupper($ln['unit'])===strtoupper($code))?'selected':''; ?>><?php echo h($label); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><input type="number" step="0.01" inputmode="decimal" name="positionen[<?php echo (int)$i; ?>][menge]" aria-label="Position <?php echo (int)$i+1; ?>: Menge" value="<?php echo h($ln['qty']); ?>"></td>
      <td><input type="text" name="positionen[<?php echo (int)$i; ?>][einzelpreis]" aria-label="Position <?php echo (int)$i+1; ?>: Einzelpreis" value="<?php echo h($ln['price']); ?>"></td>
      <td style="width:100px;"><span><?php echo h($ln['line']); ?> €</span></td>
      <?php /* Loeschen ist ein <p>: nicht fokussierbar, ohne Rolle, Name nur "-".
              Ein echter <button> wuerde die Optik aendern (style.css:56) — deshalb
              Rolle/Name/Tastatur nachgeruestet statt das Element getauscht. */ ?>
      <td style="width:10px;" class="noprint pointer"><p role="button" tabindex="0" aria-label="Position <?php echo (int)$i+1; ?> entfernen" onclick="this.closest('tr').remove()">-</p></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<button type="button" onclick="
  const tb=document.querySelector('.invoice-table tbody');
  // Index aus dem HOECHSTEN vergebenen Index ableiten, nicht aus der Zeilenanzahl:
  // nach dem Loeschen einer Position ist die Anzahl kleiner als der hoechste Index,
  // eine neue Zeile bekaeme sonst einen bereits vergebenen Namen und wuerde die
  // bestehende Position beim Speichern ueberschreiben (PHP: letzter Wert gewinnt).
  let max=-1;
  tb.querySelectorAll('input[name],select[name]').forEach(el=>{
    const m=/^positionen\[(\d+)\]/.exec(el.getAttribute('name')||'');
    if(m) max=Math.max(max, Number(m[1]));
  });
  const i=max+1;
  const units=`<?php foreach(unitOptions() as $code=>$label): ?><option value='<?php echo h($code); ?>'><?php echo h($label); ?></option><?php endforeach; ?>`;
  const r=document.createElement('tr');
  // Vorlaeufige Nummer fuer die Vorlesehilfe. Sie kann hier gar nicht sicher
  // stimmen (nach einem Loeschen haette eine bestehende Zeile dieselbe) —
  // renumberPositionen() in index.php zaehlt unmittelbar nach dem Klick alle
  // Zeilen an der sichtbaren Reihenfolge neu durch und richtet das gerade.
  const nr=tb.querySelectorAll('tr').length+1;
  r.innerHTML=`<td><input type='text' name='positionen[${i}][beschreibung]' aria-label='Position ${nr}: Beschreibung' value=''></td>
               <td><select class='plain-select' name='positionen[${i}][einheit]' aria-label='Position ${nr}: Einheit'>${units}</select></td>
               <td><input type='number' step='0.01' inputmode='decimal' name='positionen[${i}][menge]' aria-label='Position ${nr}: Menge' value='0'></td>
               <td><input type='text' name='positionen[${i}][einzelpreis]' aria-label='Position ${nr}: Einzelpreis' value='0,00'></td>
               <td style='width:100px;'><span>0,00 €</span></td>
               <td style='width:10px;' class='noprint pointer'><p role='button' tabindex='0' aria-label='Position ${nr} entfernen' onclick='this.closest(&quot;tr&quot;).remove()'>-</p></td>`;
  tb.appendChild(r);
  const ziel=r.querySelector('input');
  if(ziel) ziel.focus();
" aria-label="Position hinzufügen" title="Position hinzufügen">+</button>

<div class="summary">
  <table>
    <tr><td style="width:140px;text-align:right;"><label for="fld-netto">Nettobetrag:</label></td><td style="width:100px"><input type="text" id="fld-netto" name="zusammenfassung[nettobetrag]" value="<?php echo h(moneyFmt($net).' €'); ?>" readonly></td></tr>
    <tr><td style="text-align:right;"><label for="fld-ust">Umsatzsteuer 19%:</label></td><td><input type="text" id="fld-ust" name="zusammenfassung[umsatzsteuer]" value="<?php echo h(moneyFmt($ustAmt).' €'); ?>" readonly></td></tr>
    <tr class="grau"><td style="text-align:right;"><label for="payableBottom">Gesamtbetrag:</label></td><td><input class="grau" type="text" id="payableBottom" value="<?php echo h(moneyFmt($payableAmt).' €'); ?>" readonly></td></tr>
  </table>
</div>

<div class="footer">
  <input class="danke" type="text" name="zusammenfassung[danke]" aria-label="Schlusstext" value="Vielen Dank für den Auftrag!">
  <input type="text" name="bankverbindung[name]" aria-label="Bankverbindung: Kontoinhaber" value="<?php echo h($payName); ?>">
  <input type="text" name="bankverbindung[bank]" aria-label="Bankverbindung: Bank" value="<?php echo h($bankName); ?>">
  <input type="text" name="bankverbindung[iban]" aria-label="Bankverbindung: IBAN" value="<?php echo h($iban); ?>">
  <input type="text" name="bankverbindung[bic]" aria-label="Bankverbindung: BIC" value="<?php echo h($bic); ?>">
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

