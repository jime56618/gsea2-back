/**
 * Ejemplo de rutas para tu proyecto React (react-router-dom v6).
 * Copia a src/App.jsx o src/routes/index.jsx y ajusta imports de páginas.
 *
 * Requiere: npm install react-router-dom
 * Env: VITE_API_URL=http://localhost:8000/api
 */
import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ProtectedRoute from './ProtectedRoute';

import Landing from './Landing';
import AuthGSEA from './AuthGSEA';
import BillingLocked from './BillingLocked';
import AcceptInvitation from './AcceptInvitation';

import Dashboard from './Dashbord';
import Clientes from './Clientes.integracion';
import SeguimientoPolizas from './SeguimientoPolizas.integracion';
import SeguimientodeCobranza from './SeguimientodeCobranza';
import Calendario from './Calendario';
import Tramites from './Tramites.integracion';
import Agentes from './Agentes';
import Capacitacion from './Capacitacion';

import TeamSettings from './TeamSettings';
import RolesEditor from './RolesEditor';
import BillingSettings from './BillingSettings';

function AppWithAuth({ children }) {
  return (
    <AuthProvider>
      <ProtectedRoute>{children}</ProtectedRoute>
    </AuthProvider>
  );
}

export default function AppRoutesExample() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Landing />} />
        <Route path="/register" element={<AuthGSEA />} />
        <Route path="/invitacion/:token" element={<AcceptInvitation />} />

        <Route
          path="/billing-locked"
          element={
            <AuthProvider>
              <BillingLocked />
            </AuthProvider>
          }
        />

        <Route
          path="/dashboard"
          element={
            <AppWithAuth>
              <Dashboard />
            </AppWithAuth>
          }
        />
        <Route path="/clientes" element={<AppWithAuth><Clientes /></AppWithAuth>} />
        <Route path="/seguimiento-polizas" element={<AppWithAuth><SeguimientoPolizas /></AppWithAuth>} />
        <Route path="/seguimiento-cobranza" element={<AppWithAuth><SeguimientodeCobranza /></AppWithAuth>} />
        <Route path="/calendario" element={<AppWithAuth><Calendario /></AppWithAuth>} />
        <Route path="/tramites" element={<AppWithAuth><Tramites /></AppWithAuth>} />
        <Route path="/agentes" element={<AppWithAuth><Agentes /></AppWithAuth>} />
        <Route path="/capacitacion" element={<AppWithAuth><Capacitacion /></AppWithAuth>} />

        <Route path="/configuracion/equipo" element={<AppWithAuth><TeamSettings /></AppWithAuth>} />
        <Route path="/configuracion/roles" element={<AppWithAuth><RolesEditor /></AppWithAuth>} />
        <Route path="/configuracion/facturacion" element={<AppWithAuth><BillingSettings /></AppWithAuth>} />

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
