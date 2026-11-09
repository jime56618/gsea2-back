<?php

namespace App\Support;

/**
 * Catálogo estable de permisos (no se crean en runtime desde el CRUD).
 * El editor de roles solo asigna/desasigna estos nombres.
 */
final class WorkspacePermissions
{
    // --- Workspace / equipo ---
    public const WORKSPACES_MANAGE = 'workspaces.manage';

    public const USERS_INVITE = 'users.invite';

    public const USERS_MANAGE = 'users.manage';

    // --- Roles y permisos (editor) ---
    public const ROLES_MANAGE = 'roles.manage';

    // --- Facturación ---
    public const BILLING_MANAGE = 'billing.manage';

    // --- Catálogos globales del tenant ---
    public const CONTRATANTES_VIEW = 'contratantes.view';

    public const CONTRATANTES_MANAGE = 'contratantes.manage';

    public const AGENTES_VIEW = 'agentes.view';

    public const AGENTES_MANAGE = 'agentes.manage';

    public const POLIZAS_VIEW = 'polizas.view';

    public const POLIZAS_MANAGE = 'polizas.manage';

    public const COBRANZA_VIEW = 'cobranza.view';

    public const COBRANZA_MANAGE = 'cobranza.manage';

    public const TRAMITES_VIEW = 'tramites.view';

    public const TRAMITES_MANAGE = 'tramites.manage';

    public const DASHBOARD_VIEW = 'dashboard.view';
    public const METAS_CREATE = 'metas_comerciales.create';
    public const METAS_UPDATE = 'metas_comerciales.update';

    public const CALENDAR_VIEW = 'calendar.view';

    /**
     * @return array<string, array{group: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            self::WORKSPACES_MANAGE => ['group' => 'workspace', 'description' => 'Administrar datos de la promotoría'],
            self::USERS_INVITE => ['group' => 'users', 'description' => 'Invitar usuarios al workspace'],
            self::USERS_MANAGE => ['group' => 'users', 'description' => 'Gestionar miembros del workspace'],
            self::ROLES_MANAGE => ['group' => 'roles', 'description' => 'Crear roles y editar permisos'],
            self::BILLING_MANAGE => ['group' => 'billing', 'description' => 'Ver y activar suscripción / pago'],
            self::CONTRATANTES_VIEW => ['group' => 'contratantes', 'description' => 'Ver contratantes'],
            self::CONTRATANTES_MANAGE => ['group' => 'contratantes', 'description' => 'Crear y editar contratantes'],
            self::AGENTES_VIEW => ['group' => 'agentes', 'description' => 'Ver agentes de la promotoría'],
            self::AGENTES_MANAGE => ['group' => 'agentes', 'description' => 'Gestionar agentes de la promotoría'],
            self::POLIZAS_VIEW => ['group' => 'polizas', 'description' => 'Ver pólizas'],
            self::POLIZAS_MANAGE => ['group' => 'polizas', 'description' => 'Crear y editar pólizas'],
            self::COBRANZA_VIEW => ['group' => 'cobranza', 'description' => 'Ver cobranza'],
            self::COBRANZA_MANAGE => ['group' => 'cobranza', 'description' => 'Gestionar cobranza'],
            self::TRAMITES_VIEW => ['group' => 'tramites', 'description' => 'Ver trámites'],
            self::TRAMITES_MANAGE => ['group' => 'tramites', 'description' => 'Gestionar trámites'],
            self::DASHBOARD_VIEW => ['group' => 'dashboard', 'description' => 'Ver dashboard'],
            self::METAS_CREATE => ['group' => 'dashboard', 'description' => 'Crear metas comerciales (administradores)'],
            self::METAS_UPDATE => ['group' => 'dashboard', 'description' => 'Editar metas comerciales (administradores)'],
            self::CALENDAR_VIEW => ['group' => 'calendar', 'description' => 'Ver calendario de cobranza'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }
}
