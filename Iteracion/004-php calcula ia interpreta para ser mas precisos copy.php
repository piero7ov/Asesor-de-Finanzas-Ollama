<?php
/* ==========================================================
   Asesor de Finanzas
   ----------------------------------------------------------
   - PHP calcula (fiable): gastos, disponible, ahorro/mes, cuota, diferencia
   - IA explica y da tips: sin inventar cifras, sin fórmulas en tips
   - PHP compone salida final: IA arriba + cálculos + RESULTADO al final
   ----------------------------------------------------------
   Cambios de afinado:
   - IA: salida 4 a 8 líneas (más natural)
   - Tips: 1 a 2 tips, cada tip una sola frase corta, sin fórmulas
   - Si existe Diferencia_EUR: IA debe decir "te faltan" o "te sobran"
   - Limpieza: permitimos hasta 10 líneas para no cortar frases
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

function parseMoneyFromText(string $txt): ?float {
  $t = trim($txt);
  if ($t === "") return null;

  $t = str_replace(["€", "EUR", "eur", " "], "", $t);

  if (!preg_match('/[-+]?\d[\d\.,]*/', $t, $m)) return null;
  $n = $m[0];

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

  if (!is_numeric($n)) return null;
  return (float)$n;
}

/* Intent: cuota > margen > ahorro > general */
function detect_intent(string $q): string {
  $t = mb_strtolower(trim($q), 'UTF-8');

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

  if ($isCuota)  return "cuota";
  if ($isMargen) return "margen";
  if ($isAhorro) return "ahorro";
  return "general";
}

/* Extrae key/value de RESULTADO */
function result_key_value(string $resultLine): array {
  if (preg_match('/^RESULTADO:\s*([A-Za-z_]+)\s*=\s*(.+)$/u', trim($resultLine), $m)) {
    return ["key" => trim($m[1]), "value" => trim($m[2])];
  }
  return ["key" => "Conclusion", "value" => ""];
}

/* Limpieza: quita numeración y corta por líneas (pero más generoso) */
function clean_ai_text(string $txt, int $maxLines = 10): string {
  $txt = str_replace(["\r\n", "\r"], "\n", $txt);
  $txt = preg_replace('/^\s*\d+\)\s*/m', '', $txt);

  $lines = explode("\n", $txt);
  $out = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;

    $low = mb_strtolower($ln, 'UTF-8');
    if (strpos($low, "resultado:") !== false) continue;
    if (strpos($low, "contesto_calculado") !== false) continue;

    $out[] = $ln;
    if (count($out) >= $maxLines) break;
  }
  return implode("\n", $out);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userText = trim((string)$prompt);
  $metaTxt  = trim((string)$meta);

  /* 1) Intent */
  $intentType = detect_intent($userText . " " . $metaTxt);

  /* 2) PHP calcula */
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

  $precio = null;
  $cuotaMensual = null;

  $diff = null;

  if ($intentType === "ahorro") {
    $metaAmount = parseMoneyFromText($metaTxt);
    if ($metaAmount !== null && $plazoF !== null && $plazoF > 0) {
      $ahorroMensual = $metaAmount / $plazoF;
    }
    if ($margen !== null && $ahorroMensual !== null) {
      $diff = $margen - $ahorroMensual;
    }
  }

  if ($intentType === "cuota") {
    $precio = parseMoneyFromText($userText . " " . $metaTxt);
    if ($precio !== null && $plazoF !== null && $plazoF > 0) {
      $cuotaMensual = $precio / $plazoF;
    }
  }

  /* 3) Cálculos visibles */
  $calcLines = [];

  if (!empty($gMapNum)) {
    $calcLines[] = "Calculo: Total_gastos_fijos_EUR = " . fmt_eur_es($totalGastos) . "€";
  }

  if ($margen !== null) {
    $calcLines[] = "Calculo: Dinero_disponible_EUR = " . fmt_eur_es($ingF) . "€ - " . fmt_eur_es($totalGastos) . "€ = " . fmt_eur_es($margen) . "€";
  }

  /* RESULTADO final controlado por PHP */
  $resultLine = "";

  if ($intentType === "margen") {
    if ($margen !== null) {
      $resultLine = "RESULTADO: Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $resultLine = "RESULTADO: Conclusion=Para calcular lo que te queda necesito ingresos y gastos.";
    }
  } elseif ($intentType === "ahorro") {
    if ($ahorroMensual !== null) {
      $calcLines[] = "Calculo: Ahorro_mensual_EUR = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($ahorroMensual) . "€";

      if ($diff !== null) {
        $calcLines[] = "Calculo: Diferencia_EUR = " . fmt_eur_es($margen) . "€ - " . fmt_eur_es($ahorroMensual) . "€ = " . fmt_eur_es($diff) . "€";
      }

      $resultLine = "RESULTADO: Ahorro_mensual_EUR=" . fmt_eur_es($ahorroMensual) . "€";
    } else {
      $resultLine = "RESULTADO: Conclusion=Para calcular el ahorro mensual necesito una meta en € y un plazo en meses.";
    }
  } elseif ($intentType === "cuota") {
    if ($cuotaMensual !== null) {
      $calcLines[] = "Calculo: Cuota_mensual_EUR = " . fmt_eur_es($precio) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($cuotaMensual) . "€";
      $resultLine = "RESULTADO: Cuota_mensual_EUR=" . fmt_eur_es($cuotaMensual) . "€";
    } else {
      $resultLine = "RESULTADO: Conclusion=Para calcular cuota mensual necesito un precio en € y un plazo en meses.";
    }
  } else {
    if ($margen !== null) {
      $resultLine = "RESULTADO: Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $resultLine = "RESULTADO: Conclusion=Dime ingresos y gastos (o rellena el formulario) para poder calcular.";
    }
  }

  /* 4) Prompt afinado (IA comunica mejor + tips cortos) */

  $rv = result_key_value($resultLine);
  $resultKey   = $rv["key"];
  $resultValue = $rv["value"];

  $system =
    "Eres un consejero de finanzas personales BASICO para organización mensual.\n" .
    "Responde en español claro y práctico. No recomiendes inversiones complejas.\n" .
    "\n" .
    "FORMATO:\n" .
    "- 4 a 8 líneas en total.\n" .
    "- No uses listas numeradas.\n" .
    "- Máximo 2 tips.\n" .
    "- Cada tip debe ser una sola frase corta (máximo 120 caracteres) y debe empezar por 'Tip:'.\n" .
    "- No incluyas fórmulas ni cuentas dentro de los tips.\n" .
    "\n" .
    "REGLA PRINCIPAL:\n" .
    "- Si mencionas una cifra principal, usa RESULT_VALUE (es el resultado correcto calculado por PHP).\n" .
    "- No confundas Total_gastos_fijos_EUR con Dinero_disponible_EUR.\n" .
    "\n" .
    "CASOS:\n" .
    "- Si RESULT_KEY=Conclusion: haz 1 o 2 preguntas concretas y luego 1 tip.\n" .
    "- Si hay Diferencia_EUR:\n" .
    "  * si Diferencia_EUR es negativa, di que 'te faltan X€ al mes' para cumplir la meta.\n" .
    "  * si Diferencia_EUR es positiva, di que 'te sobran X€ al mes' para cumplir la meta.\n";

  $ctx  = "Intent: {$intentType}\n";
  $ctx .= "RESULTADO_FINAL: {$resultLine}\n";
  $ctx .= "RESULT_KEY={$resultKey}\n";
  $ctx .= "RESULT_VALUE={$resultValue}\n";

  if ($ingF !== null)            $ctx .= "Ingresos_mensuales_EUR=" . fmt_eur_es($ingF) . "€\n";
  if (!empty($gMapNum))          $ctx .= "Total_gastos_fijos_EUR=" . fmt_eur_es($totalGastos) . "€\n";
  if ($margen !== null)          $ctx .= "Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€\n";
  if ($ahorroMensual !== null)   $ctx .= "Ahorro_mensual_EUR=" . fmt_eur_es($ahorroMensual) . "€\n";
  if ($diff !== null)            $ctx .= "Diferencia_EUR=" . fmt_eur_es($diff) . "€\n";
  if ($cuotaMensual !== null)    $ctx .= "Cuota_mensual_EUR=" . fmt_eur_es($cuotaMensual) . "€\n";

  $finalPrompt = $system .
    "\nCONTEXTO_CALCULADO_POR_PHP:\n{$ctx}\n" .
    "PREGUNTA_DEL_USUARIO:\n" . ($userText !== "" ? $userText : $defaultPrompt);

  /* 5) Llamada a Ollama */
  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false,
    "options" => [
      /* Más espacio para terminar frases, pero sin respuestas largas */
      "num_predict" => 320,
      "temperature" => 0.25
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
      $ai = (string)($json["response"] ?? "");
      $ai = clean_ai_text($ai, 10);

      /* Si por cualquier razón el modelo se corta en una línea, evitamos terminar en guión o paréntesis abierto */
      $ai = preg_replace('/[\(\-\:]\s*$/u', '', $ai);

      $finalOut = $ai;

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
  </div>

  <div class="card">
    <form method="post">
      <p class="hint">
        Ejemplos:<br>
        - "Cuánto me queda para ocio con estos gastos?"<br>
        - "Quiero ahorrar 14000€ en 24 meses, cuánto debo apartar al mes?"<br>
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
          <input id="meta" name="meta" value="<?=h($meta)?>" placeholder="Ej: ahorrar 14000€ para un objetivo">
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
