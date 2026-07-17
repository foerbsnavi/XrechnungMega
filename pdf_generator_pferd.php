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
    if($postLen>=8) $italicAngle=$i32($postOff+4)/65536.0;
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
    if($pid===3 && $eid===1 && $fmt===4){ $chosen=['fmt'=>4,'off'=>$abs]; break; }
  }
  if(!$chosen){
    for($i=0;$i<$subCount;$i++){
      $r=$cmapOff+4+$i*8;
      if(($r+8)>($cmapOff+$cmapLen)) break;
      $pid=$u16($r); $eid=$u16($r+2); $subOff=$u32($r+4);
      $abs=$cmapOff+$subOff;
      if($abs+2>($cmapOff+$cmapLen)) continue;
      $fmt=$u16($abs);
      if($pid===3 && $eid===10 && $fmt===12){ $chosen=['fmt'=>12,'off'=>$abs]; break; }
      if($chosen===null && ($pid===0) && ($fmt===4 || $fmt===12)) $chosen=['fmt'=>$fmt,'off'=>$abs];
    }
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

function facturx_xmp($fname,$level='EN 16931',$version='2.2'){
  $d=gmdate('Y-m-d\TH:i:s\Z');
  $ns='urn:zugferd:pdfa:CrossIndustryDocument:invoice:2p0#';
  return '<?xpacket begin="\ufeff" id="W5M0MpCehiHzreSzNTczkc9d"?>'
  .'<x:xmpmeta xmlns:x="adobe:ns:meta/">'
  .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#" xmlns:pdf="http://ns.adobe.com/pdf/1.3/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:zf="'.$ns.'">'
  .'<rdf:Description rdf:about="" pdfaid:part="3" pdfaid:conformance="B"/>'
  .'<rdf:Description rdf:about="" pdf:Producer="php" xmp:CreatorTool="php" xmp:CreateDate="'.$d.'" xmp:ModifyDate="'.$d.'"/>'
  .'<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/">'
    .'<pdfaExtension:schemas>'
      .'<rdf:Bag>'
        .'<rdf:li rdf:parseType="Resource">'
          .'<pdfaSchema:schema>ZUGFeRD PDFA Extension Schema</pdfaSchema:schema>'
          .'<pdfaSchema:namespaceURI>'.$ns.'</pdfaSchema:namespaceURI>'
          .'<pdfaSchema:prefix>zf</pdfaSchema:prefix>'
          .'<pdfaSchema:property>'
            .'<rdf:Seq>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentFileName</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>name of the embedded XML invoice file</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentType</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>type of document</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>Version</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>ZUGFeRD version</pdfaProperty:description></rdf:li>'
              .'<rdf:li rdf:parseType="Resource"><pdfaProperty:name>ConformanceLevel</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>ZUGFeRD conformance level</pdfaProperty:description></rdf:li>'
            .'</rdf:Seq>'
          .'</pdfaSchema:property>'
        .'</rdf:li>'
      .'</rdf:Bag>'
    .'</pdfaExtension:schemas>'
  .'</rdf:Description>'
  .'<rdf:Description rdf:about="" xmlns:zf="'.$ns.'">'
    .'<zf:ConformanceLevel>'.htmlspecialchars($level,ENT_QUOTES).'</zf:ConformanceLevel>'
    .'<zf:DocumentFileName>'.htmlspecialchars($fname,ENT_QUOTES).'</zf:DocumentFileName>'
    .'<zf:DocumentType>INVOICE</zf:DocumentType>'
    .'<zf:Version>'.htmlspecialchars($version,ENT_QUOTES).'</zf:Version>'
  .'</rdf:Description>'
  .'</rdf:RDF>'
  .'</x:xmpmeta>'
  .'<?xpacket end="w"?>';
}

function facturx_set_zugferd_en16931_guideline($xml){
  $dom=new DOMDocument();
  if(!$dom->loadXML((string)$xml, LIBXML_NONET)) return $xml;

  $root=$dom->documentElement;
  if(!$root || $root->localName!=='CrossIndustryInvoice') return $xml;

  $nsRsm='urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
  $nsRam='urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

  $guideline='urn:cen.eu:en16931:2017';
  $business='urn:fdc:peppol.eu:poacc:billing:3';

  $ctx=$dom->getElementsByTagNameNS($nsRsm,'ExchangedDocumentContext')->item(0);
  if(!$ctx){
    $ctx=$dom->createElementNS($nsRsm,'rsm:ExchangedDocumentContext');
    $root->insertBefore($ctx,$root->firstChild);
  }

  $bp=null;
  foreach($ctx->getElementsByTagNameNS($nsRam,'BusinessProcessSpecifiedDocumentContextParameter') as $n){ $bp=$n; break; }
  if(!$bp){
    $bp=$dom->createElementNS($nsRam,'ram:BusinessProcessSpecifiedDocumentContextParameter');
    $ctx->appendChild($bp);
  }
  $bpId=null;
  foreach($bp->getElementsByTagNameNS($nsRam,'ID') as $n){ $bpId=$n; break; }
  if(!$bpId){
    $bpId=$dom->createElementNS($nsRam,'ram:ID');
    $bp->appendChild($bpId);
  }
  while($bpId->firstChild) $bpId->removeChild($bpId->firstChild);
  $bpId->appendChild($dom->createTextNode($business));

  $g=null;
  foreach($ctx->getElementsByTagNameNS($nsRam,'GuidelineSpecifiedDocumentContextParameter') as $n){ $g=$n; break; }
  if(!$g){
    $g=$dom->createElementNS($nsRam,'ram:GuidelineSpecifiedDocumentContextParameter');
    $ctx->appendChild($g);
  }
  $id=null;
  foreach($g->getElementsByTagNameNS($nsRam,'ID') as $n){ $id=$n; break; }
  if(!$id){
    $id=$dom->createElementNS($nsRam,'ram:ID');
    $g->appendChild($id);
  }
  while($id->firstChild) $id->removeChild($id->firstChild);
  $id->appendChild($dom->createTextNode($guideline));

  return $dom->saveXML();
}

function facturx_is_ubl_invoice($xml){
  if(!is_string($xml) || $xml==='') return false;
  return (bool)preg_match('~<\s*(?:\w+:)?Invoice\b~i',$xml);
}

function facturx_is_cii($xml){
  if(!is_string($xml) || $xml==='') return false;
  return (bool)preg_match('~<\s*(?:\w+:)?CrossIndustryInvoice\b~i',$xml);
}

// Liefert die CII-Repräsentation einer UBL-Rechnung als STRING.
// Achtung: ubl_to_cii.php deklariert selbst ein ubl_to_cii_pferd($ublPath,$ciiPath)
// (Datei-zu-Datei, Rückgabe bool) — der frühere gleichnamige Wrapper hier führte
// zu "Cannot redeclare" (HTTP 500 bei jeder UBL-Rechnung) und rief zudem
// ubl_to_cii() mit nur einem Argument auf.
function ubl_to_cii_pferd_xml($ublPath){
  require_once __DIR__.'/ubl_to_cii.php';
  $tmp=tempnam(sys_get_temp_dir(),'cii_');
  if($tmp===false) return null;
  $ok=ubl_to_cii_pferd($ublPath,$tmp);
  $xml=$ok?@file_get_contents($tmp):null;
  @unlink($tmp);
  return (is_string($xml) && $xml!=='') ? $xml : null;
}

function cii_xml_sanitize_for_embed_pferd($xml){
  if(!is_string($xml) || $xml==='') return $xml;

  libxml_use_internal_errors(true);
  $dom=new DOMDocument();
  if(!$dom->loadXML($xml, LIBXML_NONET)) return $xml;

  $xp=new DOMXPath($dom);
  $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

  foreach($xp->query('//ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name') as $n){
    if($n && $n->parentNode) $n->parentNode->removeChild($n);
  }

  foreach($xp->query('//ram:PayeePartyCreditorFinancialAccount/ram:AccountName') as $n){
    if($n && $n->parentNode) $n->parentNode->removeChild($n);
  }

  foreach($xp->query('//ram:PayeePartyCreditorFinancialAccount') as $acc){
    $iban=trim((string)$xp->evaluate('string(ram:IBANID)', $acc));
    $prop=trim((string)$xp->evaluate('string(ram:ProprietaryID)', $acc));
    if($iban==='' && $prop===''){
      if($acc && $acc->parentNode) $acc->parentNode->removeChild($acc);
    }
  }

  return $dom->saveXML();
}

function facturx_embed_xml_bytes($xmlOrPath){
  $xml=null;
  if(is_string($xmlOrPath) && is_file($xmlOrPath)){
    $xml=@file_get_contents($xmlOrPath);
    if($xml===false) return null;
  } else {
    $xml=(string)$xmlOrPath;
  }
  if($xml===null || $xml==='') return null;

  if(facturx_is_ubl_invoice($xml)){
    $tmp=$xmlOrPath;
    if(!is_string($xmlOrPath) || !is_file($xmlOrPath)){
      $tmp=tempnam(sys_get_temp_dir(),'ubl_');
      file_put_contents($tmp,$xml);
    }
    $cii=ubl_to_cii_pferd_xml($tmp);
    if($tmp!==$xmlOrPath && is_file($tmp)) @unlink($tmp);
    if(!is_string($cii) || $cii==='') return null;
    $xml=$cii;
  }

  if(!facturx_is_cii($xml)) return null;

  $xml=facturx_set_zugferd_en16931_guideline($xml);
  $xml=cii_xml_sanitize_for_embed_pferd($xml);

  return $xml;
}

function facturx_pdf_en16931($xmlPath,$pdfPath=null){
  $GLOBALS['FX_LAST_ERROR']='';

  $fail=function($m){
    $GLOBALS['FX_LAST_ERROR']=$m;
    return false;
  };

  if(!is_file($xmlPath) || !is_readable($xmlPath)) return $fail('xml_not_readable: '.$xmlPath);

  $xml=@file_get_contents($xmlPath);
  if(!is_string($xml) || $xml==='') return $fail('xml_empty');

  if(!facturx_is_ubl_invoice($xml) && !facturx_is_cii($xml)) return $fail('xml_not_ubl_or_cii');

  if($pdfPath===null){
    $base=preg_replace('/\.(xml)$/i','',$xmlPath);
    $pdfPath=$base.'_fx.pdf';
  }

  $dir=dirname($pdfPath);
  if(!is_dir($dir)){
    if(!@mkdir($dir,0775,true) && !is_dir($dir)) return $fail('pdf_dir_create_failed: '.$dir);
  }
  if(!is_writable($dir)) return $fail('pdf_dir_not_writable: '.$dir);

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

  $parseInvoice=function($xmlStr) use ($tr){
    $inv=[
      'id'=>'','type'=>'380','issue'=>'','due'=>'','service'=>'',
      'seller'=>['name'=>'','street'=>'','zip'=>'','city'=>'','tel'=>'','mail'=>'','vat'=>''],
      'buyer'=>['name'=>'','street'=>'','zip'=>'','city'=>'','mail'=>''],
      'bank'=>['owner'=>'','bank'=>'','iban'=>'','bic'=>''],
      'note'=>'','net'=>'0.00','tax'=>'0.00','gross'=>'0.00','payable'=>'0.00','lines'=>[]
    ];

    libxml_use_internal_errors(true);
    $dom=new DOMDocument();
    if(!$dom->loadXML($xmlStr, LIBXML_NONET)) return $inv;
    $root=$dom->documentElement ? $dom->documentElement->localName : '';
    $xp=new DOMXPath($dom);

    if($root==='Invoice'){
      $inv['id']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']/*[local-name()='ID'][1])"));
      $inv['type']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']/*[local-name()='InvoiceTypeCode'][1])")) ?: '380';
      $inv['issue']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']/*[local-name()='IssueDate'][1])"));
      $inv['due']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']/*[local-name()='DueDate'][1])"));
      $inv['service']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']//*[local-name()='Delivery']/*[local-name()='ActualDeliveryDate'][1])")) ?: $inv['issue'];

      $inv['seller']['name']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PartyName']/*[local-name()='Name'][1])"));
      $inv['seller']['street']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PostalAddress']/*[local-name()='StreetName'][1])"));
      $inv['seller']['zip']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PostalAddress']/*[local-name()='PostalZone'][1])"));
      $inv['seller']['city']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PostalAddress']/*[local-name()='CityName'][1])"));
      $inv['seller']['tel']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='Contact']/*[local-name()='Telephone'][1])"));
      $inv['seller']['mail']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='Contact']/*[local-name()='ElectronicMail'][1])"));
      $inv['seller']['vat']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID'][1])"));

      $inv['buyer']['name']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='PartyName']/*[local-name()='Name'][1])"));
      $inv['buyer']['street']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='PostalAddress']/*[local-name()='StreetName'][1])"));
      $inv['buyer']['zip']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='PostalAddress']/*[local-name()='PostalZone'][1])"));
      $inv['buyer']['city']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='PostalAddress']/*[local-name()='CityName'][1])"));
      $inv['buyer']['mail']=$tr((string)$xp->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='Party']/*[local-name()='EndpointID'][1])"));

      $inv['bank']['iban']=$tr((string)$xp->evaluate("string(//*[local-name()='PaymentMeans']//*[local-name()='PayeeFinancialAccount']/*[local-name()='ID'][1])"));
      $inv['bank']['bic']=$tr((string)$xp->evaluate("string(//*[local-name()='PaymentMeans']//*[local-name()='FinancialInstitutionBranch']/*[local-name()='ID'][1])"));
      $inv['bank']['owner']=$tr((string)$xp->evaluate("string(//*[local-name()='PaymentMeans']//*[local-name()='PayeeFinancialAccount']/*[local-name()='Name'][1])"));
      $inv['bank']['bank']=$tr((string)$xp->evaluate("string(//*[local-name()='PaymentMeans']//*[local-name()='FinancialInstitutionBranch']/*[local-name()='Name'][1])"));

      $inv['note']=$tr((string)$xp->evaluate("string(/*[local-name()='Invoice']/*[local-name()='Note'][1])"));

      foreach($xp->query("/*[local-name()='Invoice']//*[local-name()='InvoiceLine']") as $line){
        $desc=$tr((string)$xp->evaluate("string(.//*[local-name()='Item']/*[local-name()='Name'][1])",$line));
        $qty=$tr((string)$xp->evaluate("string(./*[local-name()='InvoicedQuantity'][1])",$line));
        $unit=$tr((string)$xp->evaluate("string(./*[local-name()='InvoicedQuantity'][1]/@unitCode)",$line));
        $price=$tr((string)$xp->evaluate("string(.//*[local-name()='Price']/*[local-name()='PriceAmount'][1])",$line));
        $lineAmt=$tr((string)$xp->evaluate("string(./*[local-name()='LineExtensionAmount'][1])",$line));
        $inv['lines'][]=['desc'=>$desc,'qty'=>$qty,'unit'=>$unit,'price'=>$price,'line'=>$lineAmt];
      }

      $net=$tr((string)$xp->evaluate("string(//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxExclusiveAmount'][1])"));
      $pay=$tr((string)$xp->evaluate("string(//*[local-name()='LegalMonetaryTotal']/*[local-name()='PayableAmount'][1])"));
      $tax=$tr((string)$xp->evaluate("string(//*[local-name()='TaxTotal']/*[local-name()='TaxAmount'][1])"));
      $inv['net']=$net!==''?$net:'0.00';
      $inv['tax']=$tax!==''?$tax:'0.00';
      $inv['payable']=$pay!==''?$pay:'0.00';
      $inv['gross']=number_format((float)str_replace(',','.',$inv['net'])+(float)str_replace(',','.',$inv['tax']),2,'.','');
      return $inv;
    }

    if($root==='CrossIndustryInvoice'){
      $s=function($q,$c=null)use($xp){ return trim((string)$xp->evaluate('string('.$q.')',$c)); };

      $inv['id']=$tr($s("/*[local-name()='CrossIndustryInvoice']/*[local-name()='ExchangedDocument']/*[local-name()='ID'][1]"));
      $inv['type']=$tr($s("/*[local-name()='CrossIndustryInvoice']/*[local-name()='ExchangedDocument']/*[local-name()='TypeCode'][1]")) ?: '380';

      $inv['issue']=$tr($s("/*[local-name()='CrossIndustryInvoice']/*[local-name()='ExchangedDocument']//*[local-name()='IssueDateTime']/*[local-name()='DateTimeString'][1]"));
      $inv['due']=$tr($s("/*[local-name()='CrossIndustryInvoice']//*[local-name()='SpecifiedTradePaymentTerms']//*[local-name()='DueDateDateTime']/*[local-name()='DateTimeString'][1]"));
      $inv['service']=$tr($s("/*[local-name()='CrossIndustryInvoice']//*[local-name()='ActualDeliverySupplyChainEvent']//*[local-name()='OccurrenceDateTime']/*[local-name()='DateTimeString'][1]")) ?: $inv['issue'];

      $inv['note']=$tr($s("/*[local-name()='CrossIndustryInvoice']/*[local-name()='ExchangedDocument']//*[local-name()='IncludedNote']/*[local-name()='Content'][1]"));

      $inv['seller']['name']=$tr($s("//*[local-name()='SellerTradeParty']/*[local-name()='Name'][1]"));
      $inv['seller']['street']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='LineOne'][1]"));
      $inv['seller']['zip']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='PostcodeCode'][1]"));
      $inv['seller']['city']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='CityName'][1]"));
      $inv['seller']['tel']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='TelephoneUniversalCommunication']/*[local-name()='CompleteNumber'][1]"));
      $inv['seller']['mail']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='EmailURIUniversalCommunication']/*[local-name()='URIID'][1]"));
      $inv['seller']['vat']=$tr($s("//*[local-name()='SellerTradeParty']//*[local-name()='SpecifiedTaxRegistration']/*[local-name()='ID'][1]"));

      $inv['buyer']['name']=$tr($s("//*[local-name()='BuyerTradeParty']/*[local-name()='Name'][1]"));
      $inv['buyer']['street']=$tr($s("//*[local-name()='BuyerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='LineOne'][1]"));
      $inv['buyer']['zip']=$tr($s("//*[local-name()='BuyerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='PostcodeCode'][1]"));
      $inv['buyer']['city']=$tr($s("//*[local-name()='BuyerTradeParty']//*[local-name()='PostalTradeAddress']/*[local-name()='CityName'][1]"));
      $inv['buyer']['mail']=$tr($s("//*[local-name()='BuyerTradeParty']//*[local-name()='URIUniversalCommunication']/*[local-name()='URIID'][1]"));

      $pay="/*[local-name()='CrossIndustryInvoice']//*[local-name()='SpecifiedTradeSettlementPaymentMeans'][1]";
      $inv['bank']['iban']=$tr($s($pay."//*[local-name()='PayeePartyCreditorFinancialAccount']/*[local-name()='IBANID'][1]"));
      $inv['bank']['bic']=$tr($s($pay."//*[local-name()='PayeeSpecifiedCreditorFinancialInstitution']/*[local-name()='BICID'][1]"));
      $inv['bank']['owner']=$tr($s($pay."//*[local-name()='PayeePartyCreditorFinancialAccount']/*[local-name()='AccountName'][1]"));
      $inv['bank']['bank']=$tr($s($pay."//*[local-name()='PayeeSpecifiedCreditorFinancialInstitution']/*[local-name()='Name'][1]"));

      $sum="/*[local-name()='CrossIndustryInvoice']//*[local-name()='SpecifiedTradeSettlementHeaderMonetarySummation'][1]";
      $inv['net']=$tr($s($sum."/*[local-name()='TaxBasisTotalAmount'][1]")) ?: '0.00';
      $inv['tax']=$tr($s($sum."/*[local-name()='TaxTotalAmount'][1]")) ?: '0.00';
      $inv['gross']=$tr($s($sum."/*[local-name()='GrandTotalAmount'][1]")) ?: number_format((float)str_replace(',','.',$inv['net'])+(float)str_replace(',','.',$inv['tax']),2,'.','');
      $inv['payable']=$tr($s($sum."/*[local-name()='DuePayableAmount'][1]")) ?: $inv['gross'];

      foreach($xp->query("/*[local-name()='CrossIndustryInvoice']//*[local-name()='IncludedSupplyChainTradeLineItem']") as $li){
        $desc=$tr($s(".//*[local-name()='SpecifiedTradeProduct']/*[local-name()='Name'][1]",$li));
        $qty=$tr($s(".//*[local-name()='SpecifiedLineTradeDelivery']/*[local-name()='BilledQuantity'][1]",$li));
        $unit=$tr($s(".//*[local-name()='SpecifiedLineTradeDelivery']/*[local-name()='BilledQuantity'][1]/@unitCode",$li));
        $price=$tr($s(".//*[local-name()='NetPriceProductTradePrice']/*[local-name()='ChargeAmount'][1]",$li));
        $lineAmt=$tr($s(".//*[local-name()='SpecifiedTradeSettlementLineMonetarySummation']/*[local-name()='LineTotalAmount'][1]",$li));
        $inv['lines'][]=['desc'=>$desc,'qty'=>$qty,'unit'=>$unit,'price'=>$price,'line'=>$lineAmt];
      }
      return $inv;
    }

    return $inv;
  };

  $inv=$parseInvoice($xml);
  if($inv['id']==='') return $fail('invoice_id_missing');

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

  // Lokal gebündelte Schrift zuerst (siehe pdf_generator_x.php); HTTP nur Fallback.
  $pdfFontN_bytes=pdf_font_bytes([__DIR__.'/Brose.ttf','https://daten.fabianbrose.de/typo/font.php?f=Brose.ttf']);
  $pdfFontN=$pdfFontN_bytes ? pdf_ttf_winansi_metrics($pdfFontN_bytes) : null;
  $pdfFontB=$pdfFontN;

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
    $b=$to1252((string)$text);
    $widthPt=($fontWidthN($b)*$size)/1000.0;
    $x=max(0,$xRight-$widthPt);
    return "BT /F1 $size Tf $x $y Td (".$esc1252($b).") Tj ET\n";
  };
  $textRightB=function($xRight,$y,$size,$text) use($to1252,$esc1252,$fontWidthB){
    $b=$to1252((string)$text);
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
    $bgH=20;
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
    $topBarShift=2;

    $out.="0.8 0.8 0.8 rg\n";
    $out.="$gx ".($gy+$topBarShift)." $gw $bgH re f\n";
    $out.="0 0 0 rg\n";

    $tmap=['380'=>'Rechnung','326'=>'Teilrechnung','384'=>'Korrigierte Rechnung','381'=>'Gutschrift'];
    $docTitle=$tmap[(string)($inv['type'] ?? '380')] ?? 'Rechnung';
    $titleDown=2;
    $out.="BT /F2 12 Tf ".($gx+10)." ".($gy+8+$topBarShift-$titleDown)." Td (".$esc($docTitle).") Tj ET\n";

    $labelX=$gx+10;
    $valueRight=$gx+$gw-10;

    $out.="BT /F1 $FS Tf $labelX ".($gy-10)." Td (Rechnungsnummer:) Tj ET\n";
    $out.=$textRight($valueRight,$gy-10,$FS,$inv['id']);

    $out.="BT /F1 $FS Tf $labelX ".($gy-24)." Td (Rechnungsdatum:) Tj ET\n";
    $out.=$textRight($valueRight,$gy-24,$FS,$fmtDate($inv['issue']));

    $out.="BT /F1 $FS Tf $labelX ".($gy-38)." Td (".$esc("Fälligkeitsdatum:").") Tj ET\n";
    $out.=$textRight($valueRight,$gy-38,$FS,$fmtDate($inv['due']));

    $out.="0.8 0.8 0.8 rg\n";
    $out.="$gx ".($gy-72+9)." $gw $bgH re f\n";
    $out.="0 0 0 rg\n";
    $out.="BT /F2 $FS Tf $labelX ".($gy-56)." Td (Zu zahlen EUR:) Tj ET\n";
    $out.=$textRightB($valueRight,$gy-56,$FS,$money($inv['payable']));

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
      $out.=$textRight($valueRight2,$sy,$FS,$money($inv['net'])." €");
      $sy-=16;

      $out.="BT /F1 $FS Tf $labelX2 $sy Td (Umsatzsteuer 19%:) Tj ET\n";
      $out.=$textRight($valueRight2,$sy,$FS,$money($inv['tax'])." €");
      $sy-=20;

      $out.="0.8 0.8 0.8 rg\n";
      $out.="332 ".($sy-7)." 215 $bgH re f\n";
      $out.="0 0 0 rg\n";
      $out.="BT /F2 $FS Tf $labelX2 $sy Td (Gesamtbetrag:) Tj ET\n";
      $out.=$textRightB($valueRight2,$sy,$FS,$money($inv['payable'])." €");

      $sy-=20;

      $out.="BT /F1 $FS Tf $labelX2 $sy Td (Leistungsdatum:) Tj ET\n";
      $out.=$textRight($valueRight2,$sy,$FS,$fmtDate($inv['service']));

      $footerShift=30;
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
  $rowH_forPaging=22;
  $perPage=(int)floor((((535-32)-240)/$rowH_forPaging))+1;

  $pagesContent=[];
  $totalPages=max(1,(int)ceil(max(1,count($lines))/$perPage));
  for($p=1;$p<=$totalPages;$p++){
    $slice=array_slice($lines,($p-1)*$perPage,$perPage);
    if(!$slice) $slice=[['desc'=>'','qty'=>'','unit'=>'','price'=>'','line'=>'']];
    $pagesContent[]=$makePage($inv,$p,$slice,$p===$totalPages);
  }

  $objs=[]; $offs=[];
  $header="%PDF-1.7\n%\xC2\xB5\xC2\xB5\n";
  $add=function($s) use (&$objs){ $objs[]=$s; return count($objs); };

  if($pdfFontN && $pdfFontN_bytes){
    $b=$pdfFontN['bbox'];
    $ia=rtrim(rtrim(number_format((float)$pdfFontN['italicAngle'],2,'.',''),'0'),'.');
    if($ia==='') $ia='0';
    $fontFile=$add("<< /Length ".strlen($pdfFontN_bytes)." /Length1 ".strlen($pdfFontN_bytes)." >>\nstream\n".$pdfFontN_bytes."\nendstream");
    $fontDesc=$add("<< /Type /FontDescriptor /FontName /".$pdfFontN['psName']." /Flags 32 /FontBBox [".$b[0]." ".$b[1]." ".$b[2]." ".$b[3]."] /ItalicAngle ".$ia." /Ascent ".$pdfFontN['ascent']." /Descent ".$pdfFontN['descent']." /CapHeight ".$pdfFontN['capHeight']." /StemV 80 /FontFile2 ".$fontFile." 0 R >>");
    $w='[ '.implode(' ',$pdfFontN['widths']).' ]';
    $font=$add("<< /Type /Font /Subtype /TrueType /BaseFont /".$pdfFontN['psName']." /FirstChar 32 /LastChar 255 /Widths ".$w." /Encoding /WinAnsiEncoding /FontDescriptor ".$fontDesc." 0 R >>");
    $fontB=$font;
  } else {
    $GLOBALS['FX_LAST_ERROR']='font_fallback';
    $font=$add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
    $fontB=$add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
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
  $pages=$add("<< /Type /Pages /Kids [".$kids." ] /Count ".count($pageObjs)." >>");

  foreach($pageObjs as $po){
    $objs[$po-1]=str_replace('/Parent 0 0 R','/Parent '.$pages.' 0 R',$objs[$po-1]);
  }

  $embedName='zugferd-invoice.xml';
  $fxXml=facturx_embed_xml_bytes($xmlPath);
  if($fxXml===null) return $fail('embed_xml_failed');

  $xmp=facturx_xmp($embedName,'EN 16931','2.2');
  $md=$add("<< /Type /Metadata /Subtype /XML /Length ".strlen($xmp)." >>\nstream\n".$xmp."\nendstream");

  $ef=$add("<< /Type /EmbeddedFile /Subtype /application#2Fxml /Params << /ModDate (D:".gmdate('YmdHis')."Z) /Size ".strlen($fxXml)." >> /Length ".strlen($fxXml)." >>\nstream\n".$fxXml."\nendstream");
  $filespec=$add("<< /Type /Filespec /F (".$esc($embedName).") /UF (".$esc($embedName).") /EF << /F ".$ef." 0 R >> /Desc (ZUGFeRD) /AFRelationship /Alternative >>");
  $names=$add("<< /EmbeddedFiles << /Names [ (".$esc($embedName).") ".$filespec." 0 R ] >> >>");

  $icc=@file_get_contents(__DIR__.'/sRGB2014.icc');
  $outputIntents='';
  if($icc!==false && $icc!==''){
    $iccObj=$add("<< /N 3 /Length ".strlen($icc)." >>\nstream\n".$icc."\nendstream");
    $oiObj=$add("<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /DestOutputProfile ".$iccObj." 0 R >>");
    $outputIntents=" /OutputIntents [ ".$oiObj." 0 R ]";
  }

  $catalog=$add("<< /Type /Catalog /Pages ".$pages." 0 R /Metadata ".$md." 0 R /Names ".$names." 0 R /AF [ ".$filespec." 0 R ]".$outputIntents." >>");

  $out=$header; $offs[0]=0; $pos=strlen($out);
  foreach($objs as $i=>$o){
    $offs[$i+1]=$pos;
    $out.=($i+1)." 0 obj\n".$o."\nendobj\n";
    $pos=strlen($out);
  }
  $xrefPos=strlen($out);
  $out.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=count($objs);$i++) $out.=sprintf("%010d 00000 n \n",$offs[$i]);

  $id=bin2hex(random_bytes(16));
  $out.="trailer << /Size ".(count($objs)+1)." /Root ".$catalog." 0 R /ID [<".$id."><".$id.">] >>\nstartxref\n".$xrefPos."\n%%EOF";

  $w=@file_put_contents($pdfPath,$out);
  if($w===false) return $fail('pdf_write_failed: '.$pdfPath);

  return $pdfPath;
}

if(PHP_SAPI==='cli'){
  $xmlPath=$argv[1]??null;
  if(!$xmlPath){ fwrite(STDERR,"xml path fehlt\n"); exit(2); }
  $ok=facturx_pdf_en16931($xmlPath);
  if(!$ok){ fwrite(STDERR,"erzeugung fehlgeschlagen\n"); exit(1); }
  echo $ok; exit;
}

require_once __DIR__.'/config.php';

if(isset($_GET['xml'])){
  // Direkter Browser-Aufruf → Zugang prüfen (Session-Login bzw. Account).
  // Wird NICHT ausgelöst, wenn diese Datei von api_pdf.php eingebunden wird.
  require_once __DIR__ . '/auth_check.php';
  $file=basename((string)$_GET['xml']);
  if($file==='' || !preg_match('~\.xml$~i',$file)){ http_response_code(400); exit('invalid'); }

  $xmlPath=rtrim(OUTBOX_DIR,'/\\').DIRECTORY_SEPARATOR.$file;
  if(!is_file($xmlPath) || !is_readable($xmlPath)){ http_response_code(404); exit('not_found'); }

  $pdfPath=preg_replace('/\.xml$/i','_fx.pdf',$xmlPath);

  $ok=facturx_pdf_en16931($xmlPath,$pdfPath);
  if($ok===false || !is_file($pdfPath)){
    http_response_code(500);
    echo 'error: '.($GLOBALS['FX_LAST_ERROR'] ?? 'unknown').' | pdfPath='.$pdfPath;
    exit;
  }

  $dl=isset($_GET['dl']) ? (int)$_GET['dl'] : 0;
  header('Content-Type: application/pdf');
  header('Content-Disposition: '.($dl?'attachment':'inline').'; filename="'.rawurlencode(basename($pdfPath)).'"');
  header('Content-Length: '.filesize($pdfPath));
  header('Cache-Control: no-store, max-age=0');
  readfile($pdfPath);
  exit;
}
