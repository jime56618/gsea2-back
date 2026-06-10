<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesWorkspace;

/**
 * Controlador base API: workspace activo + permisos por promotoría.
 */
abstract class BaseApiController extends Controller
{
    use ResolvesWorkspace;
}
