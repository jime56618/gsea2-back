/**
 * Seguimiento de Pólizas — integración con Laravel API.
 *
 * Ajusta imports a tu estructura, ej:
 *   import Sidebar from '../components/Sidebar';
 *   import Navbar from '../components/Navbar';
 *   import './css/SeguimientoPolizas.css';
 *
 * Requiere: token Sanctum, workspace activo (current_workspace_id).
 *
 * Backend POST /api/polizas exige: contratante_id, aseguradora_id, ramo_id, subramo_id,
 * numero_poliza, inicio_vigencia, fin_vigencia; opcional agente_id (id de agente_workspaces),
 * fecha_emision, primas, vehiculo, cobranza (frecuencia_cobro: unico|mensual|trimestral,
 * monto_cuota, telefono_notificacion). Las cuotas se generan en cobranza_cuotas al guardar.
 * Calendario: GET /api/calendar/cobranza-cuotas?year=2026&month=4 (o from, to).
 */

import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import {
  Plus,
  FileText,
  Edit2,
  Trash2,
  Search,
  UploadCloud,
  X,
  ChevronLeft,
  ChevronRight,
  AlertCircle,
  Filter,
  MoreVertical,
  Eye,
  Download,
  Copy,
} from 'lucide-react';

import Sidebar from './Sidebar';
import Navbar from './Navbar';
import './css/SeguimientoPolizas.css';

import { apiFetch, fetchJson } from './utils/apiClient';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

/** Vigencia mostrada = fin de vigencia (como tu mock). Estatus derivado de fechas (no hay campo en BD). */
function mapPolizaFromApi(p) {
  const fin = p.fin_vigencia?.slice(0, 10);
  const inicio = p.inicio_vigencia?.slice(0, 10);
  const estatus = computeEstatus(inicio, fin);
  return {
    id: p.id,
    raw: p,
    numero: p.numero_poliza,
    aseguradora: p.aseguradora?.nombre || '—',
    contratante: p.contratante?.nombre || '—',
    vigencia: fin,
    inicio_vigencia: inicio,
    fin_vigencia: fin,
    estatus,
    aseguradora_id: p.aseguradora_id,
    contratante_id: p.contratante_id,
    agente_id: p.agente_id,
    ramo_id: p.ramo_id,
    subramo_id: p.subramo_id,
  };
}

function computeEstatus(inicioStr, finStr) {
  if (!finStr || !inicioStr) return 'No Vigente';
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const fin = new Date(finStr);
  const inicio = new Date(inicioStr);
  fin.setHours(0, 0, 0, 0);
  inicio.setHours(0, 0, 0, 0);
  if (now >= inicio && now <= fin) return 'Vigente';
  return 'No Vigente';
}

const MENU_W = 180;
const MENU_H = 140;
const MENU_GAP = 8;
const SAFE_PADDING = 12;

const emptyForm = () => ({
  contratante_id: '',
  aseguradora_id: '',
  ramo_id: '',
  subramo_id: '',
  agente_id: '',
  numero_poliza: '',
  fecha_emision: '',
  inicio_vigencia: '',
  fin_vigencia: '',
  prima_neta: '',
  iva: '',
  prima_total: '',
  moneda: 'MXN',
  frecuencia_cobro: 'unico',
  monto_cuota: '',
  telefono_notificacion: '',
});

const SeguimientoPolizas = () => {
  const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);
  const [polizas, setPolizas] = useState([]);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [filterEstatus, setFilterEstatus] = useState('Todos');
  const [modalType, setModalType] = useState(null);
  const [selectedPoliza, setSelectedPoliza] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);

  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
  const [activeMenuId, setActiveMenuId] = useState(null);
  const menuRef = useRef(null);

  const [catalogos, setCatalogos] = useState({
    contratantes: [],
    aseguradoras: [],
    ramos: [],
    subramos: [],
    agentesWorkspace: [],
  });

  const itemsPerPage = 5;

  const loadCatalogos = useCallback(async () => {
    const [contratantes, aseguradoras, ramos, subramos, agentesWorkspace] = await Promise.all([
      fetchJson(`${API_URL}/contratantes?per_page=500`).then((j) => j.data || []),
      fetchJson(`${API_URL}/aseguradoras?per_page=200`).then((j) => j.data || []),
      fetchJson(`${API_URL}/ramos?per_page=200`).then((j) => j.data || []),
      fetchJson(`${API_URL}/subramos?per_page=500`).then((j) => j.data || []),
      fetchJson(`${API_URL}/agentes_workspace?per_page=500`).then((j) => j.data || []),
    ]);
    setCatalogos({ contratantes, aseguradoras, ramos, subramos, agentesWorkspace });
  }, []);

  const loadPolizas = useCallback(async () => {
    setLoading(true);
    setListError('');
    try {
      const json = await fetchJson(`${API_URL}/polizas?per_page=200`);
      const rows = json.data || [];
      setPolizas(rows.map(mapPolizaFromApi));
    } catch (e) {
      setListError(e.message || 'Error al cargar pólizas');
      setPolizas([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCatalogos().catch(() => {});
  }, [loadCatalogos]);

  useEffect(() => {
    loadPolizas();
  }, [loadPolizas]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (menuRef.current && !menuRef.current.contains(event.target)) {
        setActiveMenuId(null);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filteredPolizas = useMemo(() => {
    return polizas.filter((p) => {
      const matchesSearch =
        !searchTerm ||
        p.numero.toLowerCase().includes(searchTerm.toLowerCase()) ||
        p.contratante.toLowerCase().includes(searchTerm.toLowerCase()) ||
        p.aseguradora.toLowerCase().includes(searchTerm.toLowerCase());
      const matchesFilter = filterEstatus === 'Todos' || p.estatus === filterEstatus;
      return matchesSearch && matchesFilter;
    });
  }, [polizas, searchTerm, filterEstatus]);

  const totalPages = Math.ceil(filteredPolizas.length / itemsPerPage) || 1;

  const paginatedPolizas = useMemo(() => {
    const start = (currentPage - 1) * itemsPerPage;
    return filteredPolizas.slice(start, start + itemsPerPage);
  }, [filteredPolizas, currentPage]);

  useEffect(() => {
    setCurrentPage((prev) => Math.min(prev, totalPages));
  }, [totalPages]);

  const getStatusConfig = (estatus) => {
    return estatus === 'Vigente'
      ? {
          class: 'polizas-status-vigente',
          dot: 'polizas-dot-vigente',
          label: 'Vigente',
        }
      : {
          class: 'polizas-status-no-vigente',
          dot: 'polizas-dot-no-vigente',
          label: 'No Vigente',
        };
  };

  const handleMenuClick = (e, polizaId) => {
    e.stopPropagation();
    const rect = e.currentTarget.getBoundingClientRect();
    setMenuPosition({
      top: rect.bottom + MENU_GAP,
      left: rect.right - MENU_W,
    });
    setActiveMenuId((prev) => (prev === polizaId ? null : polizaId));
  };

  const handleDeleteConfirm = async () => {
    if (!selectedPoliza?.id) return;
    try {
      await apiFetch(`/polizas/${selectedPoliza.id}`, { method: 'DELETE' });
      setModalType(null);
      setSelectedPoliza(null);
      setActiveMenuId(null);
      loadPolizas();
    } catch (e) {
      alert(e.message || 'No se pudo eliminar');
    }
  };

  return (
    <div className="polizas-container">
      <Sidebar onExpand={setIsSidebarExpanded} />

      <div
        className={`polizas-main-content ${
          isSidebarExpanded ? 'polizas-sidebar-expanded' : 'polizas-sidebar-collapsed'
        }`}
      >
        <Navbar />

        <main className="polizas-main">
          <div className="polizas-header">
            <div className="polizas-header-left">
              <h1 className="polizas-title">Seguimiento de Pólizas</h1>
              <p className="polizas-subtitle">Datos desde el API (workspace activo)</p>
            </div>

            <div className="polizas-header-actions">
              <button
                type="button"
                onClick={() => {
                  setSelectedPoliza(null);
                  setModalType('add');
                }}
                className="polizas-btn-add"
              >
                <Plus size={18} />
                <span>Agregar Póliza</span>
              </button>
              <button type="button" onClick={() => setModalType('upload')} className="polizas-btn-upload">
                <UploadCloud size={18} />
                <span>Subir Documento</span>
              </button>
            </div>
          </div>

          {listError && (
            <p className="gsea-auth-error" style={{ margin: '0 0 1rem' }}>
              {listError}
            </p>
          )}
          {loading && <p style={{ marginBottom: '1rem' }}>Cargando pólizas…</p>}

          <div className="polizas-search-section">
            <div className="polizas-search-wrapper">
              <Search className="polizas-search-icon" size={18} />
              <input
                type="text"
                placeholder="Buscar por número, contratante o aseguradora..."
                className="polizas-search-input"
                value={searchTerm}
                onChange={(e) => {
                  setSearchTerm(e.target.value);
                  setCurrentPage(1);
                }}
              />
            </div>

            <div className="polizas-filter-wrapper">
              <label className="polizas-filter-label">
                <Filter size={16} />
                <span>Filtrar por:</span>
              </label>
              <select
                className="polizas-filter-select-visible"
                onChange={(e) => {
                  setFilterEstatus(e.target.value);
                  setCurrentPage(1);
                }}
                value={filterEstatus}
              >
                <option value="Todos">Todos los estados</option>
                <option value="Vigente">Vigente</option>
                <option value="No Vigente">No Vigente</option>
              </select>
            </div>
          </div>

          <div className="polizas-table-container">
            <div className="polizas-table-wrapper">
              <table className="polizas-table">
                <thead>
                  <tr>
                    <th>Número de Póliza</th>
                    <th>Aseguradora</th>
                    <th>Contratante</th>
                    <th className="text-center">Archivo</th>
                    <th>Vigencia (fin)</th>
                    <th>Estatus</th>
                    <th className="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedPolizas.map((poliza, index) => {
                    const statusConfig = getStatusConfig(poliza.estatus);
                    return (
                      <tr
                        key={poliza.id}
                        className="polizas-row"
                        style={{ animationDelay: `${index * 0.05}s` }}
                      >
                        <td className="polizas-cell polizas-cell-numero">
                          <span className="polizas-badge">{poliza.numero}</span>
                        </td>
                        <td className="polizas-cell polizas-cell-aseguradora">
                          <span className="polizas-aseguradora">{poliza.aseguradora}</span>
                        </td>
                        <td className="polizas-cell polizas-cell-contratante">
                          <span className="polizas-contratante">{poliza.contratante}</span>
                        </td>
                        <td className="polizas-cell polizas-cell-file text-center">
                          <button type="button" className="polizas-file-btn" title="Documentos (API: poliza_documentos)">
                            <FileText size={16} />
                          </button>
                        </td>
                        <td className="polizas-cell polizas-cell-vigencia">
                          <div className="polizas-vigencia">
                            <span>
                              {poliza.vigencia
                                ? new Date(poliza.vigencia).toLocaleDateString('es-MX', {
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit',
                                  })
                                : '—'}
                            </span>
                          </div>
                        </td>
                        <td className="polizas-cell polizas-cell-status">
                          <div className={`polizas-status ${statusConfig.class}`}>
                            <span className={`polizas-status-dot ${statusConfig.dot}`} />
                            <span className="polizas-status-label">{statusConfig.label}</span>
                          </div>
                        </td>
                        <td className="polizas-cell polizas-cell-actions">
                          <div className="polizas-actions">
                            <button
                              type="button"
                              onClick={() => {
                                setSelectedPoliza(poliza);
                                setModalType('edit');
                              }}
                              className="polizas-action polizas-action-edit"
                              title="Editar"
                            >
                              <Edit2 size={14} />
                            </button>
                            <button
                              type="button"
                              onClick={() => {
                                setSelectedPoliza(poliza);
                                setModalType('delete');
                              }}
                              className="polizas-action polizas-action-delete"
                              title="Eliminar"
                            >
                              <Trash2 size={14} />
                            </button>
                            <button
                              type="button"
                              className="polizas-menu-button"
                              onClick={(e) => handleMenuClick(e, poliza.id)}
                              title="Más opciones"
                            >
                              <MoreVertical size={14} />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="polizas-pagination polizas-pagination-new">
              <div className="polizas-pagination-info">
                Mostrando <span className="polizas-pagination-highlight">{paginatedPolizas.length}</span> de{' '}
                <span className="polizas-pagination-highlight">{filteredPolizas.length}</span> registros
              </div>

              <div className="polizas-pagination-right polizas-pagination-right-inline">
                <button
                  type="button"
                  className="polizas-pagination-arrowSolo"
                  onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
                  disabled={currentPage === 1}
                  aria-label="Página anterior"
                  title="Anterior"
                >
                  <ChevronLeft size={16} />
                </button>

                <span className="polizas-pagination-pageLabel polizas-pagination-pageLabel-inline">
                  Página <strong>{currentPage}</strong> de <strong>{totalPages}</strong>
                </span>

                <button
                  type="button"
                  className="polizas-pagination-arrowSolo"
                  onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
                  disabled={currentPage === totalPages || filteredPolizas.length === 0}
                  aria-label="Página siguiente"
                  title="Siguiente"
                >
                  <ChevronRight size={16} />
                </button>
              </div>
            </div>
          </div>
        </main>
      </div>

      {activeMenuId &&
        createPortal(
          <FloatingMenu
            refEl={menuRef}
            top={menuPosition.top}
            left={menuPosition.left}
            onClose={() => setActiveMenuId(null)}
          />,
          document.body
        )}

      {modalType && (
        <ModalManager
          type={modalType}
          data={selectedPoliza}
          catalogos={catalogos}
          onClose={() => {
            setModalType(null);
            setSelectedPoliza(null);
          }}
          onSaved={() => {
            setModalType(null);
            setSelectedPoliza(null);
            loadPolizas();
          }}
          onDeleteConfirm={handleDeleteConfirm}
        />
      )}
    </div>
  );
};

const FloatingMenu = ({ refEl, top, left, onClose }) => {
  const maxLeft = window.innerWidth - MENU_W - SAFE_PADDING;
  const maxTop = window.innerHeight - MENU_H - SAFE_PADDING;
  const safeLeft = Math.max(SAFE_PADDING, Math.min(left, maxLeft));
  const safeTop = Math.max(SAFE_PADDING, Math.min(top, maxTop));

  return (
    <div
      ref={refEl}
      className="polizas-floating-menu polizas-floating-menu-portal"
      style={{
        position: 'fixed',
        top: safeTop,
        left: safeLeft,
        zIndex: 99999,
      }}
      onClick={(e) => e.stopPropagation()}
    >
      <div className="polizas-floating-menu-items">
        <button type="button" className="polizas-floating-menu-item" onClick={onClose}>
          <Eye size={14} /> Ver detalles
        </button>
        <button type="button" className="polizas-floating-menu-item" onClick={onClose}>
          <Download size={14} /> Descargar
        </button>
        <button type="button" className="polizas-floating-menu-item" onClick={onClose}>
          <Copy size={14} /> Duplicar
        </button>
      </div>
    </div>
  );
};

function agentesFiltradosPorAseguradora(agentesWorkspace, aseguradoraId) {
  if (!aseguradoraId) return agentesWorkspace;
  const aid = Number(aseguradoraId);
  return agentesWorkspace.filter((aw) =>
    (aw.claves_aseguradora || []).some((c) => Number(c.aseguradora_id) === aid)
  );
}

const ModalManager = ({ type, data, catalogos, onClose, onSaved, onDeleteConfirm }) => {
  const isEdit = type === 'edit' && data?.raw;
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState('');
  const [formData, setFormData] = useState(() => emptyForm());

  useEffect(() => {
    if (type === 'edit' && data?.raw) {
      const p = data.raw;
      setFormData({
        contratante_id: String(p.contratante_id ?? ''),
        aseguradora_id: String(p.aseguradora_id ?? ''),
        ramo_id: String(p.ramo_id ?? ''),
        subramo_id: String(p.subramo_id ?? ''),
        agente_id: p.agente_id ? String(p.agente_id) : '',
        numero_poliza: p.numero_poliza || '',
        fecha_emision: p.fecha_emision?.slice(0, 10) || '',
        inicio_vigencia: p.inicio_vigencia?.slice(0, 10) || '',
        fin_vigencia: p.fin_vigencia?.slice(0, 10) || '',
        prima_neta: p.prima_neta != null ? String(p.prima_neta) : '',
        iva: p.iva != null ? String(p.iva) : '',
        prima_total: p.prima_total != null ? String(p.prima_total) : '',
        moneda: p.moneda || 'MXN',
        frecuencia_cobro: p.frecuencia_cobro || 'unico',
        monto_cuota: p.monto_cuota != null ? String(p.monto_cuota) : '',
        telefono_notificacion: p.telefono_notificacion || '',
      });
    } else if (type === 'add') {
      setFormData(emptyForm());
    }
    setErr('');
  }, [type, data]);

  const subramosFiltrados = useMemo(() => {
    if (!formData.ramo_id) return catalogos.subramos;
    return catalogos.subramos.filter((s) => String(s.ramo_id) === String(formData.ramo_id));
  }, [catalogos.subramos, formData.ramo_id]);

  const agentesOpciones = useMemo(
    () => agentesFiltradosPorAseguradora(catalogos.agentesWorkspace, formData.aseguradora_id),
    [catalogos.agentesWorkspace, formData.aseguradora_id]
  );

  const buildPayload = () => {
    const payload = {
      contratante_id: Number(formData.contratante_id),
      aseguradora_id: Number(formData.aseguradora_id),
      ramo_id: Number(formData.ramo_id),
      subramo_id: Number(formData.subramo_id),
      numero_poliza: formData.numero_poliza.trim(),
      inicio_vigencia: formData.inicio_vigencia,
      fin_vigencia: formData.fin_vigencia,
      moneda: formData.moneda || 'MXN',
    };
    if (formData.fecha_emision) payload.fecha_emision = formData.fecha_emision;
    if (formData.agente_id) payload.agente_id = Number(formData.agente_id);
    if (formData.prima_neta !== '') payload.prima_neta = Number(formData.prima_neta);
    if (formData.iva !== '') payload.iva = Number(formData.iva);
    if (formData.prima_total !== '') payload.prima_total = Number(formData.prima_total);
    if (formData.frecuencia_cobro) payload.frecuencia_cobro = formData.frecuencia_cobro;
    if (formData.monto_cuota !== '') payload.monto_cuota = Number(formData.monto_cuota);
    if (formData.telefono_notificacion.trim())
      payload.telefono_notificacion = formData.telefono_notificacion.trim();
    return payload;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErr('');
    setSaving(true);
    try {
      const payload = buildPayload();
      if (type === 'add') {
        await fetchJson(`${API_URL}/polizas`, {
          method: 'POST',
          body: JSON.stringify(payload),
        });
      } else if (type === 'edit' && data?.id) {
        await fetchJson(`${API_URL}/polizas/${data.id}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        });
      }
      onSaved();
    } catch (ex) {
      setErr(ex.message || 'Error al guardar');
    } finally {
      setSaving(false);
    }
  };

  const getModalTitle = () => {
    switch (type) {
      case 'add':
        return 'Nueva Póliza';
      case 'edit':
        return 'Editar Póliza';
      case 'delete':
        return 'Confirmar Eliminación';
      case 'upload':
        return 'Subir Documento';
      default:
        return '';
    }
  };

  return (
    <div className="polizas-modal-overlay">
      <div className={`polizas-modal ${type === 'delete' ? 'polizas-modal-delete' : ''}`}>
        <div className="polizas-modal-header">
          <h3 className="polizas-modal-title">{getModalTitle()}</h3>
          <button type="button" onClick={onClose} className="polizas-modal-close">
            <X size={20} />
          </button>
        </div>

        <div className="polizas-modal-content">
          {type === 'delete' ? (
            <div className="polizas-delete-content">
              <div className="polizas-delete-warning">
                <AlertCircle size={40} />
              </div>
              <p className="polizas-delete-text">
                ¿Eliminar la póliza <strong>{data?.numero}</strong>?
              </p>
              <p className="polizas-delete-subtext">Esta acción no se puede deshacer.</p>
              <div className="polizas-delete-actions">
                <button type="button" onClick={onDeleteConfirm} className="polizas-btn-delete-confirm">
                  Eliminar
                </button>
                <button type="button" onClick={onClose} className="polizas-btn-delete-cancel">
                  Cancelar
                </button>
              </div>
            </div>
          ) : type === 'upload' ? (
            <div className="polizas-upload-content">
              <div className="polizas-upload-area">
                <UploadCloud size={40} className="polizas-upload-icon" />
                <p className="polizas-upload-text">Subida de documentos: integrar con POST /api/poliza_documentos</p>
                <input type="file" accept=".pdf" className="polizas-upload-input" disabled />
              </div>
              <button type="button" onClick={onClose} className="polizas-btn-upload-confirm">
                Cerrar
              </button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="polizas-form">
              {err && <p className="gsea-auth-error">{err}</p>}

              <div className="polizas-form-group">
                <label>Contratante *</label>
                <select
                  required
                  value={formData.contratante_id}
                  onChange={(e) => setFormData({ ...formData, contratante_id: e.target.value })}
                >
                  <option value="">Selecciona…</option>
                  {catalogos.contratantes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div className="polizas-form-group">
                <label>Aseguradora *</label>
                <select
                  required
                  value={formData.aseguradora_id}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      aseguradora_id: e.target.value,
                      agente_id: '',
                    })
                  }
                >
                  <option value="">Selecciona…</option>
                  {catalogos.aseguradoras.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div className="polizas-form-row">
                <div className="polizas-form-group">
                  <label>Ramo *</label>
                  <select
                    required
                    value={formData.ramo_id}
                    onChange={(e) =>
                      setFormData({
                        ...formData,
                        ramo_id: e.target.value,
                        subramo_id: '',
                      })
                    }
                  >
                    <option value="">Selecciona…</option>
                    {catalogos.ramos.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.nombre}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="polizas-form-group">
                  <label>Subramo *</label>
                  <select
                    required
                    value={formData.subramo_id}
                    onChange={(e) => setFormData({ ...formData, subramo_id: e.target.value })}
                  >
                    <option value="">Selecciona…</option>
                    {subramosFiltrados.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.nombre}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="polizas-form-group">
                <label>Agente (opcional, id agente_workspace)</label>
                <select
                  value={formData.agente_id}
                  onChange={(e) => setFormData({ ...formData, agente_id: e.target.value })}
                >
                  <option value="">Ninguno</option>
                  {agentesOpciones.map((aw) => (
                    <option key={aw.id} value={aw.id}>
                      {aw.agente?.nombre} {aw.agente?.apellido || ''} ( #{aw.id} )
                    </option>
                  ))}
                </select>
                <small style={{ opacity: 0.8 }}>
                  Debe tener clave para la aseguradora elegida.
                </small>
              </div>

              <div className="polizas-form-group">
                <label>Número de póliza *</label>
                <input
                  required
                  value={formData.numero_poliza}
                  onChange={(e) => setFormData({ ...formData, numero_poliza: e.target.value })}
                  placeholder="Ej: 123-456789-01"
                />
              </div>

              <div className="polizas-form-row">
                <div className="polizas-form-group">
                  <label>Emisión</label>
                  <input
                    type="date"
                    value={formData.fecha_emision}
                    onChange={(e) => setFormData({ ...formData, fecha_emision: e.target.value })}
                  />
                </div>
                <div className="polizas-form-group">
                  <label>Moneda</label>
                  <input
                    value={formData.moneda}
                    onChange={(e) => setFormData({ ...formData, moneda: e.target.value })}
                    placeholder="MXN"
                  />
                </div>
              </div>

              <div className="polizas-form-row">
                <div className="polizas-form-group">
                  <label>Inicio vigencia *</label>
                  <input
                    type="date"
                    required
                    value={formData.inicio_vigencia}
                    onChange={(e) => setFormData({ ...formData, inicio_vigencia: e.target.value })}
                  />
                </div>
                <div className="polizas-form-group">
                  <label>Fin vigencia *</label>
                  <input
                    type="date"
                    required
                    value={formData.fin_vigencia}
                    onChange={(e) => setFormData({ ...formData, fin_vigencia: e.target.value })}
                  />
                </div>
              </div>

              <div className="polizas-form-row">
                <div className="polizas-form-group">
                  <label>Prima neta</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={formData.prima_neta}
                    onChange={(e) => setFormData({ ...formData, prima_neta: e.target.value })}
                  />
                </div>
                <div className="polizas-form-group">
                  <label>IVA</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={formData.iva}
                    onChange={(e) => setFormData({ ...formData, iva: e.target.value })}
                  />
                </div>
                <div className="polizas-form-group">
                  <label>Prima total</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={formData.prima_total}
                    onChange={(e) => setFormData({ ...formData, prima_total: e.target.value })}
                  />
                </div>
              </div>

              <div className="polizas-form-row">
                <div className="polizas-form-group">
                  <label>Frecuencia de cobro</label>
                  <select
                    value={formData.frecuencia_cobro}
                    onChange={(e) => setFormData({ ...formData, frecuencia_cobro: e.target.value })}
                  >
                    <option value="unico">Único</option>
                    <option value="mensual">Mensual</option>
                    <option value="trimestral">Trimestral</option>
                  </select>
                </div>
                <div className="polizas-form-group">
                  <label>Monto por cuota</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={formData.monto_cuota}
                    onChange={(e) => setFormData({ ...formData, monto_cuota: e.target.value })}
                    placeholder="Si vacío y hay prima total, se usa prima total"
                  />
                </div>
                <div className="polizas-form-group">
                  <label>Tel. notificación cobranza</label>
                  <input
                    value={formData.telefono_notificacion}
                    onChange={(e) =>
                      setFormData({ ...formData, telefono_notificacion: e.target.value })
                    }
                    placeholder="Opcional; si vacío, tel. del contratante"
                  />
                </div>
              </div>

              <div className="polizas-form-actions">
                <button type="button" onClick={onClose} className="polizas-btn-cancel">
                  Cancelar
                </button>
                <button type="submit" className="polizas-btn-submit" disabled={saving}>
                  {saving ? 'Guardando…' : type === 'add' ? 'Guardar Póliza' : 'Actualizar'}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
};

export default SeguimientoPolizas;
