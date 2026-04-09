<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');

define('SMTP_HOST',       'smtp.hostinger.com');
define('SMTP_PORT',       465);
define('SMTP_USER',       'ventas@saguma.com.ar');
define('SMTP_PASS',       'Emanteca.10');
define('SMTP_FROM_NAME',  'SAGUMA — Indumentaria Laboral');
define('SMTP_FROM_EMAIL', 'ventas@saguma.com.ar');
define('VENTAS_INTERNO',  'ventas@saguma.com.ar');
define('CRM_BASE_URL',    'https://saguma.com.ar/crm');
define('WA_NUMBER',       '5491161125719');

require_once __DIR__ . '/vendor/autoload.php';

function enviarMailCotizacion($pdo, $data, $cotizacion_id, $numero) {
    try {
        $items = json_decode($data['items_json'] ?? '[]', true);
        if (!$items || !is_array($items)) return ['ok'=>false,'message'=>'Items inválidos'];

        $fecha    = date('Y-m-d');
        $contacto = $data['contacto'] ?? '';
        $cliente  = $data['cliente'] ?? '';

        $costos = _getCostosPersonalizacion($pdo);
        $items  = _calcularPrecios($pdo, $items, $costos);
        $total_prendas = array_sum(array_column($items, 'cantidad'));

        $pdf_path = _generarPDFCRM($data, $items, $numero, $fecha);
        if (!$pdf_path || !file_exists($pdf_path)) {
            return ['ok'=>false, 'message'=>'Error al generar el PDF'];
        }

        $wa_aceptar = 'https://wa.me/' . WA_NUMBER . '?text=' . urlencode("Hola, quiero confirmar la cotización {$numero} a nombre de {$cliente}. ¿Cómo seguimos?");
        $wa_dudas   = 'https://wa.me/' . WA_NUMBER . '?text=' . urlencode("Hola, tengo una consulta sobre la cotización {$numero} a nombre de {$cliente}.");

        $html_cliente = _mailCliente($contacto, $cliente, $numero, $fecha, $total_prendas, $wa_aceptar, $wa_dudas);
        $ok_cliente = _enviarMailConPDF(
            $data['mail'], $contacto,
            "Cotización {$numero} — SAGUMA Indumentaria Laboral",
            $html_cliente, $pdf_path, "Cotizacion_{$numero}.pdf",
            VENTAS_INTERNO
        );

        @unlink($pdf_path);

        $estado = $ok_cliente ? 'Enviada' : 'Error envío';
        $stmt = $pdo->prepare("UPDATE cotizaciones SET estado=? WHERE id=?");
        $stmt->execute([$estado, $cotizacion_id]);

        return ['ok'=>$ok_cliente, 'message'=>$ok_cliente ? 'Mail enviado' : 'Error envío'];
    } catch (Exception $e) {
        error_log("SAGUMA mail error: " . $e->getMessage());
        return ['ok'=>false, 'message'=>$e->getMessage()];
    }
}

function _generarPDFCRM($data, $items, $numero, $fecha) {
    // NOTAS DE RENDERIZADO TCPDF:
    // 1. Usar SOLO entidades HTML numericas (&#NNN;) - el texto UTF-8 en <span> se corrompe.
    // 2. SetMargins(0,0,0) para header edge-to-edge; el contenido usa tabla con columnas espaciadoras.
    // 3. Helvetica es mas compacto que DejaVu - usar Helvetica para que entren los numeros.
    // 4. Los anchos en <th> Y <td> deben ser identicos para alinear columnas.

    $fecha_envio  = date('d/m/Y');
    $fecha_valida = date('d/m/Y', strtotime('+7 days'));
    $plazo    = $data['plazo'] ?? '30 d&#237;as h&#225;biles';
    $cliente  = htmlspecialchars($data['cliente']  ?? '', ENT_QUOTES, 'UTF-8');
    $contacto = htmlspecialchars($data['contacto'] ?? '', ENT_QUOTES, 'UTF-8');
    $telefono = htmlspecialchars($data['telefono'] ?? '', ENT_QUOTES, 'UTF-8');
    $mail     = htmlspecialchars($data['mail']     ?? '', ENT_QUOTES, 'UTF-8');
    $notas    = $data['notas'] ?? '';

    // ── Calcular totales ─────────────────────────────────────
    $subtotal_gen  = 0;
    $total_prendas = 0;
    foreach ($items as &$it) {
        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $pu   = floatval($it['precio_base'] ?? 0) + floatval($it['costo_custom'] ?? 0);
        $sub  = $pu * $cant;
        $it['_pu']  = $pu;
        $it['_sub'] = $sub;
        $it['_iva'] = $sub * 0.21;
        $it['_tot'] = $sub * 1.21;
        $subtotal_gen  += $sub;
        $total_prendas += $cant;
    }
    unset($it);
    $iva_gen     = $subtotal_gen * 0.21;
    $total_gen   = $subtotal_gen + $iva_gen;
    $hay_precios = $subtotal_gen > 0;
    $fmt = fn($n) => '$' . number_format($n, 0, ',', '.');

    // ── Colores ───────────────────────────────────────────────
    $AZ  = '#1a3a6b';
    $AZD = '#0f2447';
    $AZL = '#e8eef7';
    $GR1 = '#f7f9fc';
    $GR2 = '#f4f7fb';
    $BRD = '#d0d8e8';
    $ROW = '#e8ecf0';

    // ── Textos con entidades numericas (ASCII-safe) ───────────
    $TXT_TAGLINE  = 'INDUMENTARIA LABORAL &#183; MAYORISTA';
    $TXT_ORG      = 'Organizaci&#243;n Crima SA &#183; CUIT 30-70949492-5';
    $TXT_ADDR     = 'Av. C&#243;rdoba 391 9&#176;B &#8211; CABA';
    $TXT_CONTACT  = 'ventas@saguma.com.ar &#183; +54 11 6112-5719';
    $TXT_SUBHDR   = 'COTIZACI&#211;N MAYORISTA &#8212; N&#176; ';
    $LBL_EMPRESA  = 'EMPRESA / CLIENTE';
    $LBL_CONTACTO = 'CONTACTO';
    $LBL_TEL      = 'TEL&#201;FONO';
    $LBL_MAIL     = 'MAIL';
    $LBL_COT      = 'N&#176; COTIZACI&#211;N';
    $LBL_FENVIO   = 'FECHA ENV&#205;O';
    $LBL_VALIDA   = 'V&#193;LIDA HASTA';
    $LBL_PLAZO    = 'PLAZO DE ENTREGA';
    $TH_DESC      = 'DESCRIPCI&#211;N';
    $TXT_COND_HDR = 'CONDICIONES COMERCIALES';
    $TXT_COND     =
        '<table cellpadding="0" cellspacing="0" style="width:168mm;border-collapse:collapse">'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Pedido m&#237;nimo: 30 unidades</td></tr>'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Plazo de entrega: 30 d&#237;as h&#225;biles desde confirmaci&#243;n</td></tr>'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Anticipo: 50% al confirmar | Saldo: 50% contra entrega</td></tr>'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Validez: 7 d&#237;as corridos desde fecha de env&#237;o</td></tr>'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Talle XXXL: +12% sobre precio unitario</td></tr>'.
        '<tr><td style="width:5mm;vertical-align:top;font-size:8px;color:#333333;line-height:1.6">&#183;</td><td style="font-size:8px;color:#333333;line-height:1.6">Los costos de prendas con bordado y estampado son orientativos. Dise&#241;os de gran tama&#241;o o complejidad podr&#225;n requerir un ajuste en el precio, sujeto a evaluaci&#243;n previa.</td></tr>'.
        '</table>';
    $TXT_PIE      = 'Cotizaci&#243;n sin valor fiscal. Precios v&#225;lidos por el per&#237;odo indicado.';

    // ── Estilos base ──────────────────────────────────────────
    // Fuente Helvetica: mas compacto que DejaVu, permite mas contenido por linea.
    // Sin text-transform en ningun lugar.
    $th  = "background-color:$AZ;color:#ffffff;font-size:8px;font-weight:bold;padding:5px 3px;border-bottom:1px solid $AZD";
    $td  = "font-size:9px;padding:6px 3px;border-bottom:1px solid $ROW";
    $tot = "background-color:$AZD;color:#ffffff;font-size:9px;font-weight:bold;padding:5px 3px";
    $lbl = "font-size:7px;font-weight:bold;color:$AZ";
    $val = "font-size:9px;margin-top:0";

    // Anchos de columna en mm (total = 190mm = ancho de contenido con margenes 10mm)
    // #=5 Desc=55 Talle=14 Color=13 Cant=11 PU=21 Sub=22 IVA=20 Tot=29 => 190mm
    $W = ['5mm','55mm','14mm','13mm','11mm','21mm','22mm','20mm','29mm'];

    // ── Filas de productos ────────────────────────────────────
    $filas_html = '';
    $n = 1;
    foreach ($items as $it) {
        if (!empty($it['desc'])) {
            $desc = ucfirst($it['desc']);
        } else {
            $cat    = $it['categoria']       ?? '';
            $prod   = $it['producto']        ?? '';
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $desc   = trim($cat . ' ' . strtolower($prod));
            if ($custom === 'Bordado')       $desc .= ' c/ bordado';
            elseif ($custom === 'Estampado') $desc .= ' c/ estampado';
            elseif ($custom === 'Ambos')     $desc .= ' c/ bordado y estampado';
            $desc = ucfirst($desc);
        }
        $cant  = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $talle = htmlspecialchars($it['talle'] ?? '', ENT_QUOTES, 'UTF-8');
        $color = htmlspecialchars($it['color'] ?? '', ENT_QUOTES, 'UTF-8');
        $bg    = ($n % 2 === 0) ? "background-color:$GR2;" : 'background-color:#ffffff;';
        $filas_html .= '<tr>
          <td style="'.$bg.'text-align:center;width:'.$W[0].';'.$td.'">'.$n.'</td>
          <td style="'.$bg.'width:'.$W[1].';'.$td.'">'.htmlspecialchars($desc, ENT_QUOTES, 'UTF-8').'</td>
          <td style="'.$bg.'text-align:center;width:'.$W[2].';'.$td.'">'.$talle.'</td>
          <td style="'.$bg.'text-align:center;width:'.$W[3].';'.$td.'">'.$color.'</td>
          <td style="'.$bg.'text-align:center;width:'.$W[4].';'.$td.'">'.$cant.'</td>';
        if ($hay_precios) {
            $filas_html .=
              '<td style="'.$bg.'text-align:right;width:'.$W[5].';'.$td.'">'.$fmt($it['_pu']).'</td>'.
              '<td style="'.$bg.'text-align:right;width:'.$W[6].';'.$td.'">'.$fmt($it['_sub']).'</td>'.
              '<td style="'.$bg.'text-align:right;width:'.$W[7].';'.$td.'">'.$fmt($it['_iva']).'</td>'.
              '<td style="'.$bg.'text-align:right;width:'.$W[8].';'.$td.'">'.$fmt($it['_tot']).'</td>';
        } else {
            $filas_html .= '<td colspan="4" style="'.$bg.$td.'"></td>';
        }
        $filas_html .= '</tr>';
        $n++;
    }
    // Filas vacias (minimo 14)
    $max_rows = max(count($items), 14);
    for ($i = count($items); $i < $max_rows; $i++) {
        // Los items usan n (1-based), las filas vacias continuan el patron: n=count+1 es la siguiente
        $bg = (($i + 1) % 2 === 0) ? "background-color:$GR2;" : 'background-color:#ffffff;';
        $filas_html .= '<tr><td colspan="9" style="'.$bg.'height:17px;border-bottom:1px solid '.$ROW.'"></td></tr>';
    }
    // Fila TOTAL GENERAL
    $filas_html .= '<tr>
      <td colspan="4" style="'.$tot.';text-align:right;padding-right:5px">TOTAL GENERAL</td>
      <td style="'.$tot.';text-align:center;width:'.$W[4].'">'.$total_prendas.'</td>
      <td style="'.$tot.'"></td>';
    if ($hay_precios) {
        $filas_html .=
          '<td style="'.$tot.';text-align:right;width:'.$W[6].'">'.$fmt($subtotal_gen).'</td>'.
          '<td style="'.$tot.';text-align:right;width:'.$W[7].'">'.$fmt($iva_gen).'</td>'.
          '<td style="'.$tot.';text-align:right;width:'.$W[8].'">'.$fmt($total_gen).'</td>';
    } else {
        $filas_html .= '<td colspan="3" style="'.$tot.'"></td>';
    }
    $filas_html .= '</tr>';

    // ── Bloque de totales ─────────────────────────────────────
    $totales_html = '';
    if ($hay_precios) {
        $totales_html = '
<table style="width:190mm;border-collapse:collapse;margin-top:5px;margin-bottom:8px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:55%"></td>
    <td style="width:45%;vertical-align:top">
      <table style="width:100%;border-collapse:collapse;border:1px solid '.$BRD.'" cellpadding="0" cellspacing="0">
        <tr>
          <td style="background-color:#ffffff;padding:3px 10px;font-size:8px;border-bottom:1px solid #eeeeee">Subtotal s/IVA</td>
          <td style="background-color:#ffffff;padding:3px 10px;font-size:8px;text-align:right;border-bottom:1px solid #eeeeee">'.$fmt($subtotal_gen).'</td>
        </tr>
        <tr>
          <td style="background-color:#ffffff;padding:3px 10px;font-size:8px;border-bottom:1px solid #eeeeee">IVA 21%</td>
          <td style="background-color:#ffffff;padding:3px 10px;font-size:8px;text-align:right;border-bottom:1px solid #eeeeee">'.$fmt($iva_gen).'</td>
        </tr>
        <tr>
          <td style="background-color:'.$AZ.';padding:5px 10px;font-size:9px;font-weight:bold;color:#ffffff">TOTAL c/IVA</td>
          <td style="background-color:'.$AZ.';padding:5px 10px;font-size:9px;font-weight:bold;color:#ffffff;text-align:right">'.$fmt($total_gen).'</td>
        </tr>
      </table>
    </td>
  </tr>
</table>';
    } else {
        $totales_html = '<p style="font-size:9px;color:#666;margin:6px 0 10px;font-style:italic">Los precios ser&#225;n confirmados por nuestro equipo comercial a la brevedad.</p>';
    }

    // ── Observaciones ─────────────────────────────────────────
    $notas_html = '';
    if ($notas) {
        $notas_html = '
<table style="width:190mm;border-collapse:collapse;margin-bottom:6px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background-color:#fffbeb;padding:5px 10px;border:1px solid #fcd34d;font-size:8px">
      <strong>Observaciones:</strong> '.htmlspecialchars($notas, ENT_QUOTES, 'UTF-8').'
    </td>
  </tr>
</table>';
    }

    // ── HTML completo ─────────────────────────────────────────
    // SetMargins(0,0,0) -> tablas de header/subheader son edge-to-edge (width:100% = 210mm).
    // El contenido va dentro de una tabla con columnas espaciadoras de 10mm a cada lado.
    $html = '
<style>
  body { font-family: helvetica, Arial, sans-serif; font-size: 9px; color: #111111; margin: 0; padding: 0; }
  table { border-collapse: collapse; }
</style>

<!-- ═══ HEADER: 4 columnas (10mm spacer | 90mm izq | 100mm der | 10mm spacer = 210mm) ═══ -->
<table style="width:100%;border-collapse:collapse" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background-color:'.$AZ.';width:10mm"> </td>
    <td style="background-color:'.$AZ.';color:#ffffff;width:90mm;vertical-align:middle">
      <div style="font-size:22px;font-weight:bold;color:#ffffff;margin:0;padding:0;line-height:1.1">SAGUMA</div>
      <div style="font-size:7px;color:#aac4e8;margin:1px 0 0 0;padding:0">'.$TXT_TAGLINE.'</div>
    </td>
    <td style="background-color:'.$AZ.';color:#ffffff;width:100mm;text-align:right;vertical-align:middle;font-size:8px">
      '.$TXT_ORG.'<br>'.$TXT_ADDR.'<br>'.$TXT_CONTACT.'
    </td>
    <td style="background-color:'.$AZ.';width:10mm"> </td>
  </tr>
</table>

<!-- ═══ SUB-HEADER: misma estructura 4 columnas ═══ -->
<table style="width:100%;border-collapse:collapse;border-bottom:3px solid '.$AZ.';margin-bottom:0" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background-color:'.$AZL.';width:10mm"> </td>
    <td style="background-color:'.$AZL.';color:'.$AZ.';width:90mm;font-size:8px;font-weight:bold">'.$TXT_SUBHDR.$numero.'</td>
    <td style="background-color:'.$AZL.';color:'.$AZ.';width:100mm;font-size:8px;text-align:right">www.saguma.com.ar</td>
    <td style="background-color:'.$AZL.';width:10mm"> </td>
  </tr>
</table>

<!-- ═══ CONTENIDO: columnas espaciadoras 10mm + 190mm contenido + 10mm ═══ -->
<table style="width:100%;border-collapse:collapse" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:10mm"></td>
    <td style="width:190mm;vertical-align:top;padding-top:16px">

<!-- GRILLA DATOS CLIENTE / COTIZACION -->
<table style="width:190mm;border-collapse:collapse;margin-top:12px;margin-bottom:12px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:95mm;padding-right:4px;vertical-align:top">
      <table style="width:100%;border-collapse:collapse;border:1px solid '.$BRD.'" cellpadding="0" cellspacing="0">
        <tr><td style="background-color:'.$GR1.';padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_EMPRESA.'</div>
          <div style="'.$val.'"><strong>'.$cliente.'</strong></div>
        </td></tr>
        <tr><td style="background-color:#ffffff;padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_CONTACTO.'</div>
          <div style="'.$val.'">'.($contacto ?: '&#8212;').'</div>
        </td></tr>
        <tr><td style="background-color:'.$GR1.';padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_TEL.'</div>
          <div style="'.$val.'">'.($telefono ?: '&#8212;').'</div>
        </td></tr>
        <tr><td style="background-color:#ffffff;padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_MAIL.'</div>
          <div style="'.$val.'">'.$mail.'</div>
        </td></tr>
      </table>
    </td>
    <td style="width:95mm;padding-left:4px;vertical-align:top">
      <table style="width:100%;border-collapse:collapse;border:1px solid '.$BRD.'" cellpadding="0" cellspacing="0">
        <tr><td style="background-color:'.$GR1.';padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_COT.'</div>
          <div style="'.$val.'"><strong>'.$numero.'</strong></div>
        </td></tr>
        <tr><td style="background-color:#ffffff;padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_FENVIO.'</div>
          <div style="'.$val.'">'.$fecha_envio.'</div>
        </td></tr>
        <tr><td style="background-color:'.$GR1.';padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_VALIDA.'</div>
          <div style="'.$val.'">'.$fecha_valida.'</div>
        </td></tr>
        <tr><td style="background-color:#ffffff;padding:1px 6px;border:1px solid '.$BRD.'">
          <div style="'.$lbl.'">'.$LBL_PLAZO.'</div>
          <div style="'.$val.'">'.$plazo.'</div>
        </td></tr>
      </table>
    </td>
  </tr>
</table>

<!-- TABLA DE PRODUCTOS -->
<!-- Anchos en mm, identicos en TH y TD para alinear columnas -->
<!-- #=5 Desc=55 Talle=14 Color=13 Cant=11 PU=21 Sub=22 IVA=20 Tot=29 => 190mm -->
<table style="width:190mm;border-collapse:collapse;margin-bottom:2px" cellpadding="0" cellspacing="0">
  <thead>
    <tr>
      <th style="'.$th.';text-align:center;width:5mm">#</th>
      <th style="'.$th.';text-align:left;width:55mm">'.$TH_DESC.'</th>
      <th style="'.$th.';text-align:center;width:14mm">TALLE</th>
      <th style="'.$th.';text-align:center;width:13mm">COLOR</th>
      <th style="'.$th.';text-align:center;width:11mm">CANT.</th>
      <th style="'.$th.';text-align:right;width:21mm">P.UNIT S/IVA</th>
      <th style="'.$th.';text-align:right;width:22mm">SUBTOTAL</th>
      <th style="'.$th.';text-align:right;width:20mm">IVA 21%</th>
      <th style="'.$th.';text-align:right;width:29mm">TOTAL C/IVA</th>
    </tr>
  </thead>
  <tbody>
    '.$filas_html.'
  </tbody>
</table>

'.$totales_html.'

<!-- CONDICIONES -->
'.$notas_html.'
<table style="width:190mm;border-collapse:collapse;border:1px solid '.$BRD.';border-left:3px solid '.$AZ.';margin-bottom:8px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background-color:#f5f7fb;padding:7px 14px">
      <div style="font-size:8px;font-weight:bold;color:'.$AZ.';margin-bottom:4px">'.$TXT_COND_HDR.'</div>
      '.$TXT_COND.'
    </td>
  </tr>
</table>

<!-- PIE -->
<table style="width:190mm;border-collapse:collapse;border-top:1px solid #eeeeee" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background-color:#ffffff;padding:6px;text-align:center;font-size:7px;color:#aaaaaa">'.$TXT_PIE.'</td>
  </tr>
</table>

    </td>
    <td style="width:10mm"></td>
  </tr>
</table>';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGUMA CRM');
    $pdf->SetTitle('Cotizacion ' . $numero);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(true, 8);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    $tmp = sys_get_temp_dir() . '/cot_' . str_replace('-','', $numero) . '_' . time() . '.pdf';
    $pdf->Output($tmp, 'F');
    return $tmp;
}


function _mailCliente($contacto, $cliente, $numero, $fecha, $total_prendas, $wa_aceptar, $wa_dudas) {
    $nombre = explode(' ', trim($contacto))[0] ?: 'Estimado/a';
    $fecha_fmt = date('d/m/Y');

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f1ec;font-family:Helvetica,Arial,sans-serif;">
<div style="max-width:600px;margin:0 auto;padding:32px 16px;">
<div style="background:#0a1e3d;border-radius:12px 12px 0 0;padding:32px 40px;text-align:center;">
<h1 style="font-size:28px;color:#fff;margin:0 0 4px;letter-spacing:3px;">SAGUMA</h1>
<p style="font-size:11px;color:rgba(255,255,255,.4);letter-spacing:2px;text-transform:uppercase;margin:0;">Indumentaria laboral</p></div>
<div style="background:#fff;padding:40px;border:1px solid #e9ecef;border-top:none;">
<p style="font-size:16px;color:#1a2333;margin:0 0 20px;line-height:1.6;">Hola <strong>'.htmlspecialchars($nombre).'</strong>,</p>
<p style="font-size:15px;color:#343a40;margin:0 0 16px;line-height:1.7;">Gracias por tu consulta. Adjunto encontrás la cotización <strong>'.htmlspecialchars($numero).'</strong> correspondiente al pedido de <strong>'.$total_prendas.' prendas</strong> para <strong>'.htmlspecialchars($cliente).'</strong>.</p>
<p style="font-size:15px;color:#343a40;margin:0 0 24px;line-height:1.7;">Revisá el PDF adjunto con el detalle completo. La cotización tiene una validez de 7 días desde la fecha de emisión ('.$fecha_fmt.').</p>
<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:10px;padding:20px;margin-bottom:32px;">
<p style="font-size:13px;color:#6b7a99;margin:0;line-height:1.6;">📎 <strong style="color:#1a2333;">El detalle de productos, cantidades y personalización está en el PDF adjunto a este mail.</strong></p></div>
<p style="font-size:14px;color:#6b7a99;margin:0 0 16px;text-align:center;">¿Cómo querés continuar?</p>
<div style="text-align:center;margin-bottom:12px;"><a href="'.$wa_aceptar.'" target="_blank" style="display:inline-block;background:#25d366;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;">✓ Aceptar cotización</a></div>
<div style="text-align:center;margin-bottom:8px;"><a href="'.$wa_dudas.'" target="_blank" style="display:inline-block;background:transparent;color:#2563b0;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;border:1.5px solid #2563b0;">Tengo dudas / Consultar</a></div>
<p style="font-size:12px;color:#adb5bd;text-align:center;margin:16px 0 0;">También podés responder directamente a este mail.</p></div>
<div style="padding:24px 40px;text-align:center;background:#f8f9fa;border-radius:0 0 12px 12px;border:1px solid #e9ecef;border-top:none;">
<p style="font-size:12px;color:#adb5bd;margin:0;">SAGUMA · Indumentaria Laboral · Buenos Aires, Argentina</p>
<p style="font-size:11px;color:#adb5bd;margin:4px 0 0;">ventas@saguma.com.ar · +54 11 6112 5719</p></div>
</div></body></html>';
}

// ── MAIL INTERNO ────────────────────────────────────────────
function _mailInterno($data, $items, $numero, $fecha, $id) {
    $tp = array_sum(array_column($items,'cantidad'));
    $filas='';
    foreach ($items as $it) {
        $cat = $it['categoria']??''; $prod = $it['producto']??''; $custom = $it['personalizacion']??'-';
        $desc = trim($cat . ' ' . strtolower($prod));
        if ($custom==='Bordado') $desc .= ' c/ bordado';
        elseif ($custom==='Estampado') $desc .= ' c/ estampado';
        elseif ($custom==='Ambos') $desc .= ' c/ bordado y estampado';
        $filas.='<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:13px;">'.htmlspecialchars(ucfirst($desc)).'</td><td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:center;font-size:13px;">'.intval($it['cantidad']).'</td></tr>';
    }
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px;background:#f4f1ec;font-family:Arial,sans-serif;">
<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;">
<div style="background:#0a1e3d;color:#fff;padding:20px 24px;">
<h2 style="margin:0;font-size:18px;">Nueva Cotización — '.htmlspecialchars($numero).'</h2>
<p style="margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.5);">Cotizador web · '.date('d/m/Y H:i').'</p></div>
<div style="padding:24px;">
<table style="font-size:14px;margin-bottom:24px;">
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Empresa:</td><td style="font-weight:600;">'.htmlspecialchars($data['cliente']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Contacto:</td><td>'.htmlspecialchars($data['contacto']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Email:</td><td>'.htmlspecialchars($data['mail']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Teléfono:</td><td>'.htmlspecialchars($data['telefono']??'-').'</td></tr></table>
<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
<thead><tr style="background:#f8f9fa;"><th style="padding:8px 12px;text-align:left;font-size:11px;color:#6b7a99;border-bottom:2px solid #dee2e6;">Producto</th><th style="padding:8px 12px;text-align:center;font-size:11px;color:#6b7a99;border-bottom:2px solid #dee2e6;">Cant.</th></tr></thead>
<tbody>'.$filas.'</tbody></table>
'.(!empty($data['notas'])?'<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;margin-bottom:16px;"><strong style="font-size:12px;color:#856404;">Observaciones:</strong><p style="font-size:13px;color:#856404;margin:4px 0 0;">'.nl2br(htmlspecialchars($data['notas'])).'</p></div>':'').'
<a href="'.CRM_BASE_URL.'/#cotizaciones" style="display:inline-block;background:#2563b0;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">Ver en CRM</a>
</div></div></body></html>';
}

// ── COSTOS Y PRECIOS ────────────────────────────────────────
function _getCostosPersonalizacion($pdo) {
    $c = ['Bordado'=>0,'Estampado'=>0,'Ambos'=>0];
    try {
        $rows = $pdo->query("SELECT LOWER(tela) as n, precio FROM productos WHERE LOWER(categoria) = 'adicionales'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (strpos($r['n'],'bordado')!==false) $c['Bordado']=floatval($r['precio']??0);
            if (strpos($r['n'],'estampado')!==false) $c['Estampado']=floatval($r['precio']??0);
        }
        $c['Ambos']=$c['Bordado']+$c['Estampado'];
    } catch (Exception $e) { error_log("SAGUMA costos: ".$e->getMessage()); }
    return $c;
}

function _calcularPrecios($pdo, $items, $costos) {
    foreach ($items as &$it) {
        // Si el precio ya viene calculado del frontend (con recargo por talle), usarlo
        if (!empty($it['precio']) && floatval($it['precio']) > 0) {
            $pu = floatval($it['precio']);
        } else {
            $base = 0;
            if (!empty($it['producto_id'])) {
                $st = $pdo->prepare("SELECT precio FROM productos WHERE id=?");
                $st->execute([$it['producto_id']]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $base = floatval($r['precio'] ?? 0);
            }
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $cc = $costos[$custom] ?? 0;
            $pu = $base + $cc;
        }
        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $it['precio_base'] = $pu;
        $it['costo_custom'] = 0;
        $it['subtotal'] = $pu * $cant;
    }
    unset($it);
    return $items;
}

// ── ENVÍO ───────────────────────────────────────────────────
function _enviarMailConPDF($to, $to_name, $subject, $html, $pdf_path, $pdf_filename, $cc = null) {
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP(); $m->Host=SMTP_HOST; $m->SMTPAuth=true;
        $m->Username=SMTP_USER; $m->Password=SMTP_PASS;
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Port=SMTP_PORT; $m->CharSet='UTF-8';
        $m->Timeout=10;
        $m->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $m->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $m->addAddress($to, $to_name);
        if ($cc) $m->addCC($cc);
        $m->isHTML(true); $m->Subject=$subject; $m->Body=$html;
        $m->AltBody=strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html));
        if ($pdf_path && file_exists($pdf_path)) $m->addAttachment($pdf_path, $pdf_filename, 'base64', 'application/pdf');
        $m->send(); return true;
    } catch (Exception $e) { error_log("SAGUMA PHPMailer: ".$m->ErrorInfo); return false; }
}
