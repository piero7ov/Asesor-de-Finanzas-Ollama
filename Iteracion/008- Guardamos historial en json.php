<?php
/* ==========================================================
   Iteración estética - Asesor Finanzas (PHP calcula + Ollama tips)
   ----------------------------------------------------------
   - PHP calcula resultados fiables (margen, ahorro/mes, cuota, tiempo)
   - Ollama devuelve exactamente 5 tips accionables (sin cálculos)
   - PHP compone salida final en Markdown:
       Resumen (PHP) + Tips (IA) + Calculos (PHP) + RESULTADO (PHP)
   - Render Markdown seguro en HTML (sin permitir HTML del usuario)
   ----------------------------------------------------------
   NUEVO (Iteración actual):
   - Historial en JSON: guarda cada consulta y su respuesta en:
       ./data/historial.json
   ----------------------------------------------------------
   Config:
   - Timeout cURL: 240s
   - max_execution_time: 300s
   ========================================================== */

ini_set('max_execution_time', '300');
set_time_limit(300);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$model   = "mistral:instruct";
$baseUrl = "http://127.0.0.1:11434";

/* ------------------------------
   Historial JSON (paths + helpers)
-------------------------------- */
$dataDir  = __DIR__ . DIRECTORY_SEPARATOR . "data";
$histPath = $dataDir . DIRECTORY_SEPARATOR . "historial.json";

/* Carga historial: devuelve array de entradas */
function history_load(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === "") return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

/* Guarda historial completo (sobrescribe) */
function history_save(string $dir, string $path, array $items): bool {
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) return false;
  }
  $raw = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($raw === false) return false;
  return @file_put_contents($path, $raw, LOCK_EX) !== false;
}

/* Añade una entrada al inicio y recorta a un máximo */
function history_add(string $dir, string $path, array $entry, int $max = 200): bool {
  $hist = history_load($path);
  array_unshift($hist, $entry);
  if (count($hist) > $max) $hist = array_slice($hist, 0, $max);
  return history_save($dir, $path, $hist);
}

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
   Markdown seguro (simple)
   - Escapa HTML siempre
   - Soporta: **negrita**, *cursiva*, `code`, headings (#) y listas (- )
-------------------------------- */
function md_inline_safe(string $s): string {
  // Inline code
  $s = preg_replace_callback('/`([^`]+)`/', function($m){
    return '<code>' . $m[1] . '</code>';
  }, $s);

  // Negrita
  $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);

  // Cursiva (simple)
  $s = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $s);

  return $s;
}

function render_markdown_safe(string $md): string {
  $md = str_replace(["\r\n", "\r"], "\n", $md);
  $lines = explode("\n", $md);

  $html = '';
  $inUl = false;

  foreach ($lines as $rawLine) {
    $line = rtrim($rawLine);

    // Escapa SIEMPRE HTML
    $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

    // Línea vacía
    if (trim($line) === "") {
      if ($inUl) {
        $html .= "</ul>";
        $inUl = false;
      }
      continue;
    }

    // Heading
    if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
      if ($inUl) {
        $html .= "</ul>";
        $inUl = false;
      }
      $level = strlen($m[1]);
      $tag = ($level === 1) ? "h3" : (($level === 2) ? "h4" : "h5");
      $html .= "<{$tag}>" . md_inline_safe($m[2]) . "</{$tag}>";
      continue;
    }

    // Lista
    if (preg_match('/^\-\s+(.+)$/', $line, $m)) {
      if (!$inUl) {
        $html .= "<ul>";
        $inUl = true;
      }
      $html .= "<li>" . md_inline_safe($m[1]) . "</li>";
      continue;
    }

    // Párrafo normal
    if ($inUl) {
      $html .= "</ul>";
      $inUl = false;
    }
    $html .= "<p>" . md_inline_safe($line) . "</p>";
  }

  if ($inUl) $html .= "</ul>";

  return $html;
}

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

/* Formato € estilo ES: coma decimal, quita ,00 */
function fmt_eur_es($n): string {
  $s = number_format((float)$n, 2, ',', '');
  $s = preg_replace('/,00$/', '', $s);
  return $s;
}

/* Pluralizador simple */
function plural_es(int $n, string $singular, string $plural): string {
  return ($n === 1) ? $singular : $plural;
}

/* parsea un número desde texto tipo "14.000€" "14000" "10,5" etc */
function parseMoneyFromText(string $txt): ?float {
  $t = trim($txt);
  if ($t === "") return null;

  $t = str_replace(["€", "EUR", "eur", " "], "", $t);

  if (!preg_match('/[-+]?\d[\d\.,]*/', $t, $m)) return null;
  $n = $m[0];

  // 14.000,50 => 14000.50
  if (strpos($n, '.') !== false && strpos($n, ',') !== false) {
    $n = str_replace('.', '', $n);
    $n = str_replace(',', '.', $n);
  } else {
    // 10,5 => 10.5
    if (strpos($n, ',') !== false && strpos($n, '.') === false) {
      $n = str_replace(',', '.', $n);
    }
    // 14.000 => 14000 (asume miles si el último bloque es de 3)
    if (strpos($n, '.') !== false && strpos($n, ',') === false) {
      $parts = explode('.', $n);
      $last = end($parts);
      if (strlen($last) === 3) $n = str_replace('.', '', $n);
    }
  }

  if (!is_numeric($n)) return null;
  return (float)$n;
}

/* ------------------------------
   Intent detection
-------------------------------- */
function detect_intent(string $q): array {
  $t = mb_strtolower(trim($q), 'UTF-8');

  $isTiempo =
    (strpos($t, "cuánto tiempo") !== false) ||
    (strpos($t, "cuanto tiempo") !== false) ||
    (strpos($t, "tardar") !== false) ||
    (strpos($t, "en cuanto") !== false);

  $isCuota =
    (strpos($t, "financ") !== false) ||
    (strpos($t, "cuota") !== false)  ||
    (strpos($t, "pagar") !== false)  ||
    (strpos($t, "mensualidad") !== false);

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

  if ($isTiempo) return ["type" => "tiempo"];
  if ($isCuota)  return ["type" => "cuota"];
  if ($isMargen) return ["type" => "margen"];
  if ($isAhorro) return ["type" => "ahorro"];
  return ["type" => "general"];
}

/* ------------------------------
   Limpieza tips IA (exactamente 5)
-------------------------------- */
function clean_ai_tips(string $txt): array {
  $txt = str_replace(["\r\n", "\r"], "\n", $txt);
  $lines = explode("\n", $txt);

  $tips = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;

    if (preg_match('/^tip\s*:/i', $ln)) {
      $ln = preg_replace('/^tip\s*:\s*/i', 'Tip: ', $ln);
      $tips[] = $ln;
    }
    if (count($tips) >= 5) break;
  }

  if (count($tips) < 5) {
    $plain = trim(preg_replace('/\s+/', ' ', $txt));
    $parts = preg_split('/\.\s+|\;\s+|\?\s+|\!\s+/', $plain);
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === "") continue;
      $tips[] = "Tip: " . $p;
      if (count($tips) >= 5) break;
    }
  }

  while (count($tips) < 5) {
    $tips[] = "Tip: Apunta tus gastos una semana para detectar fugas pequeñas y ajustar sin agobiarte.";
  }

  return array_slice($tips, 0, 5);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userText = trim((string)$prompt);
  $metaTxt  = trim((string)$meta);

  /* 1) Intent */
  $intentType = detect_intent($userText . " " . $metaTxt)["type"];

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

  $metaAmount = parseMoneyFromText($metaTxt);
  if ($metaAmount === null) $metaAmount = parseMoneyFromText($userText);

  $precio = parseMoneyFromText($userText);
  if ($precio === null) $precio = parseMoneyFromText($metaTxt);

  $ahorroMensual = null;
  if ($metaAmount !== null && $plazoF !== null && $plazoF > 0) {
    $ahorroMensual = $metaAmount / $plazoF;
  }

  $cuotaMensual = null;
  if ($precio !== null && $plazoF !== null && $plazoF > 0) {
    $cuotaMensual = $precio / $plazoF;
  }

  $diff = null;
  if ($margen !== null && $ahorroMensual !== null) {
    $diff = $margen - $ahorroMensual;
  }

  $mesesExactos = null;
  $mesesRedondeo = null;
  if ($intentType === "tiempo" && $metaAmount !== null && $margen !== null && $margen > 0) {
    $mesesExactos = $metaAmount / $margen;
    $mesesRedondeo = (int)ceil($mesesExactos);
  }

  /* 3) Líneas calculadas por PHP */
  $calcLines = [];

  if (!empty($gMapNum)) {
    $calcLines[] = "Calculo: Total_gastos_fijos_EUR = " . fmt_eur_es($totalGastos) . "€";
  }

  if ($margen !== null) {
    $calcLines[] = "Calculo: Dinero_disponible_EUR = " . fmt_eur_es($ingF) . "€ - " . fmt_eur_es($totalGastos) . "€ = " . fmt_eur_es($margen) . "€";
  }

  $summaryLine = "Resumen: ";
  $resultLine  = "RESULTADO: ";

  if ($intentType === "margen") {
    if ($margen !== null) {
      $summaryLine .= "Te quedan " . fmt_eur_es($margen) . "€ al mes después de tus gastos fijos.";
      $resultLine  .= "Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine .= "No puedo calcular lo que te queda con los datos actuales.";
      $resultLine  .= "Conclusion=Faltan ingresos y/o gastos para calcular lo que te queda.";
    }
  } elseif ($intentType === "ahorro") {
    if ($ahorroMensual !== null) {
      $calcLines[] = "Calculo: Ahorro_mensual_EUR = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($ahorroMensual) . "€";

      if ($diff !== null) {
        $calcLines[] = "Calculo: Diferencia_EUR = " . fmt_eur_es($margen) . "€ - " . fmt_eur_es($ahorroMensual) . "€ = " . fmt_eur_es($diff) . "€";
        $summaryLine .= "Para tu objetivo necesitas ahorrar " . fmt_eur_es($ahorroMensual) . "€ al mes. ";
        $summaryLine .= ($diff >= 0)
          ? "Con tus datos, te sobran " . fmt_eur_es($diff) . "€ al mes."
          : "Con tus datos, te faltan " . fmt_eur_es(abs($diff)) . "€ al mes.";
      } else {
        $summaryLine .= "Para tu objetivo necesitas ahorrar " . fmt_eur_es($ahorroMensual) . "€ al mes.";
      }

      $resultLine .= "Ahorro_mensual_EUR=" . fmt_eur_es($ahorroMensual) . "€";
    } else {
      $summaryLine .= "Para calcular cuánto ahorrar al mes necesito una meta en € y un plazo en meses.";
      $resultLine  .= "Conclusion=Para calcular el ahorro mensual necesito una meta en € y un plazo en meses.";
    }
  } elseif ($intentType === "cuota") {
    if ($cuotaMensual !== null) {
      $calcLines[] = "Calculo: Cuota_mensual_EUR = " . fmt_eur_es($precio) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($cuotaMensual) . "€";
      $summaryLine .= "La cuota básica sería " . fmt_eur_es($cuotaMensual) . "€ al mes (sin intereses).";
      $resultLine  .= "Cuota_mensual_EUR=" . fmt_eur_es($cuotaMensual) . "€";
    } else {
      $summaryLine .= "Para calcular la cuota mensual necesito el precio en € y el plazo en meses.";
      $resultLine  .= "Conclusion=Para calcular cuota mensual necesito el precio en € y el plazo en meses.";
    }
  } elseif ($intentType === "tiempo") {
    if ($mesesRedondeo !== null) {
      $calcLines[] = "Calculo: Meses_necesarios = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($margen) . "€ = " . fmt_eur_es($mesesExactos) . " meses";
      $calcLines[] = "Calculo: Meses_redondeados = " . fmt_eur_es($mesesRedondeo) . " " . plural_es($mesesRedondeo, "mes", "meses");
      $summaryLine .= "Con tu dinero disponible actual, tardarías aproximadamente " . fmt_eur_es($mesesRedondeo) . " " . plural_es($mesesRedondeo, "mes", "meses") . ".";
      $resultLine  .= "Tiempo_meses=" . fmt_eur_es($mesesRedondeo);
    } else {
      $summaryLine .= "Para estimar el tiempo necesito una meta en € y tu dinero disponible mensual (ingresos y gastos).";
      $resultLine  .= "Conclusion=Para calcular el tiempo necesito una meta en € y tu dinero disponible mensual.";
    }
  } else {
    if ($margen !== null) {
      $summaryLine .= "Con tus datos, tu dinero disponible aproximado es " . fmt_eur_es($margen) . "€ al mes.";
      $resultLine  .= "Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine .= "Dime ingresos y gastos (o rellena el formulario) y te ayudo con un cálculo simple.";
      $resultLine  .= "Conclusion=Dime ingresos y gastos (o rellena el formulario) y te ayudo.";
    }
  }

  /* 4) Prompt a IA: solo 5 tips situacionales (sin cálculos ni €) */
  $system =
    "Eres un consejero de finanzas personales BASICO.\n".
    "Tu tarea es dar consejos prácticos a corto plazo para organizarse.\n".
    "No recomiendes inversiones complejas ni productos financieros avanzados.\n".
    "Devuelve EXACTAMENTE 5 líneas.\n".
    "Cada línea debe empezar por 'Tip: ' y ser un consejo accionable.\n".
    "No incluyas cálculos, no incluyas cantidades, no incluyas euros, no incluyas números.\n".
    "Adapta los tips a la situación del usuario (por ejemplo: pareja, estudiante, compra concreta, ocio, ahorro, cuota).\n".
    "Evita tips obvios tipo 'resta ingresos menos gastos'.\n".
    "Prioriza tips que ayuden a ejecutar: hábitos, límites, reglas simples, orden de pagos, sobres/cuentas, prevención de imprevistos.\n".
    "No uses listas numeradas ni viñetas; solo las 5 líneas Tip.\n\n";

  $ctx = "Intent=" . $intentType . "\n";
  if ($ingF !== null) $ctx .= "Ingresos_mensuales_EUR=SI\n";
  if (!empty($gMapNum)) $ctx .= "Gastos_fijos=SI\n";
  if ($margen !== null) $ctx .= "Tiene_dinero_disponible=SI\n";
  if ($ahorroMensual !== null) $ctx .= "Tiene_objetivo_ahorro=SI\n";
  if ($cuotaMensual !== null) $ctx .= "Consulta_cuota=SI\n";
  if ($mesesRedondeo !== null) $ctx .= "Consulta_tiempo=SI\n";

  $finalPrompt = $system
    . "CONTEXTO (banderas):\n{$ctx}\n"
    . "PREGUNTA_DEL_USUARIO:\n" . ($userText !== "" ? $userText : $defaultPrompt) . "\n"
    . "NOTA: Si hay formulario, los cálculos ya están hechos por PHP. Da tips de hábitos y organización, no de matemáticas.\n";

  /* 5) Llamada a Ollama */
  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false,
    "options" => [
      "num_predict" => 320,
      "temperature" => 0.35
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
    $result = ["ok" => false, "err" => "cURL error: " . $curlErr, "out_html" => ""];
  } elseif ($httpCode < 200 || $httpCode >= 300) {
    $result = ["ok" => false, "err" => "HTTP $httpCode\n$raw", "out_html" => ""];
  } else {
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      $result = ["ok" => false, "err" => "Respuesta no-JSON:\n$raw", "out_html" => ""];
    } else {
      $ai = (string)($json["response"] ?? "");
      $tips = clean_ai_tips($ai);

      // Composición Markdown
      $summaryText = preg_replace('/^Resumen:\s*/', '', $summaryLine);

      $md = "";
      $md .= "**Resumen:** " . $summaryText . "\n\n";

      $md .= "**Tips**\n";
      foreach ($tips as $t) {
        $tClean = preg_replace('/^Tip:\s*/', '', $t);
        $md .= "- " . $tClean . "\n";
      }

      if (!empty($calcLines)) {
        $md .= "\n**Cálculos**\n";
        foreach ($calcLines as $c) {
          $md .= "- " . $c . "\n";
        }
      }

      // Última línea exacta
      $md .= "\n" . $resultLine;

      // Render seguro a HTML
      $htmlOut = '<div class="md">' . render_markdown_safe($md) . '</div>';

      $result = ["ok" => true, "err" => "", "out_html" => $htmlOut];

      /* ------------------------------
         NUEVO: Guardar historial JSON
         - Guarda el markdown (no el HTML renderizado)
         - Guarda también inputs y tipo de intent
      -------------------------------- */
      $entry = [
        "id" => uniqid("q_", true),
        "created_at" => date("c"),
        "intent" => $intentType,
        "question" => $userText,
        "form" => [
          "ingresos" => $ingresos,
          "g_alquiler" => $g_alquiler,
          "g_luz" => $g_luz,
          "g_agua" => $g_agua,
          "g_internet" => $g_internet,
          "g_comida" => $g_comida,
          "g_transporte" => $g_transporte,
          "g_otros" => $g_otros,
          "meta" => $meta,
          "plazoMeses" => $plazoMeses,
        ],
        "markdown" => $md,
      ];

      // Si falla por permisos, no rompe la app
      history_add($dataDir, $histPath, $entry, 200);
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
      --grad-main: linear-gradient(135deg, #0ea5e9, #f97316);
      --grad-head: linear-gradient(135deg, #1e3a8a, #0ea5e9);

      --bg: #f6f7fb;
      --card: #ffffff;
      --border: #e6e8f0;
      --text: #0f172a;
      --muted: #475569;

      --radius: 16px;
      --shadow: 0 14px 40px rgba(2, 6, 23, .10);
      --shadow2: 0 10px 26px rgba(2, 6, 23, .08);
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background:
        radial-gradient(800px 500px at 10% 5%, rgba(14,165,233,.18), transparent 60%),
        radial-gradient(800px 500px at 90% 10%, rgba(249,115,22,.14), transparent 60%),
        radial-gradient(800px 500px at 60% 110%, rgba(30,58,138,.10), transparent 60%),
        var(--bg);
      color: var(--text);
      padding: 22px 16px;
    }

    .wrap{
      max-width: 1100px;
      margin: 0 auto;
      display:flex;
      flex-direction:column;
      gap: 14px;
    }

    .topbar{
      border-radius: var(--radius);
      background: var(--grad-head);
      box-shadow: var(--shadow);
      overflow:hidden;
      border: 1px solid rgba(255,255,255,.25);
    }
    .topbar-inner{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 14px;
      padding: 14px 16px;
      color: #fff;
    }
    .brand{
      display:flex;
      align-items:center;
      gap: 12px;
      min-width:0;
    }
    .brand img{
      height: 42px;
      width: auto;
      display:block;
      filter: drop-shadow(0 10px 18px rgba(0,0,0,.22));
    }
    .brand .title{
      display:flex;
      flex-direction:column;
      gap: 2px;
      min-width:0;
    }
    .brand h1{
      margin:0;
      font-size: 18px;
      letter-spacing:.2px;
      line-height: 1.15;
      white-space: nowrap;
      overflow:hidden;
      text-overflow: ellipsis;
    }
    .brand p{
      margin:0;
      font-size: 13px;
      opacity: .92;
      line-height: 1.25;
      white-space: nowrap;
      overflow:hidden;
      text-overflow: ellipsis;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
      overflow:hidden;
    }

    .cardHead{
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(14,165,233,.08), rgba(249,115,22,.05));
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
    }
    .cardHead h2{
      margin:0;
      font-size: 14px;
      letter-spacing:.2px;
      color: #0f172a;
    }

    .content{ padding: 16px; }

    .hint{
      margin: 0 0 12px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.55;
    }

    label{
      display:block;
      font-size: 12px;
      font-weight: 800;
      color: #334155;
      margin: 12px 0 6px;
      letter-spacing:.2px;
    }

    input, textarea{
      width:100%;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      outline:none;
      font: inherit;
      transition: border-color .15s ease, box-shadow .15s ease, transform .06s ease;
    }

    textarea{
      min-height: 130px;
      resize: vertical;
    }

    input::placeholder, textarea::placeholder{ color: rgba(15,23,42,.35); }

    input:focus, textarea:focus{
      border-color: rgba(14,165,233,.55);
      box-shadow: 0 0 0 4px rgba(14,165,233,.14);
    }

    .sep{
      margin: 14px 0;
      border-top: 1px dashed rgba(71,85,105,.25);
    }

    .grid2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .grid3{
      display:grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 12px;
    }

    .actions{
      display:flex;
      gap: 10px;
      margin-top: 14px;
      align-items:center;
    }

    button{
      border: 0;
      background: var(--grad-main);
      color: #0b1220;
      padding: 11px 14px;
      border-radius: 12px;
      cursor:pointer;
      font-weight: 900;
      letter-spacing:.2px;
      box-shadow: 0 12px 26px rgba(14,165,233,.22);
      transition: transform .06s ease, filter .15s ease;
    }
    button:hover{ filter: brightness(1.02); }
    button:active{ transform: translateY(1px); }

    .note{
      padding: 12px 16px;
      color: var(--muted);
      font-size: 12px;
      border-top: 1px solid var(--border);
      background: rgba(2, 6, 23, .02);
    }

    .err{
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #7f1d1d;
      padding: 12px 14px;
      border-radius: 14px;
      white-space: pre-wrap;
    }

    /* Render Markdown */
    .md{
      background: #ffffff;
      border: 1px solid rgba(2,6,23,.10);
      border-radius: 14px;
      padding: 14px;
      line-height: 1.65;
    }
    .md p{ margin: 0 0 10px; color: var(--text); }
    .md ul{ margin: 0 0 12px 18px; padding:0; }
    .md li{ margin: 6px 0; color: var(--text); }
    .md code{
      font-family: var(--mono);
      background: rgba(2,6,23,.06);
      border: 1px solid rgba(2,6,23,.08);
      padding: 1px 6px;
      border-radius: 8px;
    }
    .md h3,.md h4,.md h5{
      margin: 0 0 10px;
      font-size: 14px;
      color: var(--text);
    }

    @media (max-width: 900px){
      .grid2,.grid3{ grid-template-columns: 1fr; }
      body{ padding: 18px 12px; }
      .brand img{ height: 36px; }
    }
  </style>
</head>
<body>

<div class="wrap">

  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <img
          src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png"
          alt="PIERODEV"
          loading="eager"
        >
        <div class="title">
          <h1>Asesor de Finanzas</h1>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="cardHead"><h2>Consulta</h2></div>
    <div class="content">
      <form method="post">
        <p class="hint">
          Ejemplos:<br>
          - "Cuánto me queda para ocio con estos gastos?"<br>
          - "Quiero ahorrar 14000€ en 24 meses, cuánto debo apartar al mes?"<br>
          - "Cuánto tiempo tardaré en ahorrar 1300€?"<br>
          - "Quiero pagar algo de 10000€ en 36 meses, cuánto es al mes?" (cuota básica: precio/meses)
        </p>

        <label for="prompt">Tu pregunta (texto libre)</label>
        <textarea id="prompt" name="prompt" placeholder="Escribe tu pregunta..."><?=h($prompt)?></textarea>

        <div class="sep"></div>

        <p class="hint" style="margin-top:0;">
          Formulario (opcional): rellena lo que tengas. Si no sabes algo, déjalo en blanco.
        </p>

        <div class="grid2">
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

        <div class="grid2" style="margin-top:12px;">
          <div>
            <label for="meta">Meta / objetivo (opcional)</label>
            <input id="meta" name="meta" value="<?=h($meta)?>" placeholder="Ej: ahorrar 400€ para una compra">
          </div>
          <div>
            <label for="plazoMeses">Plazo en meses (opcional)</label>
            <input id="plazoMeses" name="plazoMeses" value="<?=h($plazoMeses)?>" placeholder="Ej: 24">
          </div>
        </div>

        <div class="actions">
          <button type="submit">Preguntar</button>
        </div>
      </form>
    </div>
    <div class="note">Si no responde, verifica que Ollama esté ejecutándose en tu PC.</div>
  </div>

  <?php if ($result !== null): ?>
    <div class="card">
      <div class="cardHead"><h2>Respuesta</h2></div>
      <div class="content">
        <?php if (!$result["ok"]): ?>
          <div class="err"><?=nl2br(h($result["err"]))?></div>
        <?php else: ?>
          <?= $result["out_html"] ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

</body>
</html>
