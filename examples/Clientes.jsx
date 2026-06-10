/**
 * Cartera de Clientes = contratantes en BD (misma entidad).
 * Backend usa workspace activo (current_workspace_id); no envíes workspace_id salvo que quieras validar coincidencia.
 *
 * Si ves "El workspace enviado no coincide...", actualiza backend (fix query workspace_id=0) o quita workspace_id del front.
 */

import React, { useState, useMemo, useEffect, useCallback } from 'react';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import { motion, AnimatePresence } from 'framer-motion';
import {
  FiPlus,
  FiX,
  FiSearch,
  FiMail,
  FiPhone,
  FiEdit2,
  FiChevronLeft,
  FiChevronRight,
  FiBriefcase,
} from 'react-icons/fi';
import './css/Clientes.css';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

function authHeaders() {
  const token = localStorage.getItem('token');
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

function mapContratanteFromApi(row) {
  return {
    id: row.id,
    nombre: row.nombre,
    rfc: row.rfc || '',
    correo: row.email || '',
    telefono: row.telefono || '',
    direccion: row.direccion || '',
    polizas_count: row.polizas_count ?? 0,
    fechaAlta: row.created_at?.slice(0, 10) || '',
    folioCliente: `CLI-${row.id}`,
    estatus: 'Activo',
  };
}

export default function Clientes() {
  const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);
  const [clients, setClients] = useState([]);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState('');
  const [workspaceLabel, setWorkspaceLabel] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;
  const [modalState, setModalState] = useState({ type: null, data: null });

  const loadMe = useCallback(async () => {
    try {
      const res = await fetch(`${API_URL}/user`, { headers: authHeaders() });
      const json = await res.json();
      if (!res.ok) return;
      const name = json.current_workspace?.nombre || json.workspaces?.[0]?.nombre || '';
      setWorkspaceLabel(name);
      if (json.current_workspace_id) {
        localStorage.setItem('current_workspace_id', String(json.current_workspace_id));
      }
    } catch {
      /* ignore */
    }
  }, []);

  const loadContratantes = useCallback(async () => {
    setLoading(true);
    setListError('');
    try {
      const res = await fetch(`${API_URL}/contratantes?per_page=100`, { headers: authHeaders() });
      const json = await res.json();
      if (!res.ok) {
        setListError(json.message || 'No se pudieron cargar los clientes');
        setClients([]);
        return;
      }
      const rows = json.data || [];
      setClients(rows.map(mapContratanteFromApi));
    } catch (e) {
      setListError('Error de red al cargar clientes');
      setClients([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadMe();
    loadContratantes();
  }, [loadMe, loadContratantes]);

  const openModal = (type, data = null) => setModalState({ type, data });
  const closeModal = () => setModalState({ type: null, data: null });

  const filteredClients = useMemo(() => {
    const t = searchTerm.toLowerCase();
    return clients.filter((c) => {
      if (!t) return true;
      return (
        c.nombre.toLowerCase().includes(t) ||
        (c.rfc && c.rfc.toLowerCase().includes(t)) ||
        (c.correo && c.correo.toLowerCase().includes(t)) ||
        (c.telefono && c.telefono.includes(t))
      );
    });
  }, [clients, searchTerm]);

  const totalPages = Math.max(1, Math.ceil(filteredClients.length / itemsPerPage));
  const currentItems = useMemo(() => {
    const start = (currentPage - 1) * itemsPerPage;
    return filteredClients.slice(start, start + itemsPerPage);
  }, [filteredClients, currentPage]);

  return (
    <div className="clientes-container">
      <Sidebar onExpand={setIsSidebarExpanded} />
      <div
        className={`clientes-main-content ${
          isSidebarExpanded ? 'clientes-sidebar-expanded' : 'clientes-sidebar-collapsed'
        }`}
      >
        <Navbar />
        <main className="clientes-main">
          <div className="clientes-page-header">
            <div>
              <h1 className="clientes-page-title">Cartera de Clientes</h1>
              <p className="clientes-page-subtitle">      
                {workspaceLabel && (
                  <span className="clientes-workspace-badge" style={{ marginLeft: '0.5rem', fontWeight: 600 }}>
                    <FiBriefcase style={{ verticalAlign: 'middle', marginRight: 4 }} />
                    {workspaceLabel}
                  </span>
                )}
              </p>
            </div>
            <motion.button
              type="button"
              whileHover={{ scale: 1.02 }}
              whileTap={{ scale: 0.98 }}
              className="clientes-btn-agregar"
              onClick={() => openModal('agregar')}
            >
              <FiPlus /> <span>Nuevo Cliente</span>
            </motion.button>
          </div>

          {listError && (
            <p className="gsea-auth-error" style={{ margin: '0 0 1rem' }}>
              {listError}
            </p>
          )}
          {loading && <p>Cargando…</p>}

          <div className="clientes-filters-section">
            <div className="clientes-search-wrapper">
              <FiSearch className="clientes-search-icon" />
              <input
                type="text"
                placeholder="Buscar por nombre, RFC, email o teléfono..."
                className="clientes-search-input"
                onChange={(e) => {
                  setSearchTerm(e.target.value);
                  setCurrentPage(1);
                }}
              />
            </div>
          </div>

          <div className="clientes-table-container">
            <div className="clientes-table-responsive">
              <table className="clientes-table">
                <thead>
                  <tr>
                    <th>Cliente</th>
                    <th>RFC</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Pólizas</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {currentItems.map((client, index) => (
                    <motion.tr
                      key={client.id}
                      initial={{ opacity: 0, y: 12 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ delay: index * 0.04 }}
                      className="clientes-table-row"
                    >
                      <td>
                        <div className="clientes-client-cell">
                          <div className="clientes-client-avatar">{client.nombre.charAt(0)}</div>
                          <div className="clientes-client-info">
                            <span className="clientes-client-fullname">{client.nombre}</span>
                            <span className="clientes-client-folio">{client.folioCliente}</span>
                          </div>
                        </div>
                      </td>
                      <td className="clientes-rfc-cell">{client.rfc}</td>
                      <td className="clientes-email-cell">
                        <div className="clientes-email-wrapper">
                          <FiMail className="clientes-email-icon" />
                          <span>{client.correo}</span>
                        </div>
                      </td>
                      <td className="clientes-phone-cell">
                        <div className="clientes-phone-wrapper">
                          <FiPhone className="clientes-phone-icon" />
                          <span>{client.telefono}</span>
                        </div>
                      </td>
                      <td>{client.polizas_count}</td>
                      <td className="clientes-actions-cell">
                        <motion.button
                          type="button"
                          whileHover={{ scale: 1.08 }}
                          whileTap={{ scale: 0.95 }}
                          className="clientes-action-btn clientes-edit-btn"
                          onClick={() => openModal('editar', client)}
                          title="Editar"
                        >
                          <FiEdit2 />
                        </motion.button>
                      </td>
                    </motion.tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="clientes-pagination-wrapper">
              <div className="clientes-pagination-info">
                Mostrando <span className="clientes-pagination-highlight">{currentItems.length}</span> de{' '}
                <span className="clientes-pagination-highlight">{filteredClients.length}</span> registros
              </div>
              <div className="clientes-pagination-controls">
                <div className="clientes-pagination-buttons">
                  <motion.button
                    type="button"
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                    disabled={currentPage === 1}
                    onClick={() => setCurrentPage((p) => p - 1)}
                    className="clientes-pagination-prev"
                  >
                    <FiChevronLeft />
                  </motion.button>
                  <motion.button
                    type="button"
                    whileHover={{ scale: 1.05 }}
                    whileTap={{ scale: 0.95 }}
                    disabled={currentPage >= totalPages}
                    onClick={() => setCurrentPage((p) => p + 1)}
                    className="clientes-pagination-next"
                  >
                    <FiChevronRight />
                  </motion.button>
                </div>
              </div>
            </div>
          </div>
        </main>
      </div>

      <AnimatePresence>
        {modalState.type && (
          <div className="clientes-modal-overlay">
            {modalState.type === 'agregar' && (
              <FormularioCliente
                title="Nuevo Cliente"
                onClose={closeModal}
                onSaved={() => {
                  closeModal();
                  loadContratantes();
                }}
              />
            )}
            {modalState.type === 'editar' && modalState.data && (
              <FormularioCliente
                title="Editar Cliente"
                initial={modalState.data}
                onClose={closeModal}
                onSaved={() => {
                  closeModal();
                  loadContratantes();
                }}
              />
            )}
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}

function FormularioCliente({ title, onClose, onSaved, initial }) {
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState('');
  const [form, setForm] = useState(() => ({
    nombre: initial?.nombre || '',
    rfc: initial?.rfc || '',
    correo: initial?.correo || '',
    telefono: initial?.telefono || '',
    direccion: initial?.direccion || '',
  }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErr('');
    setSaving(true);
    try {
      const body = {
        nombre: form.nombre.trim(),
        rfc: form.rfc.trim() || null,
        email: form.correo.trim() || null,
        telefono: form.telefono.trim() || null,
        direccion: form.direccion.trim() || null,
      };

      const isEdit = Boolean(initial?.id);
      const url = isEdit ? `${API_URL}/contratantes/${initial.id}` : `${API_URL}/contratantes`;
      const res = await fetch(url, {
        method: isEdit ? 'PUT' : 'POST',
        headers: authHeaders(),
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) {
        const msg =
          data.message ||
          (data.errors && Object.values(data.errors).flat().join(' ')) ||
          'Error al guardar';
        setErr(msg);
        return;
      }
      onSaved();
    } catch (ex) {
      setErr('Error de red');
    } finally {
      setSaving(false);
    }
  };

  return (
    <motion.div
      initial={{ scale: 0.92, opacity: 0, y: 16 }}
      animate={{ scale: 1, opacity: 1, y: 0 }}
      exit={{ scale: 0.92, opacity: 0, y: 16 }}
      className="clientes-modal-panel clientes-modal-form-panel"
    >
      <div className="clientes-modal-header">
        <h2 className="clientes-modal-title">{title}</h2>
        <button type="button" onClick={onClose} className="clientes-modal-close-btn">
          <FiX />
        </button>
      </div>

      <form className="clientes-modal-form-content" onSubmit={handleSubmit}>
        {err && <p className="gsea-auth-error">{err}</p>}

        <div className="clientes-form-section">
          <h3 className="clientes-form-section-title">Datos del contratante</h3>
          <p className="clientes-page-subtitle" style={{ marginTop: 0 }}>
            En base de datos la tabla es <strong>contratantes</strong> (es tu “cliente”).
          </p>
          <div className="clientes-form-grid">
            <div className="clientes-form-group">
              <label>Nombre o razón social *</label>
              <input
                type="text"
                value={form.nombre}
                onChange={(e) => setForm({ ...form, nombre: e.target.value })}
                placeholder="Ej: Juan Pérez García"
                required
              />
            </div>
            <div className="clientes-form-group">
              <label>RFC</label>
              <input
                type="text"
                value={form.rfc}
                onChange={(e) => setForm({ ...form, rfc: e.target.value })}
                placeholder="XXXX000101XXX"
              />
            </div>
            <div className="clientes-form-group">
              <label>Email</label>
              <input
                type="email"
                value={form.correo}
                onChange={(e) => setForm({ ...form, correo: e.target.value })}
                placeholder="cliente@email.com"
              />
            </div>
            <div className="clientes-form-group">
              <label>Teléfono</label>
              <input
                type="text"
                value={form.telefono}
                onChange={(e) => setForm({ ...form, telefono: e.target.value })}
                placeholder="999 000 0000"
              />
            </div>
            <div className="clientes-form-group clientes-full-width">
              <label>Dirección</label>
              <input
                type="text"
                value={form.direccion}
                onChange={(e) => setForm({ ...form, direccion: e.target.value })}
                placeholder="Calle, número, colonia, ciudad"
              />
            </div>
          </div>
        </div>

        <div className="clientes-form-actions">
          <motion.button
            type="button"
            whileHover={{ scale: 1.02 }}
            whileTap={{ scale: 0.98 }}
            onClick={onClose}
            className="clientes-btn-cancel"
          >
            Cancelar
          </motion.button>
          <motion.button
            type="submit"
            disabled={saving}
            whileHover={{ scale: 1.02 }}
            whileTap={{ scale: 0.98 }}
            className="clientes-btn-save"
          >
            {saving ? 'Guardando…' : 'Guardar Cliente'}
          </motion.button>
        </div>
      </form>
    </motion.div>
  );
}
