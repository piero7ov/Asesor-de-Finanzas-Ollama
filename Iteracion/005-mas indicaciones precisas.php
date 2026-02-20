<?php
/* ==========================================================
   Asesor de Finanzas (PHP calcula + IA da 5 tips buenos)
   ----------------------------------------------------------
   - PHP calcula resultados numéricos fiables (margen, ahorro/mes, cuota, tiempo)
   - IA entrega SOLO 5 tips accionables, personalizados y sin números
   - PHP compone:
       Resumen (PHP) + 5 tips (IA) + Calculos (PHP) + RESULTADO (PHP)
   ========================================================== */

ini_set('max_execution_time', '300');
set_time_limit(300);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$model   = "mistral:instruct";
$baseUrl = "http://127.0.0.1:11434";

/* Inputs */
$defaultPrompt = "";
$prompt = $_POST["prompt"] ?? $defaultPrompt;

$ingresos = $_POST["ingresos"] ?? "";

$g_alquiler    = $_POST["g_alquiler"] ?? "";
$g_luz         = $_POST["g_luz"] ?? "";
$g_agua        = $_POST["g_agua"] ?? "";
$g_internet    = $_POST["g_internet"] ?? "";
$g_comida      = $_POST["g_comida"] ?? "";
$g_transporte  = $_POST["g_transporte"] ?? "";
$g_otros       = $_POST["g_otros"] ?? "";

$meta       = $_POST["meta"] ?? "";
$plazoMeses = $_POST["plazoMeses"] ?? "";

$result = null;

/* ------------------------------
   Helpers numéricos
-------------------------------- */
function numOrBlank($v): string {
  $v = trim((string)$v);
  if ($v === "") return "";
  $v = str_replace(",", ".", $v);
  if (!is_numeric($v)) return "";
  return (string)$v;
}

function fmt_eur_es($n): string {
  $s = number_format((float)$n, 2, ',', '');
  $s = preg_replace('/,00$/', '', $s);
  return $s;
}

function extract_all_money(string $txt): array {
  $t = trim($txt);
  if ($t === "") return [];
  $t = str_replace(["€", "EUR", "eur"], "", $t);
  preg_match_all('/[-+]?\d[\d\.,]*/', $t, $mm);
  $nums = $mm[0] ?? [];
  $out = [];

  foreach ($nums as $raw) {
    $n = $raw;

    if (strpos($n, '.') !== false && strpos($n, ',') !== false) {
      $n = str_replace('.', '', $n);
      $n = str_replace(',', '.', $n);
    } else {
      if (strpos($n, ',') !== false && strpos($n, '.') === false) {
        $n = str_replace(',', '.', $n);
      }
      if (strpos($n, '.') !== false && strpos($n, ',') === false) {
        $parts = explode('.', $n);
        $last = end($parts);
        if (strlen($last) === 3) $n = str_replace('.', '', $n);
      }
    }

    if (is_numeric($n)) $out[] = (float)$n;
  }

  return $out;
}

function guess_goal_amount(string $question, string $metaTxt): ?float {
  $metaNums = extract_all_money($metaTxt);
  if (!empty($metaNums)) return $metaNums[0];

  $q = $question;
  $keywords = [
    "cuesta", "precio", "vale", "coste", "costo", "total",
    "ordenador", "pc", "coche", "moto", "movil", "móvil", "telefono", "teléfono", "viaje",
    "objetivo", "meta", "ahorrar", "juntar", "reunir", "comprar"
  ];

  foreach ($keywords as $kw) {
    if (preg_match('/\b' . preg_quote($kw, '/') . '\b.{0,25}(\d[\d\.,]*)/iu', $q, $m)) {
      $nums = extract_all_money($m[1]);
      if (!empty($nums)) return $nums[0];
    }
    if (preg_match('/(\d[\d\.,]*).{0,15}\b' . preg_quote($kw, '/') . '\b/iu', $q, $m)) {
      $nums = extract_all_money($m[1]);
      if (!empty($nums)) return $nums[0];
    }
  }

  $all = extract_all_money($q);
  if (empty($all)) return null;
  return max($all);
}

function detect_intent(string $q): string {
  $t = mb_strtolower(trim($q), 'UTF-8');

  $isTiempo =
    (strpos($t, "cuanto tiempo") !== false) ||
    (strpos($t, "cuánto tiempo") !== false) ||
    (strpos($t, "tard") !== false) ||
    (strpos($t, "cuantos meses") !== false) ||
    (strpos($t, "cuántos meses") !== false) ||
    (strpos($t, "en cuantos meses") !== false) ||
    (strpos($t, "en cuántos meses") !== false);

  $isCuota =
    (strpos($t, "financ") !== false) ||
    (strpos($t, "cuota") !== false)  ||
    (strpos($t, "pagar") !== false);

  $isMargen =
    (strpos($t, "me queda") !== false) ||
    (strpos($t, "ocio") !== false)     ||
    (strpos($t, "margen") !== false)   ||
    (strpos($t, "sobr") !== false)     ||
    (strpos($t, "disponible") !== false);

  $isAhorro =
    (strpos($t, "ahorr") !== false)   ||
    (strpos($t, "meta") !== false)    ||
    (strpos($t, "objetiv") !== false) ||
    (strpos($t, "juntar") !== false)  ||
    (strpos($t, "reunir") !== false);

  if ($isTiempo) return "tiempo";
  if ($isCuota)  return "cuota";
  if ($isMargen) return "margen";
  if ($isAhorro) return "ahorro";
  return "general";
}

function result_key_value(string $resultLine): array {
  if (preg_match('/^RESULTADO:\s*([A-Za-z_]+)\s*=\s*(.+)$/u', trim($resultLine), $m)) {
    return ["key" => trim($m[1]), "value" => trim($m[2])];
  }
  return ["key" => "Conclusion", "value" => ""];
}

/* ----------------------------------------------------------
   Tips: extracción más estricta para evitar tips "obvios"
   - Acepta solo tips sin números y con señales de acción
   - Rechaza tips que definan el cálculo o repitan "resta"
---------------------------------------------------------- */
function extract_5_tips(string $aiText, string $intentType, ?float $diff): array {
  $aiText = str_replace(["\r\n", "\r"], "\n", $aiText);
  $lines = explode("\n", $aiText);

  $tips = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;
    if (stripos($ln, "tip:") !== 0) continue;

    // sin números
    if (preg_match('/\d/', $ln)) continue;
    if (strpos($ln, "€") !== false) continue;

    $low = mb_strtolower($ln, "UTF-8");

    // rechaza tips que expliquen el cálculo o sean "obvios"
    $ban = ["resta", "diferencia entre", "ingresos y gastos", "calcula la diferencia", "calcular el margen", "para calcular"];
    foreach ($ban as $w) {
      if (strpos($low, $w) !== false) { continue 2; }
    }

    // debe ser accionable (alguna palabra típica de acción)
    $actions = ["acuerda", "define", "separa", "automatiza", "crea", "revisa", "reduce", "limita", "prioriza", "planifica", "anota", "divide", "reserva", "pon", "cancela", "compara", "negocia", "establece"];
    $okAction = false;
    foreach ($actions as $a) {
      if (strpos($low, $a) !== false) { $okAction = true; break; }
    }
    if (!$okAction) continue;

    if (mb_strlen($ln, 'UTF-8') > 170) {
      $ln = mb_substr($ln, 0, 170, 'UTF-8');
      $ln = rtrim($ln, " ,.;:-") . ".";
    }

    $tips[] = $ln;
    if (count($tips) >= 5) break;
  }

  /* Fallbacks accionables (sin números) */
  $fallback = [];

  if ($intentType === "margen") {
    $fallback = [
      "Tip: Define un presupuesto de ocio semanal y respétalo como si fuera un gasto fijo.",
      "Tip: Separa gastos comunes y personales para que el ocio no compita con lo esencial.",
      "Tip: Revisa suscripciones y pagos recurrentes y cancela lo que no uses de verdad.",
      "Tip: Crea una cuenta o apartado separado para ocio y recárgalo al inicio del mes.",
      "Tip: Anota gastos pequeños diarios durante una semana para detectar fugas rápidas."
    ];
  } elseif ($intentType === "ahorro") {
    if ($diff !== null && $diff < 0) {
      $fallback = [
        "Tip: Prioriza recortes en gastos no esenciales antes de tocar necesidades básicas.",
        "Tip: Ajusta el plazo o la meta para que el plan sea sostenible y no se abandone a mitad.",
        "Tip: Automatiza un apartado al inicio del mes y evita mover ese dinero después.",
        "Tip: Revisa compras impulsivas y pon una regla de espera antes de comprar.",
        "Tip: Negocia o compara servicios recurrentes para bajar gastos sin perder calidad."
      ];
    } else {
      $fallback = [
        "Tip: Automatiza el ahorro al inicio del mes para que no dependa de la voluntad.",
        "Tip: Reserva un fondo para imprevistos y así no rompes el plan ante una sorpresa.",
        "Tip: Divide la meta en hitos y revisa el avance cada semana para ajustar rápido.",
        "Tip: Separa el dinero de la meta de tu cuenta diaria para no gastarlo sin darte cuenta.",
        "Tip: Planifica gastos grandes del mes para evitar que te desordenen el ahorro."
      ];
    }
  } elseif ($intentType === "tiempo") {
    $fallback = [
      "Tip: Define una cantidad fija de ahorro y trátala como prioridad antes del ocio.",
      "Tip: Reduce gastos variables primero y mantén el ajuste simple para sostenerlo en el tiempo.",
      "Tip: Reserva un colchón para imprevistos para no pausar el plan cuando haya sorpresas.",
      "Tip: Planifica compras grandes y evita compras pequeñas repetidas que frenan el avance.",
      "Tip: Revisa el plan cada mes y ajusta según cambien tus gastos o ingresos."
    ];
  } elseif ($intentType === "cuota") {
    $fallback = [
      "Tip: Define un límite de cuota que no comprometa tus gastos esenciales.",
      "Tip: Reserva un fondo de imprevistos para no depender de crédito ante una emergencia.",
      "Tip: Compara alternativas más baratas antes de asumir un compromiso mensual largo.",
      "Tip: Revisa y recorta gastos prescindibles para mejorar tu capacidad de pago.",
      "Tip: Negocia condiciones y evita comprometerte si tu margen mensual queda demasiado justo."
    ];
  } else {
    $fallback = [
      "Tip: Separa gastos fijos y variables y mantén pocas categorías para poder seguirlas.",
      "Tip: Define un día del mes para revisar tu presupuesto y ajustar con calma.",
      "Tip: Automatiza lo importante y decide el resto con un límite claro para evitar improvisar.",
      "Tip: Reserva un pequeño colchón mensual para imprevistos y protege tu estabilidad.",
      "Tip: Anota gastos diarios una semana y recorta lo que no aporte valor real."
    ];
  }

  $i = 0;
  while (count($tips) < 5 && $i < count($fallback)) {
    $tips[] = "Tip: " . preg_replace('/^Tip:\s*/i', '', $fallback[$i]);
    $i++;
  }

  while (count($tips) < 5) {
    $tips[] = "Tip: Define un límite claro para gastos no esenciales y revísalo cada semana.";
  }

  return array_slice($tips, 0, 5);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userText = trim((string)$prompt);
  $metaTxt  = trim((string)$meta);

  $intentType = detect_intent($userText . " " . $metaTxt);

  /* Cálculos base */
  $ing = numOrBlank($ingresos);
  $ingF = ($ing !== "") ? (float)$ing : null;

  $gMapRaw = [
    "Alquiler/Hipoteca" => $g_alquiler,
    "Luz"               => $g_luz,
    "Agua"              => $g_agua,
    "Internet"          => $g_internet,
    "Comida"            => $g_comida,
    "Transporte"        => $g_transporte,
    "Otros"             => $g_otros,
  ];

  $gMapNum = [];
  $totalGastos = 0.0;

  foreach ($gMapRaw as $name => $val) {
    $n = numOrBlank($val);
    if ($n !== "") {
      $f = (float)$n;
      $gMapNum[$name] = $f;
      $totalGastos += $f;
    }
  }

  $margen = null;
  if ($ingF !== null && !empty($gMapNum)) {
    $margen = $ingF - $totalGastos;
  }

  $pm = numOrBlank($plazoMeses);
  $plazoF = ($pm !== "") ? (float)$pm : null;

  $metaAmount = null;
  $ahorroMensual = null;
  $diff = null;
  $precio = null;
  $cuotaMensual = null;
  $mesesExactos = null;
  $mesesRedondeo = null;

  $calcLines = [];

  if (!empty($gMapNum)) {
    $calcLines[] = "Calculo: Total_gastos_fijos_EUR = " . fmt_eur_es($totalGastos) . "€";
  }
  if ($margen !== null) {
    $calcLines[] = "Calculo: Dinero_disponible_EUR = " . fmt_eur_es($ingF) . "€ - " . fmt_eur_es($totalGastos) . "€ = " . fmt_eur_es($margen) . "€";
  }

  $resultLine = "";
  $summaryLine = "";

  if ($intentType === "margen") {
    if ($margen !== null) {
      $summaryLine = "Resumen: Te quedan " . fmt_eur_es($margen) . "€ al mes después de tus gastos fijos.";
      $resultLine  = "RESULTADO: Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine = "Resumen: No puedo calcular lo que te queda sin ingresos y gastos.";
      $resultLine  = "RESULTADO: Conclusion=Para calcular lo que te queda necesito ingresos y gastos.";
    }

  } elseif ($intentType === "ahorro") {
    $metaAmount = guess_goal_amount($userText, $metaTxt);

    if ($metaAmount !== null && $plazoF !== null && $plazoF > 0) {
      $ahorroMensual = $metaAmount / $plazoF;
      $calcLines[] = "Calculo: Ahorro_mensual_EUR = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($ahorroMensual) . "€";

      if ($margen !== null) {
        $diff = $margen - $ahorroMensual;
        $calcLines[] = "Calculo: Diferencia_EUR = " . fmt_eur_es($margen) . "€ - " . fmt_eur_es($ahorroMensual) . "€ = " . fmt_eur_es($diff) . "€";

        if ($diff < 0) $summaryLine = "Resumen: Para esa meta, deberías apartar " . fmt_eur_es($ahorroMensual) . "€ al mes, pero con tus gastos actuales no te alcanza.";
        else          $summaryLine = "Resumen: Para esa meta, deberías apartar " . fmt_eur_es($ahorroMensual) . "€ al mes y con tus datos actuales es viable.";
      } else {
        $summaryLine = "Resumen: Para esa meta, deberías apartar " . fmt_eur_es($ahorroMensual) . "€ al mes.";
      }

      $resultLine = "RESULTADO: Ahorro_mensual_EUR=" . fmt_eur_es($ahorroMensual) . "€";
    } else {
      $summaryLine = "Resumen: Falta información para calcular el ahorro mensual.";
      $resultLine  = "RESULTADO: Conclusion=Para calcular el ahorro mensual necesito una meta en € y un plazo en meses.";
    }

  } elseif ($intentType === "tiempo") {
    $metaAmount = guess_goal_amount($userText, $metaTxt);

    if ($metaAmount === null) {
      $summaryLine = "Resumen: No puedo calcular el tiempo sin un precio o meta en €.";
      $resultLine  = "RESULTADO: Conclusion=Para calcular el tiempo necesito el precio/meta en €.";
    } elseif ($margen === null) {
      $summaryLine = "Resumen: No puedo calcular el tiempo sin ingresos y gastos.";
      $resultLine  = "RESULTADO: Conclusion=Para calcular el tiempo necesito ingresos y gastos.";
    } elseif ($margen <= 0) {
      $summaryLine = "Resumen: Con tus gastos actuales no tienes dinero disponible para ahorrar.";
      $resultLine  = "RESULTADO: Conclusion=Con tus gastos actuales no hay dinero disponible para ahorrar.";
    } else {
      $mesesExactos = $metaAmount / $margen;
      $mesesRedondeo = (int)ceil($mesesExactos);

      $calcLines[] = "Calculo: Meses_necesarios = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($margen) . "€ = " . fmt_eur_es($mesesExactos) . " meses";
      $calcLines[] = "Calculo: Meses_redondeados = " . fmt_eur_es($mesesRedondeo) . " meses";

      $summaryLine = "Resumen: Con tu dinero disponible actual, tardarías aproximadamente " . fmt_eur_es($mesesRedondeo) . " meses.";
      $resultLine  = "RESULTADO: Tiempo_meses=" . fmt_eur_es($mesesRedondeo);
    }

  } elseif ($intentType === "cuota") {
    $precio = guess_goal_amount($userText, $metaTxt);

    if ($precio !== null && $plazoF !== null && $plazoF > 0) {
      $cuotaMensual = $precio / $plazoF;
      $calcLines[] = "Calculo: Cuota_mensual_EUR = " . fmt_eur_es($precio) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($cuotaMensual) . "€";

      $summaryLine = "Resumen: La cuota mensual básica sería " . fmt_eur_es($cuotaMensual) . "€ (sin intereses).";
      $resultLine  = "RESULTADO: Cuota_mensual_EUR=" . fmt_eur_es($cuotaMensual) . "€";
    } else {
      $summaryLine = "Resumen: Falta información para calcular la cuota mensual.";
      $resultLine  = "RESULTADO: Conclusion=Para calcular cuota mensual necesito un precio en € y un plazo en meses.";
    }

  } else {
    if ($margen !== null) {
      $summaryLine = "Resumen: Con tus datos actuales, te quedan " . fmt_eur_es($margen) . "€ disponibles al mes.";
      $resultLine  = "RESULTADO: Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine = "Resumen: Falta información para poder ayudarte con un cálculo fiable.";
      $resultLine  = "RESULTADO: Conclusion=Necesito ingresos y gastos para calcular algo fiable.";
    }
  }

  /* Prompt mejorado: tips accionables, personalizados y sin números */
  $rv = result_key_value($resultLine);

  $system =
    "Eres un consejero de finanzas personales BASICO para gente que se independiza.\n".
    "Debes dar consejos prácticos y accionables según la situación.\n".
    "\n".
    "SALIDA: exactamente 5 líneas.\n".
    "FORMATO: cada línea empieza por 'Tip:'\n".
    "\n".
    "REGLAS:\n".
    "- No uses números, ni moneda, ni porcentajes.\n".
    "- No expliques cómo se hace el cálculo.\n".
    "- No repitas el resumen.\n".
    "- Al menos 3 tips deben ser acciones concretas (cosas que puedan hacer hoy).\n".
    "- Personaliza: usa el contexto (por ejemplo pareja, ocio, meta, coche, etc.).\n".
    "- Si detectas que falta información clave, convierte 1 tip en una pregunta concreta para obtener ese dato.\n";

  $ctx  = "INTENT={$intentType}\n";
  $ctx .= "RESUMEN_PHP={$summaryLine}\n";
  $ctx .= "RESULT_KEY={$rv["key"]}\n";
  $ctx .= "RESULT_VALUE={$rv["value"]}\n";
  $ctx .= "PREGUNTA_USUARIO={$userText}\n";
  if ($metaTxt !== "") $ctx .= "META_TEXTO={$metaTxt}\n";

  $finalPrompt = $system . "\nCONTEXTO:\n{$ctx}";

  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false,
    "options" => [
      "num_predict"  => 700,
      "temperature"  => 0.55
    ]
  ];

  $url = rtrim($baseUrl, "/") . "/api/generate";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 240,
  ]);

  $raw = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    $result = ["ok" => false, "err" => "cURL error: " . $curlErr, "out" => ""];
  } elseif ($httpCode < 200 || $httpCode >= 300) {
    $result = ["ok" => false, "err" => "HTTP $httpCode\n$raw", "out" => ""];
  } else {
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      $result = ["ok" => false, "err" => "Respuesta no-JSON:\n$raw", "out" => ""];
    } else {
      $aiRaw = (string)($json["response"] ?? "");
      $tipsArr = extract_5_tips($aiRaw, $intentType, $diff);

      $finalOut = $summaryLine . "\n\n" . implode("\n", $tipsArr);

      if (!empty($calcLines)) {
        $finalOut .= "\n\n" . implode("\n", $calcLines);
      }
      $finalOut .= "\n" . $resultLine;

      $result = ["ok" => true, "out" => $finalOut, "err" => ""];
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asesor de Finanzas</title>
  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --border:#e6e8f0; --text:#111827;
      --muted:#6b7280; --accent:#2563eb; --radius:12px;
    }
    *{box-sizing:border-box}
    body{
      margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:var(--bg); color:var(--text); padding:20px;
    }
    .wrap{max-width:980px; margin:0 auto; display:flex; flex-direction:column; gap:14px;}
    h1{margin:0 0 6px; font-size:20px;}
    .sub{margin:0 0 14px; color:var(--muted); font-size:14px; line-height:1.4;}
    .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:14px;}
    label{display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--muted);}
    input, textarea{
      width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px;
      outline:none; font:inherit; background:#fff;
    }
    textarea{min-height:140px; resize:vertical;}
    input:focus, textarea:focus{border-color:rgba(37,99,235,.55); box-shadow:0 0 0 4px rgba(37,99,235,.10);}
    .hint{margin:12px 0; color:var(--muted); font-size:14px; line-height:1.4;}
    button{
      border:1px solid rgba(37,99,235,.35); background:var(--accent); color:#fff;
      padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:700;
    }
    button:hover{filter:brightness(.95)}
    .grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px;}
    .grid3{display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;}
    .sep{margin:12px 0; border-top:1px dashed var(--border);}
    .err{
      background:#fff1f2; border:1px solid #fecdd3; color:#7f1d1d;
      padding:10px 12px; border-radius:10px; white-space:pre-wrap;
    }
    pre{
      white-space:pre-wrap; word-wrap:break-word; background:#0b1220; color:#e5e7eb;
      padding:12px; border-radius:10px; overflow:auto; margin:0;
    }
    small{color:var(--muted)}
    @media (max-width: 820px){
      .grid,.grid3{grid-template-columns:1fr}
    }
  </style>
</head>
<body>

<div class="wrap">
  <div>
    <h1>Asesor de Finanzas</h1>
    <p class="sub">PHP calcula y Ollama da tips. (Sin Markdown por ahora)</p>
  </div>

  <div class="card">
    <form method="post">
      <p class="hint">
        Ejemplos:<br>
        - "Cuánto me queda para ocio con estos gastos?"<br>
        - "Quiero ahorrar 14000€ en 24 meses, cuánto debo apartar al mes?"<br>
        - "Cuánto tiempo tardaré en ahorrar 1300€?"<br>
        - "Quiero pagar algo de 10000€ en 36 meses, cuánto es al mes?" (cuota básica: precio/meses)
      </p>

      <label for="prompt">Tu pregunta (texto libre)</label>
      <textarea id="prompt" name="prompt"><?=h($prompt)?></textarea>

      <div class="sep"></div>

      <p class="hint" style="margin-top:0;">
        Formulario (opcional): rellena lo que tengas. Si no sabes algo, déjalo en blanco.
      </p>

      <div class="grid">
        <div>
          <label for="ingresos">Ingresos mensuales (€)</label>
          <input id="ingresos" name="ingresos" value="<?=h($ingresos)?>" placeholder="Ej: 1600">
        </div>
        <div>
          <label for="g_alquiler">Alquiler / Hipoteca (€)</label>
          <input id="g_alquiler" name="g_alquiler" value="<?=h($g_alquiler)?>" placeholder="Ej: 800">
        </div>
      </div>

      <div class="grid3" style="margin-top:12px;">
        <div>
          <label for="g_luz">Luz (€)</label>
          <input id="g_luz" name="g_luz" value="<?=h($g_luz)?>" placeholder="Ej: 50">
        </div>
        <div>
          <label for="g_agua">Agua (€)</label>
          <input id="g_agua" name="g_agua" value="<?=h($g_agua)?>" placeholder="Ej: 15">
        </div>
        <div>
          <label for="g_internet">Internet (€)</label>
          <input id="g_internet" name="g_internet" value="<?=h($g_internet)?>" placeholder="Ej: 20">
        </div>
      </div>

      <div class="grid3" style="margin-top:12px;">
        <div>
          <label for="g_comida">Comida (€)</label>
          <input id="g_comida" name="g_comida" value="<?=h($g_comida)?>" placeholder="Ej: 150">
        </div>
        <div>
          <label for="g_transporte">Transporte (€)</label>
          <input id="g_transporte" name="g_transporte" value="<?=h($g_transporte)?>" placeholder="Ej: 20">
        </div>
        <div>
          <label for="g_otros">Otros (€)</label>
          <input id="g_otros" name="g_otros" value="<?=h($g_otros)?>" placeholder="Ej: 50">
        </div>
      </div>

      <div class="grid" style="margin-top:12px;">
        <div>
          <label for="meta">Meta / objetivo (opcional)</label>
          <input id="meta" name="meta" value="<?=h($meta)?>" placeholder="Ej: ahorrar 1300€ para un objetivo">
        </div>
        <div>
          <label for="plazoMeses">Plazo en meses (opcional)</label>
          <input id="plazoMeses" name="plazoMeses" value="<?=h($plazoMeses)?>" placeholder="Ej: 24">
        </div>
      </div>

      <div style="margin-top:12px;">
        <button type="submit">Preguntar</button>
      </div>
    </form>
  </div>

  <?php if ($result !== null): ?>
    <div class="card">
      <h2 style="margin:0 0 10px; font-size:16px;">Respuesta</h2>

      <?php if (!$result["ok"]): ?>
        <div class="err"><?=nl2br(h($result["err"]))?></div>
      <?php else: ?>
        <pre><?=h($result["out"])?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <small>Si no responde, verifica que Ollama esté ejecutándose en tu PC.</small>
  </div>
</div>

</body>
</html>
