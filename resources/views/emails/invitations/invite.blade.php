<x-mail::message>
# 👋 Has sido invitado a GSEA CRM

Te invitaron a formar parte del equipo de **{{ $invitation->workspace->nombre }}**.

### Rol asignado
**{{ $invitation->role->nombre }}**

Accede mediante el siguiente botón:

<x-mail::button :url="$inviteUrl">
Aceptar invitación
</x-mail::button>

@if($invitation->expires_at)
Este enlace estará disponible hasta el **{{ \Carbon\Carbon::parse($invitation->expires_at)->format('d/m/Y') }}**.
@endif

Si no reconoces esta invitación, puedes ignorar este correo.

Gracias,<br>
**Equipo GSEA CRM**
</x-mail::message>