<?php
function ubl_to_cii($ublPath,$ciiPath){
  $d=new DOMDocument(); $d->load($ublPath);
  $xp=new DOMXPath($d);
  $xp->registerNamespace('u','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $s=function($q)use($xp){$r=$xp->evaluate('string('.$q.')');return trim($r);};
  $nf=function($v){return number_format((float)str_replace(',','.',$v),2,'.','');};
  $normType=function($c){
    $c=trim((string)$c);
    $allow=['380','326','384','381'];
    return in_array($c,$allow,true)?$c:'380';
  };
  $normPhone=function($v){
    $v=trim((string)$v);
    $digits=preg_replace('/\D+/','',$v);
    if(strlen($digits)<3) return '000';
    return $digits;
  };

  $id=$s('/u:Invoice/cbc:ID');
  $issue=str_replace('-','',$s('/u:Invoice/cbc:IssueDate'));
  $due=str_replace('-','',$s('/u:Invoice/cbc:DueDate'));
  $delivery=str_replace('-','',$s('/u:Invoice/cac:Delivery/cbc:ActualDeliveryDate'));
  $cur=$s('/u:Invoice/cbc:DocumentCurrencyCode')?:'EUR';
  $buyerRef=$s('/u:Invoice/cbc:BuyerReference');
  $note=$s('/u:Invoice/cbc:Note');
  $invType=$normType($s('/u:Invoice/cbc:InvoiceTypeCode') ?: '380');

  $sName=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
  $sStreet=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $sCity=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $sZip=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $sCountry=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode');
  $sVat=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
  $sEid=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID');
  $sEidScheme=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID/@schemeID');
  $sPhone=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:Telephone');
  $sMail =$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:ElectronicMail');

  if($sMail==='' && $sEid!=='' && filter_var($sEid, FILTER_VALIDATE_EMAIL)) $sMail=$sEid;
  $sPhone=$normPhone($sPhone);

  $bName=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
  $bStreet=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $bCity=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $bZip=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $bCountry=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode');
  $bVat=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
  $bEid=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID');
  $bEidScheme=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID/@schemeID');

  $iban=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
  $bic =$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID');
  $bankName=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:Name');
  $payeeName=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:Name');
  if($payeeName==='') $payeeName=$sName;

  $net =$nf($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
  $payable=$nf($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
  $taxAmt=$nf($s('/u:Invoice/cac:TaxTotal/cbc:TaxAmount')); if($taxAmt==='') $taxAmt='0.00';

  // Felder, die der Konverter früher VERWORFEN hat (Drift zum invoice_core-Weg):
  // Rabatt (BG-20), Vorauszahlung (BT-113), Vorgänger-Referenz (BG-3),
  // Zahlungsart/-referenz (BT-81/BT-83), Zeilensumme (BT-106), Brutto (BT-112).
  $lineExtRaw =$s('/u:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
  $allowRaw   =$s('/u:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount');
  $prepaidRaw =$s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount');
  $taxInclRaw =$s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount');
  $payCode    =$s('/u:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode')?:'58';
  $payId      =$s('/u:Invoice/cac:PaymentMeans/cbc:PaymentID');
  $precedingId=$s('/u:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID');
  $allowVal   =(float)str_replace(',','.',$allowRaw===''?'0':$allowRaw);

  $linesXml=''; $rateBucket=[];
  foreach($xp->query('/u:Invoice/cac:InvoiceLine') as $ln){
    $lid=trim($xp->evaluate('string(cbc:ID)',$ln))?:'1';
    $qty=$nf($xp->evaluate('string(cbc:InvoicedQuantity)',$ln)?:'1');
    $u=trim($xp->evaluate('string(cbc:InvoicedQuantity/@unitCode)',$ln))?:'H87';
    $desc=trim($xp->evaluate('string(cac:Item/cbc:Name)',$ln))?:'Position';
    $price=$nf($xp->evaluate('string(cac:Price/cbc:PriceAmount)',$ln)?:'0');
    $lrate=trim($xp->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)',$ln));
    if($lrate==='') $lrate=trim($xp->evaluate('string(/u:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'));
    $lineTotal=number_format((float)$qty*(float)$price,2,'.','');
    $rateKey=$nf($lrate!==''?$lrate:'0');
    $rateBucket[$rateKey]=($rateBucket[$rateKey]??0)+(float)$lineTotal;

    $linesXml.=
      '<ram:IncludedSupplyChainTradeLineItem>'.
        '<ram:AssociatedDocumentLineDocument><ram:LineID>'.htmlspecialchars($lid).'</ram:LineID></ram:AssociatedDocumentLineDocument>'.
        '<ram:SpecifiedTradeProduct><ram:Name>'.htmlspecialchars($desc).'</ram:Name></ram:SpecifiedTradeProduct>'.
        '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>'.$price.'</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>'.
        '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="'.htmlspecialchars($u).'">'.$qty.'</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>'.
        '<ram:SpecifiedLineTradeSettlement>'.
          '<ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>'.(((float)$rateKey)>0?'S':'Z').'</ram:CategoryCode>'.(((float)$rateKey)>0?'<ram:RateApplicablePercent>'.$rateKey.'</ram:RateApplicablePercent>':'').'</ram:ApplicableTradeTax>'.
          '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>'.$lineTotal.'</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>'.
        '</ram:SpecifiedLineTradeSettlement>'.
      '</ram:IncludedSupplyChainTradeLineItem>';
  }

  // Steuer-Aufschlüsselung bevorzugt aus den UBL-TaxSubtotals übernehmen — die tragen
  // bereits die (bei Rabatt reduzierten) Basen. Das frühere Neu-Rechnen aus den
  // unrabattierten Zeilensummen verletzte bei Rabatten BR-CO-14.
  $taxXml=''; $subBases=[];
  foreach($xp->query('/u:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $sub){
    $taxable=$nf($xp->evaluate('string(cbc:TaxableAmount)',$sub));
    $ta=$nf($xp->evaluate('string(cbc:TaxAmount)',$sub));
    $pr=trim($xp->evaluate('string(cac:TaxCategory/cbc:Percent)',$sub));
    $rateKey=$nf($pr!==''?$pr:'0');
    $subBases[$rateKey]=(float)$taxable;
    $taxXml.=
      '<ram:ApplicableTradeTax>'.
        '<ram:CalculatedAmount>'.$ta.'</ram:CalculatedAmount>'.
        '<ram:TypeCode>VAT</ram:TypeCode>'.
        '<ram:BasisAmount>'.$taxable.'</ram:BasisAmount>'.
        '<ram:CategoryCode>'.(((float)$rateKey)>0?'S':'Z').'</ram:CategoryCode>'.
        (((float)$rateKey)>0?'<ram:RateApplicablePercent>'.$rateKey.'</ram:RateApplicablePercent>':'').
      '</ram:ApplicableTradeTax>';
  }
  if($taxXml===''){
    foreach($rateBucket as $rate=>$basis){
      $basisFmt=number_format($basis,2,'.','');
      $calc=number_format(((float)$rate)<=0?0.00:($basis*((float)$rate/100)),2,'.','');
      $taxXml.=
        '<ram:ApplicableTradeTax>'.
          '<ram:CalculatedAmount>'.$calc.'</ram:CalculatedAmount>'.
          '<ram:TypeCode>VAT</ram:TypeCode>'.
          '<ram:BasisAmount>'.$basisFmt.'</ram:BasisAmount>'.
          '<ram:CategoryCode>'.(((float)$rate)>0?'S':'Z').'</ram:CategoryCode>'.
          (((float)$rate)>0?'<ram:RateApplicablePercent>'.htmlspecialchars((string)$rate).'</ram:RateApplicablePercent>':'').
        '</ram:ApplicableTradeTax>';
    }
  }
  if($taxXml===''){
    $taxXml=
      '<ram:ApplicableTradeTax>'.
        '<ram:CalculatedAmount>'.$taxAmt.'</ram:CalculatedAmount>'.
        '<ram:TypeCode>VAT</ram:TypeCode>'.
        '<ram:BasisAmount>'.$net.'</ram:BasisAmount>'.
        '<ram:CategoryCode>'.(((float)$taxAmt)>0?'S':'Z').'</ram:CategoryCode>'.
        (((float)$taxAmt)>0?'<ram:RateApplicablePercent>'.htmlspecialchars($xp->evaluate('string(/u:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)')).'</ram:RateApplicablePercent>':'').
      '</ram:ApplicableTradeTax>';
  }

  // Dokument-Rabatt (BG-20): je Steuersatz ein Abschlag (ChargeIndicator=false).
  // Anteil = unrabattierte Zeilensumme minus reduzierte Basis aus dem Subtotal.
  // Ohne diese Elemente verwarf der Konverter den Rabatt komplett (BR-CO-10/13).
  $allowanceChargeXml=''; $allowanceTotalXml='';
  if($allowVal>0.005){
    foreach($rateBucket as $rate=>$basis){
      $orig=round((float)$basis,2);
      $red=array_key_exists($rate,$subBases)?round($subBases[$rate],2):$orig;
      $cut=round($orig-$red,2);
      if($cut<=0) continue;
      $allowanceChargeXml.=
        '<ram:SpecifiedTradeAllowanceCharge>'.
          '<ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>'.
          '<ram:ActualAmount>'.$nf($cut).'</ram:ActualAmount>'.
          '<ram:Reason>Rabatt</ram:Reason>'.
          '<ram:CategoryTradeTax>'.
            '<ram:TypeCode>VAT</ram:TypeCode>'.
            '<ram:CategoryCode>'.(((float)$rate)>0?'S':'Z').'</ram:CategoryCode>'.
            (((float)$rate)>0?'<ram:RateApplicablePercent>'.htmlspecialchars((string)$rate).'</ram:RateApplicablePercent>':'').
          '</ram:CategoryTradeTax>'.
        '</ram:SpecifiedTradeAllowanceCharge>';
    }
    $allowanceTotalXml='<ram:AllowanceTotalAmount>'.$nf($allowVal).'</ram:AllowanceTotalAmount>';
  }

  $termsXml=$due!==''?'<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">'.$due.'</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>':'';
  $grand=$taxInclRaw!==''?$nf($taxInclRaw):$nf(((float)$net+(float)$taxAmt));
  $lineTotalOut=$lineExtRaw!==''?$nf($lineExtRaw):$net;
  $prepaidOut=$prepaidRaw!==''?$nf($prepaidRaw):'0.00';

  $sellerContact=
    '<ram:DefinedTradeContact><ram:PersonName>'.htmlspecialchars($sName).'</ram:PersonName>'
      .'<ram:TelephoneUniversalCommunication><ram:CompleteNumber>'.htmlspecialchars($sPhone).'</ram:CompleteNumber></ram:TelephoneUniversalCommunication>'
      .($sMail!==''?'<ram:EmailURIUniversalCommunication><ram:URIID>'.htmlspecialchars($sMail).'</ram:URIID></ram:EmailURIUniversalCommunication>':'')
    .'</ram:DefinedTradeContact>';

  $sellerParty=
    '<ram:SellerTradeParty>'
      .'<ram:Name>'.htmlspecialchars($sName).'</ram:Name>'
      .$sellerContact
      .'<ram:PostalTradeAddress><ram:PostcodeCode>'.htmlspecialchars($sZip).'</ram:PostcodeCode><ram:LineOne>'.htmlspecialchars($sStreet).'</ram:LineOne><ram:CityName>'.htmlspecialchars($sCity).'</ram:CityName><ram:CountryID>'.htmlspecialchars($sCountry).'</ram:CountryID></ram:PostalTradeAddress>'
      .($sEid!==''?'<ram:URIUniversalCommunication><ram:URIID'.($sEidScheme!==''?' schemeID="'.htmlspecialchars($sEidScheme).'"':'').'>'.htmlspecialchars($sEid).'</ram:URIID></ram:URIUniversalCommunication>':'')
      .($sVat!==''?'<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.htmlspecialchars($sVat).'</ram:ID></ram:SpecifiedTaxRegistration>':'')
    .'</ram:SellerTradeParty>';

  $buyerParty=
    '<ram:BuyerTradeParty>'
      .'<ram:Name>'.htmlspecialchars($bName).'</ram:Name>'
      .'<ram:PostalTradeAddress><ram:PostcodeCode>'.htmlspecialchars($bZip).'</ram:PostcodeCode><ram:LineOne>'.htmlspecialchars($bStreet).'</ram:LineOne><ram:CityName>'.htmlspecialchars($bCity).'</ram:CityName><ram:CountryID>'.htmlspecialchars($bCountry).'</ram:CountryID></ram:PostalTradeAddress>'
      .($bEid!==''?'<ram:URIUniversalCommunication><ram:URIID'.($bEidScheme!==''?' schemeID="'.htmlspecialchars($bEidScheme).'"':'').'>'.htmlspecialchars($bEid).'</ram:URIID></ram:URIUniversalCommunication>':'')
      .($bVat!==''?'<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.htmlspecialchars($bVat).'</ram:ID></ram:SpecifiedTaxRegistration>':'')
    .'</ram:BuyerTradeParty>';

  $context=
    '<rsm:ExchangedDocumentContext>'
      .'<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>urn:fdc:peppol.eu:poacc:billing:3</ram:ID></ram:BusinessProcessSpecifiedDocumentContextParameter>'
      .'<ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>'
    .'</rsm:ExchangedDocumentContext>';

  $noteXml=$note!==''?'<ram:IncludedNote><ram:Content>'.htmlspecialchars($note).'</ram:Content></ram:IncludedNote>':'';

  $accXml='';
  if($iban!==''){
    $accXml='<ram:PayeePartyCreditorFinancialAccount><ram:IBANID>'.htmlspecialchars($iban).'</ram:IBANID>'
      .($payeeName!==''?'<ram:AccountName>'.htmlspecialchars($payeeName).'</ram:AccountName>':'')
      .'</ram:PayeePartyCreditorFinancialAccount>';
  }

  $deliveryXml=$delivery!==''
    ? '<ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">'.$delivery.'</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>'
    : '<ram:ApplicableHeaderTradeDelivery/>';

  $cii='<?xml version="1.0" encoding="UTF-8"?>'
  .'<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'
    .$context
    .'<rsm:ExchangedDocument>'
      .'<ram:ID>'.htmlspecialchars($id).'</ram:ID>'
      .'<ram:TypeCode>'.htmlspecialchars($invType).'</ram:TypeCode>'
      .'<ram:IssueDateTime><udt:DateTimeString format="102">'.$issue.'</udt:DateTimeString></ram:IssueDateTime>'
      .$noteXml
    .'</rsm:ExchangedDocument>'
    .'<rsm:SupplyChainTradeTransaction>'
      .$linesXml
      .'<ram:ApplicableHeaderTradeAgreement>'
        .($buyerRef!==''?'<ram:BuyerReference>'.htmlspecialchars($buyerRef).'</ram:BuyerReference>':'')
        .$sellerParty
        .$buyerParty
      .'</ram:ApplicableHeaderTradeAgreement>'
      .$deliveryXml
      .'<ram:ApplicableHeaderTradeSettlement>'
        .'<ram:PaymentReference>'.htmlspecialchars($id).'</ram:PaymentReference>'
        .'<ram:InvoiceCurrencyCode>'.htmlspecialchars($cur).'</ram:InvoiceCurrencyCode>'
        .'<ram:SpecifiedTradeSettlementPaymentMeans>'
          .'<ram:TypeCode>'.htmlspecialchars($payCode).'</ram:TypeCode>'
          .($payId!==''?'<ram:Information>'.htmlspecialchars($payId).'</ram:Information>':'')
          .$accXml
          .($bic!==''?'<ram:PayeeSpecifiedCreditorFinancialInstitution><ram:BICID>'.htmlspecialchars($bic).'</ram:BICID>'.($bankName!==''?'<ram:Name>'.htmlspecialchars($bankName).'</ram:Name>':'').'</ram:PayeeSpecifiedCreditorFinancialInstitution>':'')
        .'</ram:SpecifiedTradeSettlementPaymentMeans>'
        .$taxXml
        .$allowanceChargeXml
        .$termsXml
        .'<ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
          .'<ram:LineTotalAmount>'.$lineTotalOut.'</ram:LineTotalAmount>'
          .$allowanceTotalXml
          .'<ram:TaxBasisTotalAmount>'.$net.'</ram:TaxBasisTotalAmount>'
          .'<ram:TaxTotalAmount currencyID="'.htmlspecialchars($cur).'">'.$taxAmt.'</ram:TaxTotalAmount>'
          .'<ram:GrandTotalAmount>'.$grand.'</ram:GrandTotalAmount>'
          .'<ram:TotalPrepaidAmount>'.$prepaidOut.'</ram:TotalPrepaidAmount>'
          .'<ram:DuePayableAmount>'.$payable.'</ram:DuePayableAmount>'
        .'</ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
        // BT-25/BT-26: Referenz auf die Vorgängerrechnung (Storno/Korrektur) gehört
        // laut CII-Schema NACH die Summation (wie im invoice_core-Weg, Fix 1.0.10).
        .($precedingId!==''?'<ram:InvoiceReferencedDocument><ram:IssuerAssignedID>'.htmlspecialchars($precedingId).'</ram:IssuerAssignedID></ram:InvoiceReferencedDocument>':'')
      .'</ram:ApplicableHeaderTradeSettlement>'
    .'</rsm:SupplyChainTradeTransaction>'
  .'</rsm:CrossIndustryInvoice>';

  // Byte-genau prüfen: ein Teil-Write (voller Datenträger) wäre eine kaputte XML —
  // die darf nicht als Erfolg durchgehen und nicht liegen bleiben.
  $written=file_put_contents($ciiPath,$cii,LOCK_EX);
  if($written===false || $written!==strlen($cii)){
    if($written!==false) @unlink($ciiPath);
    return false;
  }
  return true;
}



function ubl_to_cii_pferd($ublPath,$ciiPath){
  $d=new DOMDocument(); $d->load($ublPath);
  $xp=new DOMXPath($d);
  $xp->registerNamespace('u','urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $s=function($q)use($xp){$r=$xp->evaluate('string('.$q.')');return trim($r);};
  $nf=function($v){return number_format((float)str_replace(',','.',$v),2,'.','');};
  $normType=function($c){
    $c=trim((string)$c);
    $allow=['380','326','384','381'];
    return in_array($c,$allow,true)?$c:'380';
  };

  $id=$s('/u:Invoice/cbc:ID');
  $issue=str_replace('-','',$s('/u:Invoice/cbc:IssueDate'));
  $due=str_replace('-','',$s('/u:Invoice/cbc:DueDate'));
  $delivery=str_replace('-','',$s('/u:Invoice/cac:Delivery/cbc:ActualDeliveryDate'));
  $cur=$s('/u:Invoice/cbc:DocumentCurrencyCode')?:'EUR';
  $buyerRef=$s('/u:Invoice/cbc:BuyerReference');
  if($buyerRef==='0') $buyerRef='';
  $note=$s('/u:Invoice/cbc:Note');
  $invType=$normType($s('/u:Invoice/cbc:InvoiceTypeCode') ?: '380');

  $sName=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
  $sStreet=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $sCity=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $sZip=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $sCountry=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode');
  $sVat=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
  $sEid=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID');
  $sEidScheme=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID/@schemeID');
  $sPhone=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:Telephone');
  $sMail=$s('/u:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:ElectronicMail');

  $bName=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
  $bStreet=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName');
  $bCity=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName');
  $bZip=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone');
  $bCountry=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode');
  $bVat=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
  $bEid=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID');
  $bEidScheme=$s('/u:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID/@schemeID');

  $iban=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
  $bic=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID');
  $payeeName=$s('/u:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:Name');
  if($payeeName==='') $payeeName=$sName;

  $net=$nf($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
  $payable=$nf($s('/u:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
  $taxAmt=$nf($s('/u:Invoice/cac:TaxTotal/cbc:TaxAmount')); if($taxAmt==='') $taxAmt='0.00';

  $linesXml=''; $rateBucket=[];
  foreach($xp->query('/u:Invoice/cac:InvoiceLine') as $ln){
    $lid=trim($xp->evaluate('string(cbc:ID)',$ln))?:'1';
    $qty=$nf($xp->evaluate('string(cbc:InvoicedQuantity)',$ln)?:'1');
    $u=trim($xp->evaluate('string(cbc:InvoicedQuantity/@unitCode)',$ln))?:'H87';
    $desc=trim($xp->evaluate('string(cac:Item/cbc:Name)',$ln))?:'Position';
    $price=$nf($xp->evaluate('string(cac:Price/cbc:PriceAmount)',$ln)?:'0');
    $lrate=trim($xp->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)',$ln));
    if($lrate==='') $lrate=trim($xp->evaluate('string(/u:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'));
    $lineTotal=number_format((float)$qty*(float)$price,2,'.','');
    $rateKey=$lrate!==''?$lrate:'0';
    $rateBucket[$rateKey]=($rateBucket[$rateKey]??0)+(float)$lineTotal;

    $linesXml.=
      '<ram:IncludedSupplyChainTradeLineItem>'.
        '<ram:AssociatedDocumentLineDocument><ram:LineID>'.htmlspecialchars($lid).'</ram:LineID></ram:AssociatedDocumentLineDocument>'.
        '<ram:SpecifiedTradeProduct><ram:Name>'.htmlspecialchars($desc).'</ram:Name></ram:SpecifiedTradeProduct>'.
        '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>'.$price.'</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>'.
        '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="'.htmlspecialchars($u).'">'.$qty.'</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>'.
        '<ram:SpecifiedLineTradeSettlement>'.
          '<ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>'.($rateKey==='0'?'Z':'S').'</ram:CategoryCode>'.($rateKey!=='0'?'<ram:RateApplicablePercent>'.htmlspecialchars($lrate).'</ram:RateApplicablePercent>':'').'</ram:ApplicableTradeTax>'.
          '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>'.$lineTotal.'</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>'.
        '</ram:SpecifiedLineTradeSettlement>'.
      '</ram:IncludedSupplyChainTradeLineItem>';
  }

  $taxXml='';
  foreach($rateBucket as $rate=>$basis){
    $basisFmt=number_format($basis,2,'.','');
    $calc=number_format($rate==='0'?0.00:($basis*((float)$rate/100)),2,'.','');
    $taxXml.=
      '<ram:ApplicableTradeTax>'.
        '<ram:CalculatedAmount>'.$calc.'</ram:CalculatedAmount>'.
        '<ram:TypeCode>VAT</ram:TypeCode>'.
        '<ram:BasisAmount>'.$basisFmt.'</ram:BasisAmount>'.
        '<ram:CategoryCode>'.($rate==='0'?'Z':'S').'</ram:CategoryCode>'.
        ($rate==='0'?'':'<ram:RateApplicablePercent>'.htmlspecialchars($rate).'</ram:RateApplicablePercent>').
      '</ram:ApplicableTradeTax>';
  }
  if($taxXml===''){
    $taxXml=
      '<ram:ApplicableTradeTax>'.
        '<ram:CalculatedAmount>'.$taxAmt.'</ram:CalculatedAmount>'.
        '<ram:TypeCode>VAT</ram:TypeCode>'.
        '<ram:BasisAmount>'.$net.'</ram:BasisAmount>'.
        '<ram:CategoryCode>'.(((float)$taxAmt)>0?'S':'Z').'</ram:CategoryCode>'.
        (((float)$taxAmt)>0?'<ram:RateApplicablePercent>'.htmlspecialchars($xp->evaluate('string(/u:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)')).'</ram:RateApplicablePercent>':'').
      '</ram:ApplicableTradeTax>';
  }

  $termsXml=$due!==''?'<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">'.$due.'</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>':'';
  $grand=$nf(((float)$net+(float)$taxAmt));

  $sellerContact=($sPhone!==''||$sMail!=='')
    ? '<ram:DefinedTradeContact><ram:PersonName>'.htmlspecialchars($sName).'</ram:PersonName>'
      .($sPhone!==''?'<ram:TelephoneUniversalCommunication><ram:CompleteNumber>'.htmlspecialchars($sPhone).'</ram:CompleteNumber></ram:TelephoneUniversalCommunication>':'')
      .($sMail!==''?'<ram:EmailURIUniversalCommunication><ram:URIID>'.htmlspecialchars($sMail).'</ram:URIID></ram:EmailURIUniversalCommunication>':'')
      .'</ram:DefinedTradeContact>'
    : '';

  $sellerParty=
    '<ram:SellerTradeParty>'
      .'<ram:Name>'.htmlspecialchars($sName).'</ram:Name>'
      .$sellerContact
      .'<ram:PostalTradeAddress><ram:PostcodeCode>'.htmlspecialchars($sZip).'</ram:PostcodeCode><ram:LineOne>'.htmlspecialchars($sStreet).'</ram:LineOne><ram:CityName>'.htmlspecialchars($sCity).'</ram:CityName><ram:CountryID>'.htmlspecialchars($sCountry).'</ram:CountryID></ram:PostalTradeAddress>'
      .($sEid!==''?'<ram:URIUniversalCommunication><ram:URIID'.($sEidScheme!==''?' schemeID="'.htmlspecialchars($sEidScheme).'"':'').'>'.htmlspecialchars($sEid).'</ram:URIID></ram:URIUniversalCommunication>':'')
      .($sVat!==''?'<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.htmlspecialchars($sVat).'</ram:ID></ram:SpecifiedTaxRegistration>':'')
    .'</ram:SellerTradeParty>';

  $buyerParty=
    '<ram:BuyerTradeParty>'
      .'<ram:Name>'.htmlspecialchars($bName).'</ram:Name>'
      .'<ram:PostalTradeAddress><ram:PostcodeCode>'.htmlspecialchars($bZip).'</ram:PostcodeCode><ram:LineOne>'.htmlspecialchars($bStreet).'</ram:LineOne><ram:CityName>'.htmlspecialchars($bCity).'</ram:CityName><ram:CountryID>'.htmlspecialchars($bCountry).'</ram:CountryID></ram:PostalTradeAddress>'
      .($bEid!==''?'<ram:URIUniversalCommunication><ram:URIID'.($bEidScheme!==''?' schemeID="'.htmlspecialchars($bEidScheme).'"':'').'>'.htmlspecialchars($bEid).'</ram:URIID></ram:URIUniversalCommunication>':'')
      .($bVat!==''?'<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.htmlspecialchars($bVat).'</ram:ID></ram:SpecifiedTaxRegistration>':'')
    .'</ram:BuyerTradeParty>';

  $context=
    '<rsm:ExchangedDocumentContext>'
      .'<ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>'
    .'</rsm:ExchangedDocumentContext>';

  $noteXml=$note!==''?'<ram:IncludedNote><ram:Content>'.htmlspecialchars($note).'</ram:Content></ram:IncludedNote>':'';

  $accXml='';
  if($iban!==''){
    $accXml='<ram:PayeePartyCreditorFinancialAccount><ram:IBANID>'.htmlspecialchars($iban).'</ram:IBANID>'
      .($payeeName!==''?'<ram:AccountName>'.htmlspecialchars($payeeName).'</ram:AccountName>':'')
      .'</ram:PayeePartyCreditorFinancialAccount>';
  }

  $deliveryXml=$delivery!==''
    ? '<ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">'.$delivery.'</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>'
    : '<ram:ApplicableHeaderTradeDelivery/>';

  $cii='<?xml version="1.0" encoding="UTF-8"?>'
  .'<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'
    .$context
    .'<rsm:ExchangedDocument>'
      .'<ram:ID>'.htmlspecialchars($id).'</ram:ID>'
      .'<ram:TypeCode>'.htmlspecialchars($invType).'</ram:TypeCode>'
      .'<ram:IssueDateTime><udt:DateTimeString format="102">'.$issue.'</udt:DateTimeString></ram:IssueDateTime>'
      .$noteXml
    .'</rsm:ExchangedDocument>'
    .'<rsm:SupplyChainTradeTransaction>'
      .$linesXml
      .'<ram:ApplicableHeaderTradeAgreement>'
        .($buyerRef!==''?'<ram:BuyerReference>'.htmlspecialchars($buyerRef).'</ram:BuyerReference>':'')
        .$sellerParty
        .$buyerParty
      .'</ram:ApplicableHeaderTradeAgreement>'
      .$deliveryXml
      .'<ram:ApplicableHeaderTradeSettlement>'
        .'<ram:PaymentReference>'.htmlspecialchars($id).'</ram:PaymentReference>'
        .'<ram:InvoiceCurrencyCode>'.htmlspecialchars($cur).'</ram:InvoiceCurrencyCode>'
        .'<ram:SpecifiedTradeSettlementPaymentMeans>'
          .'<ram:TypeCode>58</ram:TypeCode>'
          .$accXml
          .($bic!==''?'<ram:PayeeSpecifiedCreditorFinancialInstitution><ram:BICID>'.htmlspecialchars($bic).'</ram:BICID></ram:PayeeSpecifiedCreditorFinancialInstitution>':'')
        .'</ram:SpecifiedTradeSettlementPaymentMeans>'
        .$taxXml
        .$termsXml
        .'<ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
          .'<ram:LineTotalAmount>'.$net.'</ram:LineTotalAmount>'
          .'<ram:TaxBasisTotalAmount>'.$net.'</ram:TaxBasisTotalAmount>'
          .'<ram:TaxTotalAmount currencyID="'.htmlspecialchars($cur).'">'.$taxAmt.'</ram:TaxTotalAmount>'
          .'<ram:GrandTotalAmount>'.$grand.'</ram:GrandTotalAmount>'
          .'<ram:DuePayableAmount>'.$payable.'</ram:DuePayableAmount>'
        .'</ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
      .'</ram:ApplicableHeaderTradeSettlement>'
    .'</rsm:SupplyChainTradeTransaction>'
  .'</rsm:CrossIndustryInvoice>';

  return file_put_contents($ciiPath,$cii)!==false;
}
