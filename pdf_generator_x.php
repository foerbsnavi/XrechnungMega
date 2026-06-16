<?php

function pdf_fetch_url($url){
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_CONNECTTIMEOUT=>4,
      CURLOPT_TIMEOUT=>10,
      CURLOPT_SSL_VERIFYPEER=>true,
      CURLOPT_SSL_VERIFYHOST=>2,
      CURLOPT_USERAGENT=>'XrechnungMega/1.0'
    ]);
    $b=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($b!==false && $code>=200 && $code<300) ? $b : null;
  }
  $ctx=stream_context_create([
    'http'=>['timeout'=>10,'follow_location'=>1,'user_agent'=>'XrechnungMega/1.0'],
    'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]
  ]);
  $b=@file_get_contents($url,false,$ctx);
  return ($b!==false && $b!=='') ? $b : null;
}

function pdf_font_bytes(array $candidates){
  foreach($candidates as $c){
    if(!$c) continue;
    $b=null;
    if(preg_match('~^https?://~i',$c)) $b=pdf_fetch_url($c);
    else $b=@file_get_contents($c);
    if(is_string($b) && strlen($b)>1024) return $b;
  }
  return null;
}

function pdf_name_sanitize($s){
  $s=(string)$s;
  $s=preg_replace('~[^A-Za-z0-9._+\\-]~','-',$s);
  $s=trim($s,'-');
  if($s==='') $s='Font';
  if(preg_match('~^[0-9]~',$s)) $s='F'.$s;
  return $s;
}

function pdf_ttf_winansi_metrics($ttf){
  if(!is_string($ttf) || strlen($ttf)<64) return null;

  $u16=function($o)use($ttf){ return (ord($ttf[$o])<<8)|ord($ttf[$o+1]); };
  $i16=function($o)use($u16){ $v=$u16($o); return $v>=0x8000?$v-0x10000:$v; };
  $u32=function($o)use($ttf){ return (ord($ttf[$o])<<24)|(ord($ttf[$o+1])<<16)|(ord($ttf[$o+2])<<8)|ord($ttf[$o+3]); };
  $i32=function($o)use($u32){ $v=$u32($o); return $v>=0x80000000?$v-0x100000000:$v; };

  $numTables=$u16(4);
  $tables=[];
  $len=strlen($ttf);
  for($i=0;$i<$numTables;$i++){
    $rec=12+$i*16;
    if($rec+16>$len) break;
    $tag=substr($ttf,$rec,4);
    $off=$u32($rec+8);
    $l=$u32($rec+12);
    if($off>0 && $l>0 && ($off+$l)<=$len) $tables[$tag]=[$off,$l];
  }

  foreach(['head','hhea','maxp','hmtx','cmap'] as $t) if(!isset($tables[$t])) return null;

  [$headOff,$headLen]=$tables['head'];
  $unitsPerEm=$u16($headOff+18);
  if($unitsPerEm<=0) return null;
  $xMin=$i16($headOff+36); $yMin=$i16($headOff+38); $xMax=$i16($headOff+40); $yMax=$i16($headOff+42);

  [$hheaOff,$hheaLen]=$tables['hhea'];
  $ascent=$i16($hheaOff+4);
  $descent=$i16($hheaOff+6);
  $numHMetrics=$u16($hheaOff+34);

  [$maxpOff,$maxpLen]=$tables['maxp'];
  $numGlyphs=$u16($maxpOff+4);
  if($numGlyphs<=0) return null;

  [$hmtxOff,$hmtxLen]=$tables['hmtx'];
  $adv=[];
  $pos=$hmtxOff;
  $last=0;
  for($g=0;$g<$numGlyphs;$g++){
    if($g<$numHMetrics && ($pos+4)<=($hmtxOff+$hmtxLen)){
      $last=$u16($pos);
      $pos+=4;
    }
    $adv[$g]=$last;
  }

  $italicAngle=0.0;
  if(isset($tables['post'])){
    [$postOff,$postLen]=$tables['post'];
    if($postLen>=8){
      $italicAngle=$i32($postOff+4)/65536.0;
    }
  }

  $capHeight=$ascent;
  if(isset($tables['OS/2'])){
    [$os2Off,$os2Len]=$tables['OS/2'];
    if($os2Len>=90){
      $ver=$u16($os2Off);
      if($ver>=2){
        $capHeight=$i16($os2Off+88);
        if($capHeight===0) $capHeight=$ascent;
      }
    }
  }

  $psName='Brose';
  if(isset($tables['name'])){
    [$nameOff,$nameLen]=$tables['name'];
    if($nameLen>=6){
      $count=$u16($nameOff+2);
      $strOff=$u16($nameOff+4);
      $best=null;
      for($i=0;$i<$count;$i++){
        $r=$nameOff+6+$i*12;
        if(($r+12)>($nameOff+$nameLen)) break;
        $pid=$u16($r); $eid=$u16($r+2); $lid=$u16($r+4); $nid=$u16($r+6);
        $l=$u16($r+8); $o=$u16($r+10);
        if($nid!==6 || $l<=0) continue;
        $so=$nameOff+$strOff+$o;
        if(($so+$l)>($nameOff+$nameLen)) continue;
        $raw=substr($ttf,$so,$l);
        $txt='';
        if($pid===3) $txt=@iconv('UTF-16BE','UTF-8//IGNORE',$raw);
        elseif($pid===1) $txt=@iconv('Macintosh','UTF-8//IGNORE',$raw);
        if(!is_string($txt) || $txt==='') continue;
        $txt=pdf_name_sanitize($txt);
        if($pid===3 && ($eid===1 || $eid===10)){ $best=$txt; break; }
        if($best===null) $best=$txt;
      }
      if($best!==null) $psName=$best;
    }
  }
  $psName=pdf_name_sanitize($psName);

  [$cmapOff,$cmapLen]=$tables['cmap'];
  $subCount=$u16($cmapOff+2);
  $chosen=null;
  for($i=0;$i<$subCount;$i++){
    $r=$cmapOff+4+$i*8;
    if(($r+8)>($cmapOff+$cmapLen)) break;
    $pid=$u16($r); $eid=$u16($r+2); $subOff=$u32($r+4);
    $abs=$cmapOff+$subOff;
    if($abs+2>($cmapOff+$cmapLen)) continue;
    $fmt=$u16($abs);
    if($fmt===12 && $pid===3 && $eid===10){ $chosen=['fmt'=>12,'off'=>$abs]; break; }
    if($chosen===null && $fmt===4 && $pid===3 && $eid===1) $chosen=['fmt'=>4,'off'=>$abs];
    if($chosen===null && ($pid===0) && ($fmt===4 || $fmt===12)) $chosen=['fmt'=>$fmt,'off'=>$abs];
  }
  if(!$chosen) return null;

  $glyphIndex=function($uni)use($ttf,$u16,$u32,$chosen){
    $off=$chosen['off']; $fmt=$chosen['fmt'];
    if($fmt===12){
      $nGroups=$u32($off+12);
      $gOff=$off+16;
      for($i=0;$i<$nGroups;$i++){
        $p=$gOff+$i*12;
        $start=$u32($p); $end=$u32($p+4); $startGlyph=$u32($p+8);
        if($uni<$start) break;
        if($uni<=$end) return (int)($startGlyph+($uni-$start));
      }
      return 0;
    }
    $segCount=(int)($u16($off+6)/2);
    $endOff=$off+14;
    $startOff=$endOff+2*$segCount+2;
    $deltaOff=$startOff+2*$segCount;
    $rangeOff=$deltaOff+2*$segCount;
    for($i=0;$i<$segCount;$i++){
      $end=$u16($endOff+2*$i);
      if($uni>$end) continue;
      $start=$u16($startOff+2*$i);
      if($uni<$start) return 0;
      $delta=$u16($deltaOff+2*$i);
      $delta=$delta>=0x8000?$delta-0x10000:$delta;
      $ro=$u16($rangeOff+2*$i);
      if($ro===0) return (int)(($uni+$delta)&0xFFFF);
      $glyphAddr=($rangeOff+2*$i)+$ro+2*($uni-$start);
      if($glyphAddr+2>strlen($ttf)) return 0;
      $g=$u16($glyphAddr);
      if($g===0) return 0;
      return (int)(($g+$delta)&0xFFFF);
    }
    return 0;
  };

  static $cp1252=null;
  if($cp1252===null){
    $cp1252=[];
    for($i=0;$i<256;$i++) $cp1252[$i]=$i;
    $cp1252[0x80]=0x20AC; $cp1252[0x82]=0x201A; $cp1252[0x83]=0x0192; $cp1252[0x84]=0x201E;
    $cp1252[0x85]=0x2026; $cp1252[0x86]=0x2020; $cp1252[0x87]=0x2021; $cp1252[0x88]=0x02C6;
    $cp1252[0x89]=0x2030; $cp1252[0x8A]=0x0160; $cp1252[0x8B]=0x2039; $cp1252[0x8C]=0x0152;
    $cp1252[0x8E]=0x017D; $cp1252[0x91]=0x2018; $cp1252[0x92]=0x2019; $cp1252[0x93]=0x201C;
    $cp1252[0x94]=0x201D; $cp1252[0x95]=0x2022; $cp1252[0x96]=0x2013; $cp1252[0x97]=0x2014;
    $cp1252[0x98]=0x02DC; $cp1252[0x99]=0x2122; $cp1252[0x9A]=0x0161; $cp1252[0x9B]=0x203A;
    $cp1252[0x9C]=0x0153; $cp1252[0x9E]=0x017E; $cp1252[0x9F]=0x0178;
  }

  $to1000=function($v)use($unitsPerEm){ return (int)round(((float)$v)*1000.0/(float)$unitsPerEm); };

  $widths=[];
  $wmap=[];
  $missing=$to1000($adv[0] ?? 0);
  for($c=32;$c<=255;$c++){
    $uni=$cp1252[$c] ?? $c;
    $gid=$glyphIndex($uni);
    $aw=$adv[$gid] ?? ($adv[0] ?? 0);
    $w=$to1000($aw);
    $widths[]=$w;
    $wmap[$c]=$w;
  }

  return [
    'psName'=>$psName,
    'unitsPerEm'=>$unitsPerEm,
    'bbox'=>[$to1000($xMin),$to1000($yMin),$to1000($xMax),$to1000($yMax)],
    'ascent'=>$to1000($ascent),
    'descent'=>$to1000($descent),
    'capHeight'=>$to1000($capHeight),
    'italicAngle'=>$italicAngle,
    'missingWidth'=>$missing,
    'widths'=>$widths,
    'wmap'=>$wmap
  ];
}

function facturx_xmp($fname,$level='EN 16931'){
  $d=gmdate('Y-m-d\TH:i:s\Z');
  $ns='urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';
  return '<?xpacket begin="\ufeff" id="W5M0MpCehiHzreSzNTczkc9d"?>'
  .'<x:xmpmeta xmlns:x="adobe:ns:meta/">'
  .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#" xmlns:pdf="http://ns.adobe.com/pdf/1.3/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:fx="'.$ns.'">'
  .'<rdf:Description rdf:about="" pdfaid:part="3" pdfaid:conformance="B"/>'
  .'<rdf:Description rdf:about="" pdf:Producer="php" xmp:CreatorTool="php" xmp:CreateDate="'.$d.'" xmp:ModifyDate="'.$d.'"/>'
  .'<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/">'
    .'<pdfaExtension:schemas>'
      .'<rdf:Bag>'
        .'<rdf:li rdf:parseType="Resource">'
          .'<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
          .'<pdfaSchema:namespaceURI>'.$ns.'</pdfaSchema:namespaceURI>'
          .'<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
          .'<pdfaSchema:property>'
            .'<rdf:Seq>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentFileName</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>name of the embedded XML invoice file</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentType</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>type of document</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>Version</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>Factur-X version</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>ConformanceLevel</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>Factur-X conformance level</pdfaProperty:description></rdf:li>'
            .'</rdf:Seq>'
          .'</pdfaSchema:property>'
        .'</rdf:li>'
      .'</rdf:Bag>'
    .'</pdfaExtension:schemas>'
  .'</rdf:Description>'
  .'<rdf:Description rdf:about="" xmlns:fx="'.$ns.'">'
    .'<fx:ConformanceLevel>'.htmlspecialchars($level,ENT_QUOTES).'</fx:ConformanceLevel>'
    .'<fx:DocumentFileName>'.htmlspecialchars($fname,ENT_QUOTES).'</fx:DocumentFileName>'
    .'<fx:DocumentType>INVOICE</fx:DocumentType>'
    .'<fx:Version>1.0</fx:Version>'
  .'</rdf:Description>'
  .'</rdf:RDF>'
  .'</x:xmpmeta>'
  .'<?xpacket end="w"?>';
}

function cii_xml_sanitize_for_embed($xml){
  if(!is_string($xml) || $xml==='') return $xml;

  libxml_use_internal_errors(true);
  $dom=new DOMDocument();
  if(!$dom->loadXML($xml, LIBXML_NONET)) return $xml;

  $xp=new DOMXPath($dom);
  $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

  foreach($xp->query('//ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name') as $n){
    if($n && $n->parentNode) $n->parentNode->removeChild($n);
  }

  $out=$dom->saveXML();
  return (is_string($out) && $out!=='') ? $out : $xml;
}

function facturx_pdf($xmlPath, $pdfPath=null, $displayName=null){
  $xml=@file_get_contents($xmlPath); if($xml===false) return false;

  $dir=dirname($xmlPath);
  $xmlName = $displayName ?: basename($xmlPath);
  $title   = preg_replace('/\.xml$/','',$xmlName);
  if($pdfPath===null) $pdfPath = $dir.'/'.$title.'.pdf';

  $tr=function($s){
    $s=(string)$s;
    $s=str_replace(["\r","\n","\t"],[' ',' ',' '],$s);
    $s=preg_replace('/\s+/',' ',$s);
    return trim($s);
  };

  $esc=function($s){
    $s=(string)$s;
    $s=@iconv('UTF-8','Windows-1252//TRANSLIT',$s);
    if($s===false) $s='';
    return str_replace(['\\','(',')'],['\\\\','\(','\)'],$s);
  };

  $inv=[
    'id'=>$title,'type'=>'380','issue'=>'','due'=>'','service'=>'',
    'seller'=>['name'=>'','street'=>'','zip'=>'','city'=>'','tel'=>'','mail'=>'','vat'=>''],
    'buyer'=>['name'=>'','street'=>'','zip'=>'','city'=>'','mail'=>''],
    'bank'=>['owner'=>'','bank'=>'','iban'=>'','bic'=>''],
    'note'=>'',
    'net'=>'0.00','tax'=>'0.00','gross'=>'0.00','payable'=>'0.00',
    'lines'=>[]
  ];

  libxml_use_internal_errors(true);
  $dom=new DOMDocument();
  if($dom->loadXML($xml, LIBXML_NONET)){
    $root = $dom->documentElement ? $dom->documentElement->localName : '';

    if($root==='Invoice'){
      $sx=simplexml_import_dom($dom);
      $ns=$sx->getNamespaces(true);
      $cbc=$sx->children($ns['cbc'] ?? null);
      $cac=$sx->children($ns['cac'] ?? null);

      $inv['id']=$tr($cbc->ID ?? $title);
      $inv['issue']=$tr($cbc->IssueDate ?? '');
      $inv['due']=$tr($cbc->DueDate ?? '');
      $inv['note']=$tr($cbc->Note ?? '');
      $inv['type']=$tr($cbc->InvoiceTypeCode ?? '380');

      $supp=$cac->AccountingSupplierParty ?? null;
      $cust=$cac->AccountingCustomerParty ?? null;
      $pay =$cac->PaymentMeans ?? null;
      $legal=$cac->LegalMonetaryTotal ?? null;
      $tax =$cac->TaxTotal ?? null;

      $del=$cac->Delivery ?? null;
      $inv['service']=$tr($del ? ($del->children($ns['cbc'] ?? null)->ActualDeliveryDate ?? '') : '');

      $p=$supp ? $supp->children($ns['cac'] ?? null)->Party : null;
      $inv['seller']['name']=$tr($p?($p->PartyName->children($ns['cbc'] ?? null)->Name ?? ''):'');
      $inv['seller']['street']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->StreetName ?? ''):'');
      $inv['seller']['zip']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->PostalZone ?? ''):'');
      $inv['seller']['city']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->CityName ?? ''):'');
      $inv['seller']['tel']=$tr($p?($p->Contact->children($ns['cbc'] ?? null)->Telephone ?? ''):'');
      $inv['seller']['mail']=$tr($p?($p->Contact->children($ns['cbc'] ?? null)->ElectronicMail ?? ''):'');
      $inv['seller']['vat']=$tr($p?($p->PartyTaxScheme->children($ns['cbc'] ?? null)->CompanyID ?? ''):'');

      $p=$cust ? $cust->children($ns['cac'] ?? null)->Party : null;
      $inv['buyer']['name']=$tr($p?($p->PartyName->children($ns['cbc'] ?? null)->Name ?? ''):'');
      $inv['buyer']['street']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->StreetName ?? ''):'');
      $inv['buyer']['zip']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->PostalZone ?? ''):'');
      $inv['buyer']['city']=$tr($p?($p->PostalAddress->children($ns['cbc'] ?? null)->CityName ?? ''):'');
      $inv['buyer']['mail']=$tr($p?($p->children($ns['cbc'] ?? null)->EndpointID ?? ''):'');

      $inv['bank']['iban']=$tr($pay?($pay->children($ns['cac'] ?? null)->PayeeFinancialAccount->children($ns['cbc'] ?? null)->ID ?? ''):'');
      $inv['bank']['bic']=$tr($pay?($pay->children($ns['cac'] ?? null)->PayeeFinancialAccount->children($ns['cac'] ?? null)->FinancialInstitutionBranch->children($ns['cbc'] ?? null)->ID ?? ''):'');
      $inv['bank']['owner']=$tr($pay?($pay->children($ns['cac'] ?? null)->PayeeFinancialAccount->children($ns['cbc'] ?? null)->Name ?? ''):'');
      $inv['bank']['bank']=$tr($pay?($pay->children($ns['cac'] ?? null)->PayeeFinancialAccount->children($ns['cac'] ?? null)->FinancialInstitutionBranch->children($ns['cbc'] ?? null)->Name ?? ''):'');

      $inv['net']=$tr($legal?$legal->children($ns['cbc'] ?? null)->TaxExclusiveAmount ?? '0.00':'0.00');
      $inv['payable']=$tr($legal?$legal->children($ns['cbc'] ?? null)->PayableAmount ?? '0.00':'0.00');
      $inv['tax']=$tr($tax?$tax->children($ns['cbc'] ?? null)->TaxAmount ?? '0.00':'0.00');
      $inv['gross']=$tr(number_format((float)str_replace(',','.',$inv['net']) + (float)str_replace(',','.',$inv['tax']),2,'.',''));

      foreach(($cac->InvoiceLine ?? []) as $pos){
        $pCAC=$pos->children($ns['cac'] ?? null);
        $pCBC=$pos->children($ns['cbc'] ?? null);

        $desc=$tr($pCAC->Item->children($ns['cbc'] ?? null)->Name ?? $pCAC->Item->children($ns['cbc'] ?? null)->Description ?? '');
        $qty =$tr($pCBC->InvoicedQuantity ?? '1');
        $unit=$tr((string)($pCBC->InvoicedQuantity->attributes()->unitCode ?? ''));
        $price=$tr($pCAC->Price->children($ns['cbc'] ?? null)->PriceAmount ?? '0.00');
        $line=$tr($pCBC->LineExtensionAmount ?? '');

        if($line===''){
          $q=(float)str_replace(',','.',$qty);
          $pr=(float)str_replace(',','.',$price);
          $line=number_format($q*$pr,2,'.','');
        }
        $inv['lines'][]=['desc'=>$desc,'qty'=>$qty,'unit'=>$unit,'price'=>$price,'line'=>$line];
      }

    } else {
      $xp=new DOMXPath($dom);
      $xp->registerNamespace('rsm','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
      $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
      $xp->registerNamespace('udt','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
      $s=function($q,$c=null)use($xp){ return trim($xp->evaluate('string('.$q.')',$c)); };

      $inv['id']=$tr($s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID') ?: $title);
      $inv['type']=$tr($s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode') ?: '380');

      $issue=$s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
      $due  =$s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
      $svc  =$s('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString');

      $inv['issue']=$tr($issue);
      $inv['due']=$tr($due);
      $inv['service']=$tr($svc);

      $inv['note']=$tr($s('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content'));

      $sp='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
      $bp='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';

      $inv['seller']['name']=$tr($s($sp.'/ram:Name'));
      $inv['seller']['street']=$tr($s($sp.'/ram:PostalTradeAddress/ram:LineOne'));
      $inv['seller']['zip']=$tr($s($sp.'/ram:PostalTradeAddress/ram:PostcodeCode'));
      $inv['seller']['city']=$tr($s($sp.'/ram:PostalTradeAddress/ram:CityName'));
      $inv['seller']['tel']=$tr($s($sp.'/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber'));
      $inv['seller']['mail']=$tr($s($sp.'/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID'));
      $inv['seller']['vat']=$tr($s($sp.'/ram:SpecifiedTaxRegistration/ram:ID'));

      $inv['buyer']['name']=$tr($s($bp.'/ram:Name'));
      $inv['buyer']['street']=$tr($s($bp.'/ram:PostalTradeAddress/ram:LineOne'));
      $inv['buyer']['zip']=$tr($s($bp.'/ram:PostalTradeAddress/ram:PostcodeCode'));
      $inv['buyer']['city']=$tr($s($bp.'/ram:PostalTradeAddress/ram:CityName'));
      $inv['buyer']['mail']=$tr($s($bp.'/ram:URIUniversalCommunication/ram:URIID'));

      $pay='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans';
      $inv['bank']['iban']=$tr($s($pay.'/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'));
      $inv['bank']['bic']=$tr($s($pay.'/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'));
      $inv['bank']['owner']=$tr($s($pay.'/ram:PayeePartyCreditorFinancialAccount/ram:AccountName'));
      $inv['bank']['bank']=$tr($s($pay.'/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name'));

      $sum='/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
      $inv['net']=$tr($s($sum.'/ram:TaxBasisTotalAmount') ?: '0.00');
      $inv['tax']=$tr($s($sum.'/ram:TaxTotalAmount') ?: '0.00');
      $inv['gross']=$tr($s($sum.'/ram:GrandTotalAmount') ?: number_format((float)$inv['net']+(float)$inv['tax'],2,'.',''));
      $inv['payable']=$tr($s($sum.'/ram:DuePayableAmount') ?: $inv['gross']);

      foreach($xp->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') as $li){
        $desc=$tr($s('ram:SpecifiedTradeProduct/ram:Name',$li));
        $qty =$tr($s('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity',$li));
        $unit=$tr($s('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode',$li));
        $price=$tr($s('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount',$li));
        $line=$tr($s('(ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount | ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount/udt:Amount)[1]',$li));

        if($line==='' || ((float)str_replace(',','.',$line)===0.0 && ((float)str_replace(',','.',$qty))*((float)str_replace(',','.',$price))>0)){
          $q=(float)str_replace(',','.',$qty);
          $pr=(float)str_replace(',','.',$price);
          $line=number_format($q*$pr,2,'.','');
        }

        $inv['lines'][]=['desc'=>$desc,'qty'=>$qty,'unit'=>$unit,'price'=>$price,'line'=>$line];
      }
    }
  }

  if($inv['service']==='') $inv['service']=$inv['issue'];

  $fmtDate=function($s){
    $s=trim((string)$s);
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)){
      $dt=DateTime::createFromFormat('Y-m-d',$s);
      return $dt?$dt->format('d.m.Y'):$s;
    }
    if(preg_match('/^\d{8}$/',$s)){
      $dt=DateTime::createFromFormat('Ymd',$s);
      return $dt?$dt->format('d.m.Y'):$s;
    }
    return $s;
  };
  $money=function($s){
    $v=(float)str_replace(',','.',preg_replace('/[^0-9,\.\-]/','',(string)$s));
    return number_format($v,2,',','.');
  };
  $unitLabel=function($c){
    $m=['HUR'=>'Std.','H87'=>'Stck.','C62'=>'Einheit','LS'=>'Pauschal','DAY'=>'Tag','WEE'=>'Woche','MON'=>'Monat','ANN'=>'Jahr','MIN'=>'Minute','SEC'=>'Sekunde'];
    $c=strtoupper(trim((string)$c));
    return $m[$c] ?? $c;
  };

  $helvWidth=function($s){
    static $w=null;
    if($w===null){
      $w=[
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,
        58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,
        65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,
        91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,
        97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,
        123=>334,124=>260,125=>334,126=>584,
        196=>667,214=>778,220=>722,223=>556,228=>556,246=>556,252=>556
      ];
    }
    $sum=0;
    $s=(string)$s;
    for($i=0,$n=strlen($s);$i<$n;$i++){
      $c=ord($s[$i]);
      $sum += $w[$c] ?? 556;
    }
    return $sum;
  };

  $to1252=function($s){
    $s=(string)$s;
    $b=@iconv('UTF-8','Windows-1252//TRANSLIT',$s);
    return $b===false?'':$b;
  };
  $esc1252=function($b){
    $b=(string)$b;
    return str_replace(['\\','(',')'],['\\\\','\(','\)'],$b);
  };

  $pdfFontN_bytes=pdf_font_bytes([
    'https://daten.fabianbrose.de/typo/font.php?f=Brose.ttf'
  ]);

  $pdfFontN=$pdfFontN_bytes ? pdf_ttf_winansi_metrics($pdfFontN_bytes) : null;
  $pdfFontB=$pdfFontN;
  if($pdfFontN && !$pdfFontB){ $pdfFontB=$pdfFontN; $pdfFontN_bytes=$pdfFontN_bytes; }

  $fontWidthN=function($s1252) use($pdfFontN,$helvWidth){
    if(!$pdfFontN) return $helvWidth($s1252);
    $sum=0;
    $m=$pdfFontN['wmap'];
    $def=$pdfFontN['missingWidth'] ?: 556;
    $s1252=(string)$s1252;
    for($i=0,$n=strlen($s1252);$i<$n;$i++){
      $c=ord($s1252[$i]);
      $sum += $m[$c] ?? $def;
    }
    return $sum;
  };
  $fontWidthB=function($s1252) use($pdfFontB,$fontWidthN){
    if(!$pdfFontB) return $fontWidthN($s1252);
    $sum=0;
    $m=$pdfFontB['wmap'];
    $def=$pdfFontB['missingWidth'] ?: 556;
    $s1252=(string)$s1252;
    for($i=0,$n=strlen($s1252);$i<$n;$i++){
      $c=ord($s1252[$i]);
      $sum += $m[$c] ?? $def;
    }
    return $sum;
  };

  $textRight=function($xRight,$y,$size,$text) use($to1252,$esc1252,$fontWidthN){
    $t=(string)$text;
    $b=$to1252($t);
    $widthPt=($fontWidthN($b)*$size)/1000.0;
    $x=max(0,$xRight-$widthPt);
    return "BT /F1 $size Tf $x $y Td (".$esc1252($b).") Tj ET\n";
  };
  $textRightB=function($xRight,$y,$size,$text) use($to1252,$esc1252,$fontWidthB){
    $t=(string)$text;
    $b=$to1252($t);
    $widthPt=($fontWidthB($b)*$size)/1000.0;
    $x=max(0,$xRight-$widthPt);
    return "BT /F2 $size Tf $x $y Td (".$esc1252($b).") Tj ET\n";
  };

  $makePage=function($inv,$pageNo,$rows,$isLastPage) use($esc,$fmtDate,$money,$unitLabel,$textRight,$textRightB){
    $out="q\n";

    $x0=30; $y0=30; $w=535; $h=782;
    $out.="0.6 w 0.75 0.75 0.75 RG\n";
    $out.="$x0 $y0 $w $h re S\n";
    $out.="0 0 0 RG 0 0 0 rg 0.6 w\n";

    $FS=9;
    $bgH = 20;
    $lh=14;
    $xL=55;

    $out.="BT /F1 $FS Tf $xL 784 Td (".$esc($inv['seller']['name']).") Tj ET\n";
    $y=770;
    foreach([
      $inv['seller']['street'],
      trim($inv['seller']['zip'].' '.$inv['seller']['city']),
      $inv['seller']['tel'],
      $inv['seller']['mail'],
      $inv['seller']['vat']
    ] as $ln){
      $ln=trim((string)$ln); if($ln==='') continue;
      $out.="BT /F1 $FS Tf $xL $y Td (".$esc($ln).") Tj ET\n";
      $y-=$lh;
    }

    $lineY=700;
    $out.="0.6 w 0.82 0.82 0.82 RG\n";
    $out.="$xL $lineY m 548 $lineY l S\n";
    $out.="0 0 0 RG\n";

    $by=680;
    $out.="BT /F1 $FS Tf $xL $by Td (".$esc($inv['buyer']['name']).") Tj ET\n"; $by-=$lh;
    $out.="BT /F1 $FS Tf $xL $by Td (".$esc($inv['buyer']['street']).") Tj ET\n"; $by-=$lh;
    $out.="BT /F1 $FS Tf $xL $by Td (".$esc(trim($inv['buyer']['zip'].' '.$inv['buyer']['city'])).") Tj ET\n"; $by-=$lh;
    if(trim($inv['buyer']['mail'])!=='') $out.="BT /F1 $FS Tf $xL $by Td (".$esc($inv['buyer']['mail']).") Tj ET\n";

    $gx=332; $gw=215; $gy=662;

    $topBarShift = 2;

    $out.="0.8 0.8 0.8 rg\n";
    $out.="$gx ".($gy+$topBarShift)." $gw $bgH re f\n";
    $out.="0 0 0 rg\n";

    $tmap=['380'=>'Rechnung','326'=>'Teilrechnung','384'=>'Korrigierte Rechnung','381'=>'Gutschrift'];
    $docTitle=$tmap[(string)($inv['type'] ?? '380')] ?? 'Rechnung';
    $titleDown = 2;
    $out.="BT /F2 12 Tf ".($gx+10)." ".($gy+8+$topBarShift-$titleDown)." Td (".$esc($docTitle).") Tj ET\n";

    $labelX=$gx+10;
    $valueRight=$gx+$gw-10;

    $out.="BT /F1 $FS Tf $labelX ".($gy-10)." Td (Rechnungsnummer:) Tj ET\n";
    $out.=$textRight($valueRight, $gy-10, $FS, $inv['id']);

    $out.="BT /F1 $FS Tf $labelX ".($gy-24)." Td (Rechnungsdatum:) Tj ET\n";
    $out.=$textRight($valueRight, $gy-24, $FS, $fmtDate($inv['issue']));

    $out.="BT /F1 $FS Tf $labelX ".($gy-38)." Td (".$esc("Fälligkeitsdatum:").") Tj ET\n";
    $out.=$textRight($valueRight, $gy-38, $FS, $fmtDate($inv['due']));

    $out.="0.8 0.8 0.8 rg\n";
    $out.="$gx ".($gy-72+9)." $gw $bgH re f\n";
    $out.="0 0 0 rg\n";
    $out.="BT /F2 $FS Tf $labelX ".($gy-56)." Td (Zu zahlen EUR:) Tj ET\n";
    $out.=$textRightB($valueRight, $gy-56, $FS, $money($inv['payable']));

    $desc=trim((string)$inv['note']);
    if($desc!==''){
      $desc=substr($desc,0,180);
      $out.="BT /F1 $FS Tf $xL 548 Td (".$esc($desc).") Tj ET\n";
    }

    $tx1=55; $tx2=240; $tx3=320; $tx4=390; $tx5=480; $tx6=545;
    $ty=535;

    $out.="0.8 0.8 0.8 rg\n";
    $out.=($tx1-8)." ".($ty-16+2)." ".(($tx6-$tx1)+8)." $bgH re f\n";
    $out.="0 0 0 rg\n";

    $out.="BT /F1 9 Tf $tx1 ".($ty-8)." Td (Beschreibung) Tj ET\n";
    $out.="BT /F1 9 Tf $tx2 ".($ty-8)." Td (Einheit) Tj ET\n";
    $out.="BT /F1 9 Tf $tx3 ".($ty-8)." Td (Menge) Tj ET\n";
    $out.="BT /F1 9 Tf $tx4 ".($ty-8)." Td (Einzelpreis) Tj ET\n";
    $out.="BT /F1 9 Tf $tx5 ".($ty-8)." Td (Gesamt) Tj ET\n";

    $y=$ty-30;
    $rowH=22;

    foreach($rows as $r){
      $d=substr(trim((string)$r['desc']),0,55);
      $u=$unitLabel($r['unit']);
      $q=trim((string)$r['qty']);
      $p=$money($r['price'])." €";
      $lineRaw=trim((string)($r['line'] ?? ''));
      if($lineRaw==='' || ((float)str_replace(',','.',$lineRaw)===0.0 && ((float)str_replace(',','.',$q))*((float)str_replace(',','.',$r['price'] ?? ''))>0)){
        $qv=(float)str_replace(',','.',$q);
        $pv=(float)str_replace(',','.',preg_replace('/[^0-9,\.\-]/','',(string)($r['price'] ?? '0')));
        $lineRaw=number_format($qv*$pv,2,'.','');
      }
      $g=$money($lineRaw)." €";

      $out.="BT /F1 $FS Tf $tx1 $y Td (".$esc($d).") Tj ET\n";
      $out.="BT /F1 $FS Tf $tx2 $y Td (".$esc($u).") Tj ET\n";
      $out.="BT /F1 $FS Tf $tx3 $y Td (".$esc($q).") Tj ET\n";
      $out.="BT /F1 $FS Tf $tx4 $y Td (".$esc($p).") Tj ET\n";
      $out.="BT /F1 $FS Tf $tx5 $y Td (".$esc($g).") Tj ET\n";

      $out.="0.6 w 0.82 0.82 0.82 RG\n";
      $ly = $y - 6;
      $out.="0.6 w 0.82 0.82 0.82 RG\n";
      $out.=($tx1-8)." $ly m $tx6 $ly l S\n";
      $out.="0 0 0 RG\n";

      $out.="0 0 0 RG\n";

      $y-=$rowH;
      if($y<240) break;
    }

    if($isLastPage){
      $sy=245;
      $labelX2=343;
      $valueRight2=535;

      $out.="BT /F1 $FS Tf $labelX2 $sy Td (Nettobetrag:) Tj ET\n";
      $out.=$textRight($valueRight2, $sy, $FS, $money($inv['net'])." €");
      $sy-=16;

      $out.="BT /F1 $FS Tf $labelX2 $sy Td (Umsatzsteuer 19%:) Tj ET\n";
      $out.=$textRight($valueRight2, $sy, $FS, $money($inv['tax'])." €");
      $sy-=20;

      $out.="0.8 0.8 0.8 rg\n";
      $out.="332 ".($sy-7)." 215 $bgH re f\n";
      $out.="0 0 0 rg\n";
      $out.="BT /F2 $FS Tf $labelX2 $sy Td (Gesamtbetrag:) Tj ET\n";
      $out.=$textRightB($valueRight2, $sy, $FS, $money($inv['payable'])." €");

      $sy-=20;

      $out.="BT /F1 $FS Tf $labelX2 $sy Td (Leistungsdatum:) Tj ET\n";
      $out.=$textRight($valueRight2, $sy, $FS, $fmtDate($inv['service']));

      $footerShift = 30;
      $out.="BT /F1 $FS Tf $xL ".(160-$footerShift)." Td (".$esc("Vielen Dank für den Auftrag!").") Tj ET\n";

      $bo=trim((string)$inv['bank']['owner']);
      $bb=trim((string)$inv['bank']['bank']);
      if($bo!=='') $out.="BT /F1 $FS Tf $xL ".(134-$footerShift)." Td (".$esc($bo).") Tj ET\n";
      if($bb!=='') $out.="BT /F1 $FS Tf $xL ".(120-$footerShift)." Td (".$esc($bb).") Tj ET\n";
      if(trim($inv['bank']['iban'])!=='') $out.="BT /F1 $FS Tf $xL ".(106-$footerShift)." Td (".$esc($inv['bank']['iban']).") Tj ET\n";
      if(trim($inv['bank']['bic'])!=='') $out.="BT /F1 $FS Tf $xL ".(92-$footerShift)." Td (".$esc($inv['bank']['bic']).") Tj ET\n";
    }

    $out.="Q\n";
    return $out;
  };

  $lines=$inv['lines'];
  $rowH_forPaging = 22;
  $perPage = (int)floor((((535-32) - 240) / $rowH_forPaging)) + 1;

  $pagesContent=[];
  $totalPages=max(1,(int)ceil(max(1,count($lines))/$perPage));
  for($p=1;$p<=$totalPages;$p++){
    $slice=array_slice($lines, ($p-1)*$perPage, $perPage);
    if(!$slice) $slice=[['desc'=>'','qty'=>'','unit'=>'','price'=>'','line'=>'']];
    $pagesContent[]=$makePage($inv,$p,$slice, $p===$totalPages);
  }

  $objs=[];$offs=[];
  $header="%PDF-1.7\n%\xC2\xB5\xC2\xB5\n";
  $add=function($s) use (&$objs){$objs[]=$s; return count($objs);};

  if($pdfFontN && $pdfFontN_bytes){
    $b=$pdfFontN['bbox'];
    $ia=rtrim(rtrim(number_format((float)$pdfFontN['italicAngle'],2,'.',''),'0'),'.');
    if($ia==='') $ia='0';
    $fontFile=$add("<< /Length ".strlen($pdfFontN_bytes)." /Length1 ".strlen($pdfFontN_bytes)." >>\nstream\n".$pdfFontN_bytes."\nendstream");
    $fontDesc=$add("<< /Type /FontDescriptor /FontName /".$pdfFontN['psName']." /Flags 32 /FontBBox [".$b[0]." ".$b[1]." ".$b[2]." ".$b[3]."] /ItalicAngle ".$ia." /Ascent ".$pdfFontN['ascent']." /Descent ".$pdfFontN['descent']." /CapHeight ".$pdfFontN['capHeight']." /StemV 80 /FontFile2 ".$fontFile." 0 R >>");
    $w='[ '.implode(' ',$pdfFontN['widths']).' ]';
    $font=$add("<< /Type /Font /Subtype /TrueType /BaseFont /".$pdfFontN['psName']." /FirstChar 32 /LastChar 255 /Widths ".$w." /Encoding /WinAnsiEncoding /FontDescriptor ".$fontDesc." 0 R >>");

    if($pdfFontB && $pdfFontN_bytes && !($pdfFontN_bytes===$pdfFontN_bytes && $pdfFontB['psName']===$pdfFontN['psName'])){
      $b2=$pdfFontB['bbox'];
      $ia2=rtrim(rtrim(number_format((float)$pdfFontB['italicAngle'],2,'.',''),'0'),'.');
      if($ia2==='') $ia2='0';
      $fontFile2=$add("<< /Length ".strlen($pdfFontN_bytes)." /Length1 ".strlen($pdfFontN_bytes)." >>\nstream\n".$pdfFontN_bytes."\nendstream");
      $fontDesc2=$add("<< /Type /FontDescriptor /FontName /".$pdfFontB['psName']." /Flags 32 /FontBBox [".$b2[0]." ".$b2[1]." ".$b2[2]." ".$b2[3]."] /ItalicAngle ".$ia2." /Ascent ".$pdfFontB['ascent']." /Descent ".$pdfFontB['descent']." /CapHeight ".$pdfFontB['capHeight']." /StemV 80 /FontFile2 ".$fontFile2." 0 R >>");
      $w2='[ '.implode(' ',$pdfFontB['widths']).' ]';
      $fontB=$add("<< /Type /Font /Subtype /TrueType /BaseFont /".$pdfFontB['psName']." /FirstChar 32 /LastChar 255 /Widths ".$w2." /Encoding /WinAnsiEncoding /FontDescriptor ".$fontDesc2." 0 R >>");
    } else {
      $fontB=$font;
    }
  } else {
    $font = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
    $fontB = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
  }

  $contentObjs=[];
  foreach($pagesContent as $cs){
    $contentObjs[]=$add("<< /Length ".strlen($cs)." >>\nstream\n".$cs."\nendstream");
  }

  $pageObjs=[];
  foreach($contentObjs as $co){
    $pageObjs[]=$add("<< /Type /Page /Parent 0 0 R /Resources << /Font << /F1 ".$font." 0 R /F2 ".$fontB." 0 R >> >> /MediaBox [0 0 595 842] /Contents ".$co." 0 R >>");
  }

  $kids='';
  foreach($pageObjs as $po){ $kids.=" ".$po." 0 R"; }
  $pages = $add("<< /Type /Pages /Kids [".$kids." ] /Count ".count($pageObjs)." >>");

  foreach($pageObjs as $po){
    $objs[$po-1]=str_replace('/Parent 0 0 R','/Parent '.$pages.' 0 R',$objs[$po-1]);
  }

  $embedName='xrechnung.xml';
  $xmlEmbed=cii_xml_sanitize_for_embed($xml);

  $xmp = facturx_xmp($embedName,'XRECHNUNG');
  $md  = $add("<< /Type /Metadata /Subtype /XML /Length ".strlen($xmp)." >>\nstream\n".$xmp."\nendstream");

  $ef  = $add("<< /Type /EmbeddedFile /Subtype /application#2Fxml /Params << /ModDate (D:".gmdate('YmdHis')."Z) /Size ".strlen($xmlEmbed)." >> /Length ".strlen($xmlEmbed)." >>\nstream\n".$xmlEmbed."\nendstream");
  $filespec = $add("<< /Type /Filespec /F (".$esc($embedName).") /UF (".$esc($embedName).") /EF << /F ".$ef." 0 R >> /Desc (XRechnung) /AFRelationship /Alternative >>");
  $names = $add("<< /EmbeddedFiles << /Names [ (".$esc($embedName).") ".$filespec." 0 R ] >> >>");

  $icc=@file_get_contents(__DIR__.'/sRGB2014.icc');
  $outputIntents='';
  if($icc!==false && $icc!==''){
    $iccObj=$add("<< /N 3 /Length ".strlen($icc)." >>\nstream\n".$icc."\nendstream");
    $oiObj=$add("<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /DestOutputProfile ".$iccObj." 0 R >>");
    $outputIntents=" /OutputIntents [ ".$oiObj." 0 R ]";
  }

  $catalog = $add("<< /Type /Catalog /Pages ".$pages." 0 R /Metadata ".$md." 0 R /Names ".$names." 0 R /AF [ ".$filespec." 0 R ]".$outputIntents." >>");

  $out=$header; $offs[0]=0; $pos=strlen($out);
  foreach($objs as $i=>$o){ $offs[$i+1]=$pos; $out.=(($i+1)." 0 obj\n".$o."\nendobj\n"); $pos=strlen($out); }
  $xrefPos=strlen($out);
  $out.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=count($objs);$i++) $out.=sprintf("%010d 00000 n \n",$offs[$i]);
  $id=bin2hex(random_bytes(16));
  $out.="trailer << /Size ".(count($objs)+1)." /Root ".$catalog." 0 R /ID [<".$id."><".$id.">] >>\nstartxref\n".$xrefPos."\n%%EOF";

  return file_put_contents($pdfPath,$out)!==false ? $pdfPath : false;
}

if(PHP_SAPI==='cli'){
  $xmlPath=$argv[1]??null;
  if(!$xmlPath){fwrite(STDERR,"xml path fehlt\n"); exit(2);}
  $ok=facturx_pdf($xmlPath);
  if(!$ok){fwrite(STDERR,"erzeugung fehlgeschlagen\n"); exit(1);}
  echo $ok; exit;
}

require_once __DIR__ . '/config.php';

if (isset($_GET['xml'])) {
  // Direkter Browser-Aufruf → Zugang prüfen (Session-Login bzw. Account).
  // Wird NICHT ausgelöst, wenn diese Datei von api_pdf.php eingebunden wird
  // (die nutzt ?id, nicht ?xml).
  require_once __DIR__ . '/auth_check.php';
  $file = basename((string)$_GET['xml']);
  if ($file === '' || !preg_match('~\.xml$~i', $file)) { http_response_code(400); exit('invalid'); }

  $xmlPath = rtrim(OUTBOX_DIR, '/\\') . DIRECTORY_SEPARATOR . $file;
  if (!is_file($xmlPath) || !is_readable($xmlPath)) { http_response_code(404); exit('not_found'); }

  $pdfPath = preg_replace('/\.xml$/i','.pdf',$xmlPath);
  $ok = facturx_pdf($xmlPath, $pdfPath, basename($xmlPath));
  if ($ok === false || !is_file($pdfPath)) { http_response_code(500); exit('error'); }

  $dl = isset($_GET['dl']) ? (int)$_GET['dl'] : 0;
  header('Content-Type: application/pdf');
  header('Content-Disposition: '.($dl?'attachment':'inline').'; filename="'.rawurlencode(basename($pdfPath)).'"');
  header('Content-Length: '.filesize($pdfPath));
  header('Cache-Control: no-store, max-age=0');
  readfile($pdfPath);
  exit;
}
