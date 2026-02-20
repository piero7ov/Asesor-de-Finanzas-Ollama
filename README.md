# Asesor-de-Finanzas-Ollama

Mini proyecto en **PHP + Ollama (local)** para hacer de **asesor básico de finanzas personales** orientado a gente que se independiza (parejas, amigos, estudiantes, etc.).

La idea central es separar responsabilidades:

- **PHP calcula** (fiable): margen mensual, ahorro/mes, cuota/mes y tiempo estimado para alcanzar una meta.
- **Ollama explica** (valor “asesor”): devuelve **5 tips accionables** adaptados a la situación, **sin cálculos**.

Aviso: proyecto educativo. No es asesoría financiera profesional.

---

## Funcionalidades

- Pregunta en **texto libre** (por ejemplo: “¿Cuánto me queda para ocio?” / “¿Cuánto debo ahorrar al mes?”).
- **Formulario opcional** para meter ingresos, gastos fijos y meta/plazo.
- Detección de intención por palabras clave:
  - `margen` (me queda/ocio/sobra/disponible)
  - `ahorro` (ahorrar/meta/objetivo/reunir/juntar)
  - `cuota` (financiar/cuota/pagar/mensualidad)
  - `tiempo` (cuánto tiempo/tardar/en cuánto)
- Salida en **Markdown** y renderizado a **HTML seguro** (sin permitir HTML del modelo/usuario).
- **Autorrellenar** con la última consulta guardada (botón).
- Guardado de historial mínimo en JSON para soporte de autorellenado.
- Medición de rendimiento: muestra “Última consulta tardó X segundos”.
- Timeouts configurados para evitar cortes por respuestas lentas.

---

## Estructura del proyecto (según el ZIP)

```

Asesor-de-Finanzas-Ollama/
├─ index.php
├─ estilo.css
└─ data/
└─ historial.json

````

- `index.php`: lógica completa (cálculos, prompt a Ollama, render Markdown seguro, guardado JSON, UI).
- `estilo.css`: estilos de la interfaz.
- `data/historial.json`: historial (se mantiene acotado por cantidad).

---

## Requisitos

- Windows con **XAMPP** (Apache + PHP).
- PHP con extensiones:
  - `curl` (obligatoria)
  - `mbstring` (recomendada, se usa en detección de intención)
- **Ollama** instalado y ejecutándose en local.
- Modelo (por defecto en el código): `mistral:instruct`

---

## Instalación y ejecución

1) Instalar Ollama y descargar el modelo:
```bash
ollama pull mistral:instruct
````

2. Levantar Ollama (si no está ya activo):

```bash
ollama serve
```

O simplemente abrir Ollama si lo manejas como servicio/app (según tu instalación).

3. Copiar el proyecto dentro de `htdocs`, por ejemplo:

```
C:\xampp\htdocs\Asesor-de-Finanzas-Ollama
```

4. Iniciar Apache desde el panel de XAMPP.

5. Abrir en el navegador:

```
http://localhost/Asesor-de-Finanzas-Ollama/
```

Notas de permisos:

* El archivo `data/historial.json` debe poder escribirse. En Windows normalmente basta con que exista y no esté bloqueado por permisos.

---

## Cómo se calcula (PHP)

El sistema intenta hacer cálculos simples y consistentes:

* Total gastos fijos:

  * `total_gastos = suma(gastos ingresados)`
* Margen mensual disponible:

  * `margen = ingresos - total_gastos`
* Ahorro mensual necesario (si hay meta y plazo):

  * `ahorro_mes = meta / meses`
* Diferencia de viabilidad (si hay margen y ahorro):

  * `diferencia = margen - ahorro_mes`
* Cuota mensual simple (sin intereses):

  * `cuota_mes = precio / meses`
* Tiempo para alcanzar una meta (si hay margen > 0):

  * `meses = ceil(meta / margen)`

El “precio” y la “meta” pueden venir del campo Meta o del texto libre (se intenta parsear cantidades tipo `14.000€`, `14000`, `10,5`, etc.).

---

## Qué le pide a Ollama

Ollama NO calcula. Solo devuelve exactamente 5 líneas:

* Cada línea empieza con: `Tip: `
* Consejos accionables, de organización y hábitos.
* Sin números, sin euros y sin “resta ingresos menos gastos”.

Luego PHP compone la respuesta final en Markdown:

* Resumen (PHP)
* Tips (IA)
* Cálculos (PHP)
* RESULTADO final (PHP)

---

## Markdown seguro (anti XSS)

El render de Markdown es deliberadamente simple y seguro:

* Escapa HTML siempre.
* Soporta lo mínimo:

  * Negrita: `**texto**`
  * Cursiva: `*texto*`
  * Código inline: `` `code` ``
  * Headings (`#`, `##`, `###`) mapeados a `h3/h4/h5`
  * Listas con `- item`

No se permite HTML ni del usuario ni del modelo.

---

## Configuración rápida

Dentro de `index.php` (al inicio) están los valores:

* Modelo:

  * `$model = "mistral:instruct";`
* URL Ollama:

  * `$baseUrl = "http://127.0.0.1:11434";`
* Timeouts:

  * `CURLOPT_TIMEOUT => 240`
  * `max_execution_time = 300`

Si cambias de modelo, asegúrate de que esté instalado en Ollama.

---

## Historial (JSON)

Se guarda un historial acotado en:

* `data/historial.json`

Se utiliza principalmente para:

* Botón “Autorrellenar (última)” (rellena el formulario con la última consulta guardada).

El límite máximo de entradas está controlado por código (por defecto, 50).

---

## Uso recomendado (ejemplos)

* Margen/ocio:

  * “¿Cuánto me queda para ocio?”
* Ahorro mensual:

  * “Quiero ahorrar 10000€ en 24 meses, ¿cuánto debo apartar?”
* Cuota:

  * “Quiero pagar 12000€ en 36 meses, ¿cuánto es al mes?”
* Tiempo:

  * “¿Cuánto tiempo tardaré en ahorrar 1300€?”

Puedes usar solo texto libre, solo formulario, o ambos.

---

## Autor

Desarrollado por Piero Olivares (PieroDev).

