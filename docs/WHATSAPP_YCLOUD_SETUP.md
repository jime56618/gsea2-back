# WhatsApp con YCloud + Meta (G-SEA)

Guía rápida para producción. El código ya soporta **YCloud** (default) y Twilio como respaldo.

## 1. Lo que TÚ haces en YCloud / Meta

### Cuenta YCloud
1. Entra a [https://www.ycloud.com/](https://www.ycloud.com/) y abre sesión (si dice account not available, contacta soporte).
2. Conecta tu **WhatsApp Business** / número.
3. Ve a **Developers → API Keys** y copia la API Key.

### Plantillas en Meta (vía YCloud → Message Templates)
Crea 3 plantillas **Utility** en español (`es_MX`). Los nombres deben coincidir con el `.env`:

#### A) `g_pruebas` (alta de póliza)
Texto sugerido (debe tener exactamente 5 variables en este orden):

```text
¡Hola, {{1}}!
Este es tu primer recordatorio de tu póliza {{3}} con {{2}}.
Recuerda que tu próximo pago es el {{4}} por {{5}}.
Te enviaremos avisos antes de cada fecha de pago.
```

Variables que manda G-SEA: `{{1}}=nombre`, `{{2}}=empresa`, `{{3}}=numero_poliza`, `{{4}}=fecha`, `{{5}}=monto`

#### B) `gsea_recordatorio_pago` (7 / 3 / 1 días antes)
```text
¡Hola, {{1}}!
Te recordamos que tu póliza {{2}} tiene un pago programado para el {{3}} por {{4}}.
Empresa: {{5}}.
Si ya pagaste, ignora este mensaje.
```

#### C) `gsea_dia_pago`
```text
¡Hola, {{1}}!
Hoy es la fecha de pago de tu póliza {{2}}.
Monto: {{4}} · Fecha: {{3}}.
{{5}}
```

Espera a que Meta las deje en estado **Approved**.

---

## 2. Variables en Render / `.env`

```env
WHATSAPP_ENABLED=true
WHATSAPP_PROVIDER=ycloud
WHATSAPP_SEND_ALTA_ON_POLIZA=true
WHATSAPP_REMINDER_OFFSETS=7,3,1,0
WHATSAPP_TIMEZONE=America/Mexico_City

YCLOUD_API_KEY=tu_api_key
YCLOUD_FROM=+521XXXXXXXXXX
YCLOUD_TEMPLATE_LANG=es_MX
YCLOUD_TEMPLATE_ALTA=g_pruebas
YCLOUD_TEMPLATE_RECORDATORIO=gsea_recordatorio_pago
YCLOUD_TEMPLATE_DIA_PAGO=gsea_dia_pago
```

`YCLOUD_FROM` = tu número WhatsApp Business en formato E.164 (`+521...`).

---

## 3. Migración y prueba

```bash
php artisan migrate
php artisan whatsapp:send-test 9991234567 "Prueba G-SEA YCloud"
```

Si usas plantilla de test:

```env
YCLOUD_TEMPLATE_TEST=hello_world
```

---

## 4. Cómo funciona en G-SEA

1. Creas **póliza** con frecuencia + monto + teléfono (póliza o contratante).
2. Se generan cuotas y recordatorios.
3. Se envía al instante el **primer recordatorio** (plantilla `alta`).
4. Diario a las 9:00: `whatsapp:send-due-reminders`.

Comandos:

| Comando | Uso |
|---------|-----|
| `whatsapp:send-test {tel}` | Prueba manual |
| `whatsapp:send-due-reminders` | Envía los programados de hoy |
| `whatsapp:send-due-reminders --date=2026-08-01` | Fuerza una fecha |

---

## 5. Desbloquear trial (producción)

Si ves “Tu periodo de prueba terminó”:

**Opción A — botón** “Activar plan (demo)” (ahora muestra errores si falla).

**Opción B — servidor:**

```bash
php artisan billing:activate
# o un workspace:
php artisan billing:activate 1
```

---

## Docs YCloud

- Envío: https://docs.ycloud.com/reference/whatsapp-message-sending-guide  
- Endpoint: `POST https://api.ycloud.com/v2/whatsapp/messages`  
- Header: `X-API-Key: ...`
