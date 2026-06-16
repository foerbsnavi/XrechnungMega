<?php
/**
 * invoice_core.php – Gemeinsame Rechnungs-Logik
 * Wird von api.php verwendet (nicht von save.php, die bleibt unverändert).
 */

declare(strict_types=1);

// ── Hilfsfunktionen ────────────────────────────────────────────────────────

function xr_clean_currency(mixed $v): string {
    $v = str_replace(['€', ' '], '', trim((string)$v));
    $v = str_replace(',', '.', $v);
    $v = preg_replace('/[^0-9.\-]/', '', $v);
    return is_numeric($v) ? $v : '0';
}

function xr_iso_date(mixed $d): string {
    $d = trim((string)$d);
    if ($d === '') return '';
    // Bereits ISO?
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    // Deutsches Format
    $o = DateTime::createFromFormat('d.m.Y', $d);
    if ($o) return $o->format('Y-m-d');
    return '';
}

function xr_s2(mixed $n): string {
    return number_format((float)$n, 2, '.', '');
}

function xr_unit_code(mixed $v): string {
    $c = strtoupper(trim((string)$v));
    $allow = ['HUR', 'H87', 'C62', 'LS', 'DAY', 'WEE', 'MON', 'ANN', 'MIN', 'SEC'];
    return in_array($c, $allow, true) ? $c : 'HUR';
}

function xr_invoice_type_code(mixed $v): string {
    $c = trim((string)$v);
    return in_array($c, ['380', '326', '384', '381'], true) ? $c : '380';
}

function xr_valid_bic(mixed $bic): bool {
    return (bool)preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper((string)$bic));
}

function xr_valid_ust(mixed $id): bool {
    $id = strtoupper(trim((string)$id));
    return $id === '' || (bool)preg_match('/^DE[0-9]{9}$/', $id);
}

function xr_valid_iban(mixed $iban): bool {
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) return false;
    $r = substr($iban, 4) . substr($iban, 0, 4);
    $n = '';
    for ($i = 0, $l = strlen($r); $i < $l; $i++)
        $n .= ctype_alpha($r[$i]) ? (ord($r[$i]) - 55) : $r[$i];
    $m = 0;
    for ($i = 0, $l = strlen($n); $i < $l; $i++)
        $m = ($m * 10 + (int)$n[$i]) % 97;
    return $m === 1;
}

function xr_norm_phone(mixed $v): string {
    $v = trim((string)$v);
    $digits = preg_replace('/\D+/', '', $v);
    return strlen($digits) < 3 ? '000' : $digits;
}

function xr_money_parse(mixed $v): float {
    $s = trim((string)$v);
    $s = preg_replace('/[^0-9,.\-]/', '', $s);
    $s = str_replace(',', '.', $s);
    return (float)$s;
}

// ── Status-JSON ─────────────────────────────────────────────────────────────

function xr_load_status(): array {
    if (!defined('STATUS_FILE') || !is_file(STATUS_FILE)) return [];
    $h = @fopen(STATUS_FILE, 'r');
    if (!$h) return [];
    @flock($h, LOCK_SH);
    $c = stream_get_contents($h);
    @flock($h, LOCK_UN);
    @fclose($h);
    $a = json_decode((string)$c, true);
    return is_array($a) ? $a : [];
}

function xr_save_status(array $map): bool {
    if (!defined('STATUS_FILE')) return false;
    $tmp = STATUS_FILE . '.tmp';
    $h = @fopen($tmp, 'w');
    if (!$h) return false;
    @flock($h, LOCK_EX);
    fwrite($h, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @flock($h, LOCK_UN);
    @fclose($h);
    return rename($tmp, STATUS_FILE);
}

// ── Rechnungs-Metadaten (für Liste) ─────────────────────────────────────────

function xr_invoice_list_meta(string $path): array {
    $typeMap = ['380' => 'Rechnung', '326' => 'Teilrechnung', '384' => 'Korrigierte Rechnung', '381' => 'Gutschrift'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->load($path, LIBXML_NONET)) return ['', '', '', '', ''];

    $root  = $dom->documentElement;
    $local = $root ? (string)$root->localName : '';

    if ($local === 'CrossIndustryInvoice') {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
        $s = fn($q) => trim($xp->evaluate('string(' . $q . ')'));

        $id  = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
        $d   = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        $iso = preg_match('/^\d{8}$/', $d) ? (substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)) : $d;
        $emp = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name');
        $sum = $s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount');
        $tc  = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');
        return [$id, $iso, $emp, $sum, $typeMap[$tc] ?? $tc];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $s = fn($q) => trim((string)$xp->evaluate('string(' . $q . ')'));

    $id  = $s('/inv:Invoice/cbc:ID');
    $iso = $s('/inv:Invoice/cbc:IssueDate');
    $emp = $s('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
    $sum = $s('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount');
    $tc  = $s('/inv:Invoice/cbc:InvoiceTypeCode');
    return [$id, $iso, $emp, $sum, $typeMap[$tc] ?? $tc];
}

// ── Rechnung vollständig parsen → Array ─────────────────────────────────────

function xr_parse_invoice(string $path): ?array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->load($path, LIBXML_NONET)) return null;
    $root  = $dom->documentElement;
    $local = $root ? (string)$root->localName : '';

    $result = [
        'rechnungsnummer' => '', 'rechnungsdatum' => '', 'faelligkeitsdatum' => '',
        'leistungsdatum' => '', 'typ' => '380', 'beschreibung' => '', 'buyer_reference' => '',
        'absender'       => ['name' => '', 'adresse' => '', 'plzOrt' => '', 'telefon' => '', 'email' => '', 'ustid' => ''],
        'empfaenger'     => ['name' => '', 'adresse' => '', 'plzOrt' => '', 'email' => ''],
        'positionen'     => [],
        'bankverbindung' => ['iban' => '', 'bic' => '', 'name' => '', 'bank' => ''],
        'zusammenfassung' => ['netto' => 0.0, 'ust' => 0.0, 'brutto' => 0.0, 'rabatt' => 0.0, 'vorauszahlung' => 0.0, 'zahlbetrag' => 0.0],
        'payment'        => ['code' => '58', 'id' => ''],
    ];

    if ($local === 'CrossIndustryInvoice') {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
        $s  = fn($q, $c = null) => trim($xp->evaluate('string(' . $q . ')', $c));
        $d2 = fn($v) => preg_match('/^\d{8}$/', $v) ? (substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2)) : $v;

        $result['rechnungsnummer']    = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
        $result['rechnungsdatum']     = $d2($s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'));
        $result['typ']                = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode') ?: '380';
        $result['beschreibung']       = $s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content');

        $sp = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
        $bp = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';
        $pay = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';

        $result['faelligkeitsdatum']  = $d2($s($pay . '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'));
        $result['leistungsdatum']     = $d2($s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'));

        $zip = $s($sp . '/ram:PostalTradeAddress/ram:PostcodeCode');
        $city = $s($sp . '/ram:PostalTradeAddress/ram:CityName');
        $result['absender'] = [
            'name'    => $s($sp . '/ram:Name'),
            'adresse' => $s($sp . '/ram:PostalTradeAddress/ram:LineOne'),
            'plzOrt'  => trim($zip . ' ' . $city),
            'telefon' => $s($sp . '/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber'),
            'email'   => $s($sp . '/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID'),
            'ustid'   => $s($sp . '/ram:SpecifiedTaxRegistration/ram:ID'),
        ];
        $czip = $s($bp . '/ram:PostalTradeAddress/ram:PostcodeCode');
        $ccity = $s($bp . '/ram:PostalTradeAddress/ram:CityName');
        $result['empfaenger'] = [
            'name'    => $s($bp . '/ram:Name'),
            'adresse' => $s($bp . '/ram:PostalTradeAddress/ram:LineOne'),
            'plzOrt'  => trim($czip . ' ' . $ccity),
            'email'   => $s($bp . '/ram:URIUniversalCommunication/ram:URIID'),
        ];
        $result['bankverbindung'] = [
            'iban' => $s($pay . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'),
            'bic'  => $s($pay . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'),
            'name' => $s($pay . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:AccountName'),
            'bank' => $s($pay . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name'),
        ];
        $sum = $pay . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        $netto = xr_money_parse($s($sum . '/ram:TaxBasisTotalAmount'));
        $ust   = xr_money_parse($s($sum . '/ram:TaxTotalAmount'));
        $result['zusammenfassung'] = [
            'netto'         => round($netto, 2),
            'ust'           => round($ust, 2),
            'brutto'        => round($netto + $ust, 2),
            'rabatt'        => round(xr_money_parse($s($sum . '/ram:AllowanceTotalAmount')), 2),
            'vorauszahlung' => round(xr_money_parse($s($sum . '/ram:PrepaidAmount')), 2),
            'zahlbetrag'    => round(xr_money_parse($s($sum . '/ram:DuePayableAmount')), 2),
        ];
        foreach ($xp->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') as $li) {
            $result['positionen'][] = [
                'beschreibung' => $s('ram:SpecifiedTradeProduct/ram:Name', $li),
                'menge'        => (float)$s('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $li),
                'einheit'      => strtoupper($xp->evaluate('string(ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode)', $li)) ?: 'HUR',
                'einzelpreis'  => round(xr_money_parse($s('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $li)), 4),
                'zeilensumme'  => round(xr_money_parse($s('ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $li)), 2),
            ];
        }
        return $result;
    }

    // UBL
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('u',   'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $s = fn($q, $c = null) => trim($xp->evaluate('string(' . $q . ')', $c));

    $result['rechnungsnummer']   = $s('/u:Invoice/cbc:ID');
    $result['rechnungsdatum']    = $s('/u:Invoice/cbc:IssueDate');
    $result['faelligkeitsdatum'] = $s('/u:Invoice/cbc:DueDate');
    $result['leistungsdatum']    = $s('/u:Invoice/cac:Delivery/cbc:ActualDeliveryDate');
    $result['typ']               = $s('/u:Invoice/cbc:InvoiceTypeCode') ?: '380';
    $result['beschreibung']      = $s('/u:Invoice/cbc:Note');
    $result['buyer_reference']   = $s('/u:Invoice/cbc:BuyerReference');

    $szip = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
    $scity = $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName');
    $result['absender'] = [
        'name'    => $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name'),
        'adresse' => $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName'),
        'plzOrt'  => trim($szip . ' ' . $scity),
        'telefon' => $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:Telephone'),
        'email'   => $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:ElectronicMail'),
        'ustid'   => $s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
    ];
    $czip = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
    $ccity = $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName');
    $result['empfaenger'] = [
        'name'    => $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name'),
        'adresse' => $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName'),
        'plzOrt'  => trim($czip . ' ' . $ccity),
        'email'   => $s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID'),
    ];
    $result['bankverbindung'] = [
        'iban' => $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
        'bic'  => $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID'),
        'name' => $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:Name'),
        'bank' => $s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:Name'),
    ];
    $netto = xr_money_parse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
    $ust   = xr_money_parse($s('/u:Invoice/cac:TaxTotal/cbc:TaxAmount'));
    $result['zusammenfassung'] = [
        'netto'         => round($netto, 2),
        'ust'           => round($ust, 2),
        'brutto'        => round(xr_money_parse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')), 2),
        'rabatt'        => round(xr_money_parse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount')), 2),
        'vorauszahlung' => round(xr_money_parse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount')), 2),
        'zahlbetrag'    => round(xr_money_parse($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount')), 2),
    ];
    foreach ($xp->query('/u:Invoice/cac:InvoiceLine') as $li) {
        $result['positionen'][] = [
            'beschreibung' => $s('cac:Item/cbc:Name', $li) ?: $s('cac:Item/cbc:Description', $li),
            'menge'        => (float)$s('cbc:InvoicedQuantity', $li),
            'einheit'      => strtoupper($xp->evaluate('string(cbc:InvoicedQuantity/@unitCode)', $li)) ?: 'HUR',
            'einzelpreis'  => round(xr_money_parse($s('cac:Price/cbc:PriceAmount', $li)), 4),
            'zeilensumme'  => round(xr_money_parse($s('cbc:LineExtensionAmount', $li)), 2),
        ];
    }
    return $result;
}

// ── Rechnung bauen und speichern ─────────────────────────────────────────────

/**
 * Baut eine XRechnung-konforme XML-Datei aus einem Daten-Array.
 *
 * $data:
 *   rechnungsnummer, rechnungsdatum (ISO), faelligkeitsdatum (ISO),
 *   leistungsdatum (ISO, opt.), typ (opt.), beschreibung (opt.), buyer_reference (opt.),
 *   vorgaenger_rechnung (opt.),
 *   absender { name, adresse, plzOrt, telefon, email, ustid },
 *   empfaenger { name, adresse, plzOrt, email },
 *   positionen [ { beschreibung, menge, einzelpreis, einheit } ... ],
 *   bankverbindung { iban, bic, name, bank },
 *   rabatt (opt.), vorauszahlung (opt.),
 *   payment_code (opt.), payment_id (opt.)
 *
 * $outFile: Zielpfad für die XML-Datei
 *
 * Rückgabe: ['ok'=>true,'file'=>basename,'id'=>rn] oder ['ok'=>false,'error'=>msg]
 */
function xr_build_invoice(array $data, string $outFile): array {
    $xe = 'htmlspecialchars';

    $rn = trim(preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($data['rechnungsnummer'] ?? '')), '_-');
    if ($rn === '') return ['ok' => false, 'error' => 'Rechnungsnummer fehlt oder ungültig'];

    $issueDate   = xr_iso_date($data['rechnungsdatum'] ?? '');
    $dueDate     = xr_iso_date($data['faelligkeitsdatum'] ?? '');
    $serviceDate = xr_iso_date($data['leistungsdatum'] ?? ($data['rechnungsdatum'] ?? ''));

    if ($issueDate === '') return ['ok' => false, 'error' => 'Rechnungsdatum ungültig (erwartet: YYYY-MM-DD)'];
    if ($dueDate === '')   return ['ok' => false, 'error' => 'Fälligkeitsdatum ungültig (erwartet: YYYY-MM-DD)'];

    $abs  = (array)($data['absender']     ?? []);
    $emp  = (array)($data['empfaenger']   ?? []);
    $bank = (array)($data['bankverbindung'] ?? []);

    $iban  = strtoupper(preg_replace('/\s+/', '', (string)($bank['iban'] ?? '')));
    $bic   = strtoupper(preg_replace('/\s+/', '', (string)($bank['bic'] ?? '')));
    $ustid = strtoupper(trim((string)($abs['ustid'] ?? '')));

    if (!xr_valid_iban($iban)) return ['ok' => false, 'error' => 'Ungültige IBAN'];
    if ($bic !== '' && !xr_valid_bic($bic)) return ['ok' => false, 'error' => 'Ungültige BIC'];
    if (!xr_valid_ust($ustid)) return ['ok' => false, 'error' => 'Ungültige USt-IdNr. (erwartet: DE + 9 Ziffern)'];

    $buyerEmail = trim((string)($emp['email'] ?? ''));
    if ($buyerEmail === '') return ['ok' => false, 'error' => 'Empfänger-E-Mail (BT-34) fehlt'];

    // PLZ / Ort trennen
    $abs_plzort = trim((string)($abs['plzOrt'] ?? ''));
    $abs_plz = ''; $abs_ort = '';
    if (preg_match('/^(\d{5})\s*(.*)$/', $abs_plzort, $m)) { $abs_plz = $m[1]; $abs_ort = trim($m[2]); }
    else $abs_ort = $abs_plzort;

    $emp_plzort = trim((string)($emp['plzOrt'] ?? ''));
    $emp_plz = ''; $emp_ort = '';
    if (preg_match('/^(\d{5})\s*(.*)$/', $emp_plzort, $m)) { $emp_plz = $m[1]; $emp_ort = trim($m[2]); }
    else $emp_ort = $emp_plzort;

    $payeeName   = trim((string)($bank['name'] ?? '')) ?: trim((string)($abs['name'] ?? ''));
    $bankName    = trim((string)($bank['bank'] ?? ''));
    $sellerEmail = trim((string)($abs['email'] ?? ''));
    $sellerPhone = xr_norm_phone($abs['telefon'] ?? '');
    $invType     = xr_invoice_type_code($data['typ'] ?? '380');
    $buyerRef    = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string)($data['buyer_reference'] ?? '')) ?: $buyerEmail);
    $note        = trim((string)($data['beschreibung'] ?? ''));
    $payCode     = (string)($data['payment_code'] ?? '58');
    $payId       = trim((string)($data['payment_id'] ?? $rn));
    $prevInvId   = trim((string)($data['vorgaenger_rechnung'] ?? ''));

    // Positionen berechnen
    $pos        = (array)($data['positionen'] ?? []);
    $net        = 0.0;
    $taxBuckets = [];
    $linesXml   = '';
    $idx        = 0;
    $nf         = fn($v) => xr_s2((float)$v);

    foreach ($pos as $p) {
        if (!is_array($p)) continue;
        $desc  = trim((string)($p['beschreibung'] ?? ''));
        $qty   = (float)xr_clean_currency($p['menge'] ?? '0');
        $price = (float)xr_clean_currency($p['einzelpreis'] ?? '0');
        if ($desc === '' && abs($qty) < 0.00001 && abs($price) < 0.00001) continue;
        if ($desc === '') $desc = 'Position';
        $rate    = 19.0;
        $unit    = xr_unit_code((string)($p['einheit'] ?? 'HUR'));
        $lineNet = round($qty * $price, 2);
        $net    += $lineNet;
        $taxBuckets[$rate] = ($taxBuckets[$rate] ?? 0.0) + $lineNet;
        $idx++;
        $linesXml .=
            '<ram:IncludedSupplyChainTradeLineItem>' .
                '<ram:AssociatedDocumentLineDocument><ram:LineID>' . $idx . '</ram:LineID></ram:AssociatedDocumentLineDocument>' .
                '<ram:SpecifiedTradeProduct><ram:Name>' . $xe($desc) . '</ram:Name></ram:SpecifiedTradeProduct>' .
                '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>' . $nf($price) . '</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>' .
                '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="' . $xe($unit) . '">' . $nf($qty) . '</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>' .
                '<ram:SpecifiedLineTradeSettlement>' .
                    '<ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>' . ($rate > 0 ? 'S' : 'Z') . '</ram:CategoryCode>' . ($rate > 0 ? '<ram:RateApplicablePercent>' . $nf($rate) . '</ram:RateApplicablePercent>' : '') . '</ram:ApplicableTradeTax>' .
                    '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . $nf($lineNet) . '</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>' .
                '</ram:SpecifiedLineTradeSettlement>' .
            '</ram:IncludedSupplyChainTradeLineItem>';
    }

    if ($idx === 0) return ['ok' => false, 'error' => 'Keine Positionen angegeben'];

    // Rabatt & Vorauszahlung
    $allow   = max(0.0, min($net, (float)xr_clean_currency($data['rabatt'] ?? '0')));
    $prepaid = max(0.0, (float)xr_clean_currency($data['vorauszahlung'] ?? '0'));

    $taxBases = $taxBuckets;
    if ($allow > 0.0) {
        $rates = array_keys($taxBuckets);
        sort($rates, SORT_NUMERIC);
        $remainAllow = $allow; $remainBase = $net;
        $reduced = []; $sumReduced = 0.0;
        for ($i = 0, $l = count($rates); $i < $l; $i++) {
            $r = $rates[$i]; $b = (float)($taxBuckets[$r] ?? 0.0);
            if ($i === $l - 1) { $reduced[$r] = max(0.0, round(($net - $allow) - $sumReduced, 2)); break; }
            if ($remainBase <= 0.0) { $reduced[$r] = 0.0; continue; }
            $share = $b / $remainBase;
            $cut   = min($b, round($remainAllow * $share, 2));
            $b2    = max(0.0, round($b - $cut, 2));
            $reduced[$r]  = $b2; $sumReduced  += $b2;
            $remainAllow  = round($remainAllow - $cut, 2);
            $remainBase   = round($remainBase - $b, 2);
        }
        $taxBases = $reduced;
    }

    $totalTax = 0.0;
    foreach ($taxBases as $r => $taxable)
        $totalTax += round(((float)$taxable) * ((float)$r) / 100, 2);
    $totalTax = round($totalTax, 2);

    $taxExclusive = max(0.0, round($net - $allow, 2));
    $taxInclusive = round($taxExclusive + $totalTax, 2);
    $payable      = max(0.0, round($taxInclusive - $prepaid, 2));

    // Tax XML
    $taxXml = '';
    foreach ($taxBases as $r => $taxable) {
        $taxAmt = $nf(round(((float)$taxable) * ((float)$r) / 100, 2));
        $taxXml .=
            '<ram:ApplicableTradeTax>' .
                '<ram:CalculatedAmount>' . $taxAmt . '</ram:CalculatedAmount>' .
                '<ram:TypeCode>VAT</ram:TypeCode>' .
                '<ram:BasisAmount>' . $nf($taxable) . '</ram:BasisAmount>' .
                '<ram:CategoryCode>' . (((float)$r) > 0 ? 'S' : 'Z') . '</ram:CategoryCode>' .
                (((float)$r) > 0 ? '<ram:RateApplicablePercent>' . $nf($r) . '</ram:RateApplicablePercent>' : '') .
            '</ram:ApplicableTradeTax>';
    }
    if ($taxXml === '') {
        $taxXml =
            '<ram:ApplicableTradeTax>' .
                '<ram:CalculatedAmount>' . $nf($totalTax) . '</ram:CalculatedAmount>' .
                '<ram:TypeCode>VAT</ram:TypeCode>' .
                '<ram:BasisAmount>' . $nf($net) . '</ram:BasisAmount>' .
                '<ram:CategoryCode>' . ($totalTax > 0 ? 'S' : 'Z') . '</ram:CategoryCode>' .
                ($totalTax > 0 ? '<ram:RateApplicablePercent>19.00</ram:RateApplicablePercent>' : '') .
            '</ram:ApplicableTradeTax>';
    }

    // Absender
    $sellerContactXml =
        '<ram:DefinedTradeContact>' .
            '<ram:PersonName>' . $xe((string)($abs['name'] ?? '')) . '</ram:PersonName>' .
            '<ram:TelephoneUniversalCommunication><ram:CompleteNumber>' . $xe($sellerPhone) . '</ram:CompleteNumber></ram:TelephoneUniversalCommunication>' .
            ($sellerEmail !== '' ? '<ram:EmailURIUniversalCommunication><ram:URIID>' . $xe($sellerEmail) . '</ram:URIID></ram:EmailURIUniversalCommunication>' : '') .
        '</ram:DefinedTradeContact>';

    $sellerPartyXml =
        '<ram:SellerTradeParty>' .
            '<ram:Name>' . $xe((string)($abs['name'] ?? '')) . '</ram:Name>' .
            $sellerContactXml .
            '<ram:PostalTradeAddress>' .
                '<ram:PostcodeCode>' . $xe($abs_plz) . '</ram:PostcodeCode>' .
                '<ram:LineOne>' . $xe((string)($abs['adresse'] ?? '')) . '</ram:LineOne>' .
                '<ram:CityName>' . $xe($abs_ort) . '</ram:CityName>' .
                '<ram:CountryID>DE</ram:CountryID>' .
            '</ram:PostalTradeAddress>' .
            ($sellerEmail !== '' ? '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . $xe($sellerEmail) . '</ram:URIID></ram:URIUniversalCommunication>' : '') .
            ($ustid !== '' ? '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . $xe($ustid) . '</ram:ID></ram:SpecifiedTaxRegistration>' : '') .
        '</ram:SellerTradeParty>';

    // Empfänger
    $buyerVat = strtoupper(trim((string)($emp['ustid'] ?? '')));
    $buyerPartyXml =
        '<ram:BuyerTradeParty>' .
            '<ram:Name>' . $xe((string)($emp['name'] ?? '')) . '</ram:Name>' .
            '<ram:PostalTradeAddress>' .
                '<ram:PostcodeCode>' . $xe($emp_plz) . '</ram:PostcodeCode>' .
                '<ram:LineOne>' . $xe((string)($emp['adresse'] ?? '')) . '</ram:LineOne>' .
                '<ram:CityName>' . $xe($emp_ort) . '</ram:CityName>' .
                '<ram:CountryID>DE</ram:CountryID>' .
            '</ram:PostalTradeAddress>' .
            '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . $xe($buyerEmail) . '</ram:URIID></ram:URIUniversalCommunication>' .
            ($buyerVat !== '' ? '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . $xe($buyerVat) . '</ram:ID></ram:SpecifiedTaxRegistration>' : '') .
        '</ram:BuyerTradeParty>';

    // Zahlungsdaten
    $accXml = $iban !== ''
        ? '<ram:PayeePartyCreditorFinancialAccount>' .
              '<ram:IBANID>' . $xe($iban) . '</ram:IBANID>' .
              ($payeeName !== '' ? '<ram:AccountName>' . $xe($payeeName) . '</ram:AccountName>' : '') .
          '</ram:PayeePartyCreditorFinancialAccount>'
        : '';
    $bicXml = $bic !== ''
        ? '<ram:PayeeSpecifiedCreditorFinancialInstitution>' .
              '<ram:BICID>' . $xe($bic) . '</ram:BICID>' .
              ($bankName !== '' ? '<ram:Name>' . $xe($bankName) . '</ram:Name>' : '') .
          '</ram:PayeeSpecifiedCreditorFinancialInstitution>'
        : '';

    $deliveryXml = $serviceDate !== ''
        ? '<ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">' . str_replace('-', '', $serviceDate) . '</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>'
        : '<ram:ApplicableHeaderTradeDelivery/>';

    $termsXml = $dueDate !== ''
        ? '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">' . str_replace('-', '', $dueDate) . '</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>'
        : '';

    $noteXml = $note !== '' ? '<ram:IncludedNote><ram:Content>' . $xe($note) . '</ram:Content></ram:IncludedNote>' : '';

    // CII-XML direkt zusammenbauen (kein DOMDocument benötigt)
    $cii = '<?xml version="1.0" encoding="UTF-8"?>' .
    '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">' .
        '<rsm:ExchangedDocumentContext>' .
            '<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>urn:fdc:peppol.eu:poacc:billing:3</ram:ID></ram:BusinessProcessSpecifiedDocumentContextParameter>' .
            '<ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>' .
        '</rsm:ExchangedDocumentContext>' .
        '<rsm:ExchangedDocument>' .
            '<ram:ID>' . $xe($rn) . '</ram:ID>' .
            '<ram:TypeCode>' . $xe($invType) . '</ram:TypeCode>' .
            '<ram:IssueDateTime><udt:DateTimeString format="102">' . str_replace('-', '', $issueDate) . '</udt:DateTimeString></ram:IssueDateTime>' .
            $noteXml .
        '</rsm:ExchangedDocument>' .
        '<rsm:SupplyChainTradeTransaction>' .
            $linesXml .
            '<ram:ApplicableHeaderTradeAgreement>' .
                ($buyerRef !== '' ? '<ram:BuyerReference>' . $xe($buyerRef) . '</ram:BuyerReference>' : '') .
                $sellerPartyXml .
                $buyerPartyXml .
            '</ram:ApplicableHeaderTradeAgreement>' .
            $deliveryXml .
            '<ram:ApplicableHeaderTradeSettlement>' .
                '<ram:PaymentReference>' . $xe($rn) . '</ram:PaymentReference>' .
                '<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>' .
                '<ram:SpecifiedTradeSettlementPaymentMeans>' .
                    '<ram:TypeCode>' . $xe($payCode) . '</ram:TypeCode>' .
                    ($payId !== '' ? '<ram:Information>' . $xe($payId) . '</ram:Information>' : '') .
                    $accXml .
                    $bicXml .
                '</ram:SpecifiedTradeSettlementPaymentMeans>' .
                $taxXml .
                $termsXml .
                ($prevInvId !== '' ? '<ram:InvoiceReferencedDocument><ram:IssuerAssignedID>' . $xe($prevInvId) . '</ram:IssuerAssignedID></ram:InvoiceReferencedDocument>' : '') .
                '<ram:SpecifiedTradeSettlementHeaderMonetarySummation>' .
                    '<ram:LineTotalAmount>' . $nf($net) . '</ram:LineTotalAmount>' .
                    '<ram:TaxBasisTotalAmount>' . $nf($taxExclusive) . '</ram:TaxBasisTotalAmount>' .
                    '<ram:TaxTotalAmount currencyID="EUR">' . $nf($totalTax) . '</ram:TaxTotalAmount>' .
                    '<ram:GrandTotalAmount>' . $nf($taxInclusive) . '</ram:GrandTotalAmount>' .
                    '<ram:TotalPrepaidAmount>' . $nf($prepaid) . '</ram:TotalPrepaidAmount>' .
                    '<ram:DuePayableAmount>' . $nf($payable) . '</ram:DuePayableAmount>' .
                '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>' .
            '</ram:ApplicableHeaderTradeSettlement>' .
        '</rsm:SupplyChainTradeTransaction>' .
    '</rsm:CrossIndustryInvoice>';

    if (file_put_contents($outFile, $cii) === false) {
        return ['ok' => false, 'error' => 'XML-Datei konnte nicht gespeichert werden'];
    }

    return ['ok' => true, 'file' => basename($outFile), 'id' => $rn];
}
