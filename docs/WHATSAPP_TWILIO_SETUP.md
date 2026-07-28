# WhatsApp recordatorios — Twilio Sandbox (G-SEA)

Recordatorios automáticos de cobranza al crear pólizas. Usa **Twilio Sandbox** (gratis para demo).

## Lo que hace G-SEA

1. Al **crear una póliza** → genera cuotas + programa recordatorios (7, 3, 1 y 0 días antes del pago).
2. Envía **mensaje de alta** inmediato (si Twilio está configurado).
3. Cada día a las **9:00** (`whatsapp:send-due-reminders`) manda los que tocan.
4. Si marcas una cuota como **pagada** → cancela recordatorios pendientes.

---

## TÚ debes hacer esto en Twilio (15 min)

### 1. Crear cuenta Twilio

1. [https://www.twilio.com/try-twilio](https://www.twilio.com/try-twilio)
2. Verifica correo y teléfono.

### 2. Activar WhatsApp Sandbox

1. Consola → **Messaging** → **Try it out** → **Send a WhatsApp message**
2. Copia el número sandbox (ej. `+1 415 523 8886`)
3. Copia el código `join xxx-xxxx`
4. Desde **tu WhatsApp personal**, envía ese mensaje al número sandbox
5. Debes recibir confirmación de Twilio

> **Importante:** Cada celular que reciba mensajes debe hacer el `join` primero (tú y quien vea la demo).

### 3. Copiar credenciales

En Twilio Console → **Account Info**:

| Variable | Dónde |
|----------|--------|
| `TWILIO_ACCOUNT_SID` | Account SID |
| `TWILIO_AUTH_TOKEN` | Auth Token (Show) |
| `TWILIO_WHATSAPP_FROM` | `whatsapp:+14155238886` (el número sandbox, con prefijo whatsapp:) |

---

## Configurar G-SEA

### 1. Migración

```bash
php artisan migrate
```

### 2. Variables en `.env`

```env
WHATSAPP_ENABLED=true
WHATSAPP_REMINDER_OFFSETS=7,3,1,0
WHATSAPP_SEND_ALTA_ON_POLIZA=true
WHATSAPP_TIMEZONE=America/Mexico_City

TWILIO_ACCOUNT_SID=ACxxxxxxxx
TWILIO_AUTH_TOKEN=xxxxxxxx
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
```

### 3. Prueba rápida (sin póliza)

```bash
php artisan whatsapp:send-test 9991234567 "Hola prueba G-SEA"
```

Usa tu celular de 10 dígitos (debe haber hecho `join` al sandbox).

### 4. Probar recordatorios programados

```bash
php artisan whatsapp:send-due-reminders
```

O forzar una fecha:

```bash
php artisan whatsapp:send-due-reminders --date=2026-07-15
```

---

## Crear póliza con teléfono

Al crear la póliza, pon teléfono en:

- `telefono_notificacion` de la póliza, **o**
- `telefono` del contratante

Frecuencias soportadas: `unico`, `mensual`, `trimestral`, `semestral`, `anual`.

---

## En Render / producción

1. Agrega las variables `WHATSAPP_*` y `TWILIO_*` en el dashboard de Render.
2. El scheduler de Laravel debe correr; si no tienes cron, ejecuta manualmente o usa un cron job:

```bash
php artisan schedule:run
```

Cada minuto en cron, o al menos diario a las 9am.

---

## Mensajes de ejemplo

**Alta:**
> ¡Hola, Juan! 👋 Tu póliza AXA-123 ya quedó registrada en G-SEA...

**7 días antes:**
> Te recordamos que tu póliza AXA-123 tiene un pago programado para el 15/08/2026...

---

## Limitaciones Sandbox

- Solo números que enviaron `join xxx` al sandbox.
- No es número real de empresa (eso es paso 2 con Twilio producción).
- Twilio trial tiene ~100 mensajes WhatsApp gratis.

---

## Tabla `whatsapp_notifications`

Historial de envíos: pendiente, enviado, fallido, cancelado.

Si falla, revisa `storage/logs/laravel.log` y columna `error_detalle`.
