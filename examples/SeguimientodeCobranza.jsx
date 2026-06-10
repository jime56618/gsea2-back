import React, { useState, useMemo, Fragment, useEffect, useCallback } from 'react';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import { 
  FiSearch, FiPlus, FiEdit2, FiTrash2, FiMoreVertical, 
  FiDownload, FiFilter, FiChevronLeft, FiChevronRight, FiX, 
  FiAlertTriangle, FiFileText, FiUser, FiDollarSign, FiCalendar,
  FiEye, FiCopy, FiPrinter, FiArchive
} from 'react-icons/fi';
import { Menu, Transition } from '@headlessui/react';
import { motion, AnimatePresence } from 'framer-motion';
import './css/SeguimientoCobranza.css';

import { fetchJson } from './utils/apiClient';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

function nombreAgenteDesdePoliza(poliza) {
  const ag = poliza?.agente_workspace?.agente || poliza?.agenteWorkspace?.agente;
  if (!ag) return '—';
  return [ag.nombre, ag.apellido].filter(Boolean).join(' ').trim() || '—';
}

function mapCuotaToRegistro(c) {
  return {
    id: c.id,
    poliza: c.poliza?.numero_poliza || '—',
    cliente: c.poliza?.contratante?.nombre || '—',
    agente: nombreAgenteDesdePoliza(c.poliza),
    monto: Number(c.monto),
    fecha_prog: (c.fecha_programada || '').toString().slice(0, 10),
    estatus: c.estatus || 'pendiente',
    _fromApi: true,
  };
}

const SeguimientoCobranza = () => {
  const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);
  const [hoverStatus, setHoverStatus] = useState(null);
  const [menuOpen, setMenuOpen] = useState(null);
  const [registros, setRegistros] = useState([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  const [searchTerm, setSearchTerm] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [modalDeleteOpen, setModalDeleteOpen] = useState(false);
  const [currentRegistro, setCurrentRegistro] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 5;

  const loadRegistros = useCallback(async () => {
    setLoadError('');
    setLoading(true);
    try {
      const json = await fetchJson(`${API_URL}/cobranza_cuotas?per_page=500`);
      const rows = (json.data || []).map(mapCuotaToRegistro);
      setRegistros(rows);
      setCurrentPage(1);
    } catch (e) {
      setLoadError(e.message || 'No se pudieron cargar las cuotas');
      setRegistros([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadRegistros();
  }, [loadRegistros]);

  const filteredRegistros = useMemo(() => {
    const q = searchTerm.toLowerCase();
    return registros.filter(
      (reg) =>
        (reg.cliente || '').toLowerCase().includes(q) ||
        (reg.poliza || '').toLowerCase().includes(q) ||
        (reg.agente || '').toLowerCase().includes(q)
    );
  }, [registros, searchTerm]);

  const totalPages = Math.max(1, Math.ceil(filteredRegistros.length / itemsPerPage));
  const paginatedRegistros = useMemo(() => {
    const start = (currentPage - 1) * itemsPerPage;
    return filteredRegistros.slice(start, start + itemsPerPage);
  }, [filteredRegistros, currentPage]);

  const handleOpenAdd = () => {
    window.alert(
      'Las cuotas se generan al crear o editar una póliza en Seguimiento de Pólizas, con prima total o monto por cuota y frecuencia de cobro.'
    );
  };

  const handleOpenEdit = (e, registro) => {
    e.stopPropagation();
    setCurrentRegistro({ ...registro });
    setModalOpen(true);
  };

  const handleOpenDelete = (e, registro) => {
    e.stopPropagation();
    setCurrentRegistro(registro);
    setModalDeleteOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (!currentRegistro?._fromApi) return;
    try {
      await fetchJson(`${API_URL}/cobranza_cuotas/${currentRegistro.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          fecha_programada: currentRegistro.fecha_prog,
          monto: Number(currentRegistro.monto),
          estatus: currentRegistro.estatus,
        }),
      });
      await loadRegistros();
      setModalOpen(false);
      setCurrentRegistro(null);
    } catch (err) {
      window.alert(err.message || 'Error al guardar');
    }
  };

  const handleDelete = async () => {
    if (!currentRegistro?._fromApi) return;
    try {
      await fetchJson(`${API_URL}/cobranza_cuotas/${currentRegistro.id}`, { method: 'DELETE' });
      await loadRegistros();
      setModalDeleteOpen(false);
      setCurrentRegistro(null);
    } catch (err) {
      window.alert(err.message || 'Error al eliminar');
    }
  };

  const getStatusColor = (estatus) => {
    switch (estatus) {
      case 'pagado':
      case 'Verde':
        return 'cobranza-status-verde';
      case 'pendiente':
      case 'Amarillo':
        return 'cobranza-status-amarillo';
      case 'vencido':
      case 'cancelado':
      case 'Rojo':
        return 'cobranza-status-rojo';
      default:
        return 'cobranza-status-amarillo';
    }
  };

  const estatusLabel = (estatus) => {
    const map = {
      pagado: 'Pagado',
      pendiente: 'Pendiente',
      vencido: 'Vencido',
      cancelado: 'Cancelado',
    };
    return map[estatus] || estatus || '—';
  };

  return (
    <div className="cobranza-container">
      <Sidebar onExpand={setIsSidebarExpanded} />
      
      <div className={`cobranza-main-content ${isSidebarExpanded ? 'cobranza-sidebar-expanded' : 'cobranza-sidebar-collapsed'}`}>
        <Navbar />

        <main className="cobranza-main">
          <div className="cobranza-header">
            <div>
              <h1 className="cobranza-title">Seguimiento de Cobranza</h1>
              <p className="cobranza-subtitle">
                Cuotas desde el API (<code>GET /cobranza_cuotas</code>). Token + workspace activo requeridos.
              </p>
              {loadError && (
                <p style={{ color: '#b91c1c', marginTop: 8 }}>{loadError}</p>
              )}
            </div>
            <div className="cobranza-header-actions">
              <motion.button 
                whileHover={{ scale: 1.02 }}
                whileTap={{ scale: 0.98 }}
                className="cobranza-btn-export"
              >
                <FiDownload size={18} /> Exportar
              </motion.button>
              <motion.button 
                whileHover={{ scale: 1.02 }}
                whileTap={{ scale: 0.98 }}
                onClick={handleOpenAdd}
                className="cobranza-btn-add"
              >
                <FiPlus size={18} /> Nuevo Registro
              </motion.button>
            </div>
          </div>

          <div className="cobranza-search-section">
            <div className="cobranza-search-wrapper">
              <FiSearch className="cobranza-search-icon" />
              <input 
                type="text" 
                placeholder="Buscar por póliza, cliente o agente..." 
                className="cobranza-search-input"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
            <motion.button 
              whileHover={{ scale: 1.02 }}
              whileTap={{ scale: 0.98 }}
              className="cobranza-btn-filter"
            >
              <FiFilter size={16} /> Filtros
            </motion.button>
          </div>

          {/* Tabla */}
          <div className="cobranza-table-container">
            <div className="cobranza-table-responsive">
              <table className="cobranza-table">
                <thead>
                  <tr>
                    <th>Póliza</th>
                    <th>Cliente</th>
                    <th>Agente</th>
                    <th>Fecha Prog.</th>
                    <th>Monto</th>
                    <th>Estatus</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  {loading && (
                    <tr>
                      <td colSpan={7} style={{ padding: '2rem', textAlign: 'center' }}>
                        Cargando cuotas…
                      </td>
                    </tr>
                  )}
                  {!loading && filteredRegistros.length === 0 && (
                    <tr>
                      <td colSpan={7} style={{ padding: '2rem', textAlign: 'center' }}>
                        No hay cuotas. Genera cuotas al crear una póliza con prima total o monto por cuota.
                      </td>
                    </tr>
                  )}
                  {!loading &&
                    paginatedRegistros.map((reg, index) => (
                    <motion.tr 
                      key={reg.id}
                      initial={{ opacity: 0, y: 20 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ delay: index * 0.05 }}
                      className="cobranza-table-row"
                    >
                      <td className="cobranza-cell-poliza">
                        <span className="cobranza-badge-poliza">{reg.poliza}</span>
                      </td>
                      <td className="cobranza-cell-cliente">
                        <span className="cobranza-cliente-nombre">{reg.cliente}</span>
                      </td>
                      <td className="cobranza-cell-agente">{reg.agente}</td>
                      <td className="cobranza-cell-fecha">
                        <div className="cobranza-fecha-wrapper">
                          <FiCalendar className="cobranza-fecha-icon" />
                          <span>{reg.fecha_prog}</span>
                        </div>
                      </td>
                      <td className="cobranza-cell-monto">
                        <span className="cobranza-monto">
                          ${Number(reg.monto).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                        </span>
                      </td>
                      <td className="cobranza-cell-status">
                        <div 
                          className="cobranza-status-wrapper"
                          onMouseEnter={() => setHoverStatus(reg.id)}
                          onMouseLeave={() => setHoverStatus(null)}
                        >
                          <div className={`cobranza-status-dot ${getStatusColor(reg.estatus)}`} />
                          <span className={`cobranza-status-text ${getStatusColor(reg.estatus)}`}>
                            {estatusLabel(reg.estatus)}
                          </span>
                          
                          <AnimatePresence>
                            {hoverStatus === reg.id && (
                              <motion.div 
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0 }}
                                className="cobranza-tooltip"
                              >
                                <div className="cobranza-tooltip-arrow" />
                                <p className="cobranza-tooltip-title">Guía de Seguimiento</p>
                                <div className="cobranza-tooltip-content">
                                  <p><span className="cobranza-tooltip-dot cobranza-dot-verde" /> Verde: Cobro exitoso</p>
                                  <p><span className="cobranza-tooltip-dot cobranza-dot-amarillo" /> Amarillo: En proceso / Promesa</p>
                                  <p><span className="cobranza-tooltip-dot cobranza-dot-rojo" /> Rojo: Urgente / Vencido</p>
                                </div>
                              </motion.div>
                            )}
                          </AnimatePresence>
                        </div>
                      </td>
                      <td className="cobranza-cell-actions">
                        <div className="cobranza-actions-group">
                          <motion.button 
                            whileHover={{ scale: 1.1 }}
                            whileTap={{ scale: 0.95 }}
                            onClick={(e) => handleOpenEdit(e, reg)} 
                            className="cobranza-action-btn cobranza-edit-btn"
                            title="Editar"
                          >
                            <FiEdit2 />
                          </motion.button>
                          
                          <motion.button 
                            whileHover={{ scale: 1.1 }}
                            whileTap={{ scale: 0.95 }}
                            onClick={(e) => handleOpenDelete(e, reg)} 
                            className="cobranza-action-btn cobranza-delete-btn"
                            title="Eliminar"
                          >
                            <FiTrash2 />
                          </motion.button>

                          {/* Menú de tres puntitos */}
                          <Menu as="div" className="cobranza-menu">
                            <Menu.Button as="div" className="cobranza-menu-button">
                              <FiMoreVertical />
                            </Menu.Button>
                            
                            <Transition
                              as={Fragment}
                              enter="cobranza-transition-enter"
                              enterFrom="cobranza-transition-enter-from"
                              enterTo="cobranza-transition-enter-to"
                              leave="cobranza-transition-leave"
                              leaveFrom="cobranza-transition-leave-from"
                              leaveTo="cobranza-transition-leave-to"
                            >
                              <Menu.Items className="cobranza-menu-items">
                                <Menu.Item>
                                  {({ active }) => (
                                    <button className={`cobranza-menu-item ${active ? 'cobranza-menu-item-active' : ''}`}>
                                      <FiEye /> Ver detalles
                                    </button>
                                  )}
                                </Menu.Item>
                                <Menu.Item>
                                  {({ active }) => (
                                    <button className={`cobranza-menu-item ${active ? 'cobranza-menu-item-active' : ''}`}>
                                      <FiCopy /> Duplicar
                                    </button>
                                  )}
                                </Menu.Item>
                                <Menu.Item>
                                  {({ active }) => (
                                    <button className={`cobranza-menu-item ${active ? 'cobranza-menu-item-active' : ''}`}>
                                      <FiPrinter /> Imprimir
                                    </button>
                                  )}
                                </Menu.Item>
                                <Menu.Item>
                                  {({ active }) => (
                                    <button className={`cobranza-menu-item ${active ? 'cobranza-menu-item-active' : ''}`}>
                                      <FiArchive /> Archivar
                                    </button>
                                  )}
                                </Menu.Item>
                              </Menu.Items>
                            </Transition>
                          </Menu>
                        </div>
                      </td>
                    </motion.tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Paginación */}
            <div className="cobranza-pagination">
              <div className="cobranza-pagination-info">
                Total: <span className="cobranza-pagination-highlight">{filteredRegistros.length}</span> cuotas
              </div>
              <div className="cobranza-pagination-controls">
                <motion.button 
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  className="cobranza-pagination-btn cobranza-pagination-prev"
                  onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                  disabled={currentPage === 1}
                >
                  <FiChevronLeft />
                </motion.button>
                <span className="cobranza-pagination-page">
                  Página {currentPage} de {totalPages}
                </span>
                <motion.button 
                  whileHover={{ scale: 1.05 }}
                  whileTap={{ scale: 0.95 }}
                  className="cobranza-pagination-btn cobranza-pagination-next"
                  onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
                  disabled={currentPage === totalPages}
                >
                  <FiChevronRight />
                </motion.button>
              </div>
            </div>
          </div>
        </main>
      </div>

      {/* Modal de Agregar/Editar */}
      <AnimatePresence>
        {modalOpen && currentRegistro && (
          <div className="cobranza-modal-overlay">
            <motion.div 
              initial={{ scale: 0.9, opacity: 0, y: 20 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              exit={{ scale: 0.9, opacity: 0, y: 20 }}
              className="cobranza-modal-panel cobranza-modal-form"
            >
              <div className="cobranza-modal-header">
                
                <h2 className="cobranza-modal-title">Editar cuota</h2>
                <button onClick={() => setModalOpen(false)} className="cobranza-modal-close">
                  <FiX />
                </button>
              </div>

              <form onSubmit={handleSave} className="cobranza-modal-form">
                <div className="cobranza-form-grid">
                  <div className="cobranza-form-group cobranza-full-width">
                    <label>Cliente *</label>
                    <div className="cobranza-input-wrapper">
                      <FiUser className="cobranza-input-icon" />
                      <input
                        type="text"
                        value={currentRegistro.cliente || ''}
                        onChange={(e) =>
                          setCurrentRegistro({ ...currentRegistro, cliente: e.target.value })
                        }
                        placeholder="Nombre completo"
                        readOnly={!!currentRegistro._fromApi}
                        required
                      />
                    </div>
                  </div>

                  <div className="cobranza-form-group">
                    <label>Póliza *</label>
                    <div className="cobranza-input-wrapper">
                      <FiFileText className="cobranza-input-icon" />
                      <input
                        type="text"
                        value={currentRegistro.poliza || ''}
                        onChange={(e) =>
                          setCurrentRegistro({ ...currentRegistro, poliza: e.target.value })
                        }
                        placeholder="Número de póliza"
                        readOnly={!!currentRegistro._fromApi}
                        required
                      />
                    </div>
                  </div>

                  <div className="cobranza-form-group">
                    <label>Agente *</label>
                    <div className="cobranza-input-wrapper">
                      <FiUser className="cobranza-input-icon" />
                      <input
                        type="text"
                        value={currentRegistro.agente || ''}
                        onChange={(e) =>
                          setCurrentRegistro({ ...currentRegistro, agente: e.target.value })
                        }
                        placeholder="Nombre del agente"
                        readOnly={!!currentRegistro._fromApi}
                        required
                      />
                    </div>
                  </div>

                  <div className="cobranza-form-group">
                    <label>Monto *</label>
                    <div className="cobranza-input-wrapper">
                      <FiDollarSign className="cobranza-input-icon" />
                      <input 
                        type="number" 
                        step="0.01"
                        value={currentRegistro.monto || ''} 
                        onChange={(e) =>
                          setCurrentRegistro({
                            ...currentRegistro,
                            monto: e.target.value === '' ? '' : parseFloat(e.target.value),
                          })
                        }
                        placeholder="0.00"
                        required
                      />
                    </div>
                  </div>

                  <div className="cobranza-form-group">
                    <label>Fecha Programada *</label>
                    <div className="cobranza-input-wrapper">
                      <FiCalendar className="cobranza-input-icon" />
                      <input 
                        type="date" 
                        value={currentRegistro.fecha_prog || ''} 
                        onChange={e => setCurrentRegistro({...currentRegistro, fecha_prog: e.target.value})}
                        required
                      />
                    </div>
                  </div>

                  <div className="cobranza-form-group cobranza-full-width">
                    <label>Estatus *</label>
                    <select
                      value={currentRegistro.estatus || 'pendiente'}
                      onChange={(e) =>
                        setCurrentRegistro({ ...currentRegistro, estatus: e.target.value })
                      }
                      className="cobranza-select"
                    >
                      <option value="pendiente">Pendiente</option>
                      <option value="pagado">Pagado</option>
                      <option value="vencido">Vencido</option>
                      <option value="cancelado">Cancelado</option>
                    </select>
                  </div>
                </div>

                <div className="cobranza-modal-actions">
                  <motion.button
                    whileHover={{ scale: 1.02 }}
                    whileTap={{ scale: 0.98 }}
                    type="button"
                    onClick={() => setModalOpen(false)}
                    className="cobranza-btn-cancel"
                  >
                    Cancelar
                  </motion.button>
                  <motion.button
                    whileHover={{ scale: 1.02 }}
                    whileTap={{ scale: 0.98 }}
                    type="submit"
                    className="cobranza-btn-save"
                  >
                    Guardar Cambios
                  </motion.button>
                </div>
              </form>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Modal de Eliminar */}
      <AnimatePresence>
        {modalDeleteOpen && currentRegistro && (
          <div className="cobranza-modal-overlay cobranza-modal-overlay-delete">
            <motion.div 
              initial={{ scale: 0.9, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.9, opacity: 0 }}
              className="cobranza-modal-panel cobranza-modal-delete"
            >
              <div className="cobranza-delete-icon">
                <FiAlertTriangle />
              </div>
              <h3 className="cobranza-delete-title">¿Eliminar registro?</h3>
              <p className="cobranza-delete-text">
                Vas a eliminar el seguimiento de la póliza <strong>{currentRegistro.poliza}</strong> de <strong>{currentRegistro.cliente}</strong>. Esta acción no se puede deshacer.
              </p>
              <div className="cobranza-delete-actions">
                <motion.button 
                  whileHover={{ scale: 1.02 }}
                  whileTap={{ scale: 0.98 }}
                  onClick={handleDelete}
                  className="cobranza-delete-confirm"
                >
                  Eliminar Ahora
                </motion.button>
                <motion.button 
                  whileHover={{ scale: 1.02 }}
                  whileTap={{ scale: 0.98 }}
                  onClick={() => setModalDeleteOpen(false)}
                  className="cobranza-delete-cancel"
                >
                  Cancelar
                </motion.button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default SeguimientoCobranza;