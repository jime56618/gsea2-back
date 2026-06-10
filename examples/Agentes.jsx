import React, { useEffect, useState, useMemo, Fragment, useCallback } from 'react';
import {
  useReactTable,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  flexRender,
} from '@tanstack/react-table';
import { Menu, Transition, Dialog } from '@headlessui/react';
import { motion, AnimatePresence } from 'framer-motion';
import { 
  Search, UserPlus, MoreVertical, Edit2, Trash2, 
  ChevronLeft, ChevronRight, X, AlertTriangle, Mail, 
  Phone, MapPin, Building, User, Users, CheckCircle2, XCircle,
  Filter, Calendar, ArrowUpDown, ChevronDown
} from 'lucide-react';

import Sidebar from './Sidebar';
import Navbar from './Navbar';
import './css/Agentes.css';

export default function SeccionAgentes() {
  const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);
  const [data, setData] = useState([]);
  const [aseguradoras, setAseguradoras] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');
  const [sorting, setSorting] = useState([]);
  const [filtroEstado, setFiltroEstado] = useState('todos');
  const [filtroEmpresa, setFiltroEmpresa] = useState('todos');
  const [ordenamiento, setOrdenamiento] = useState('reciente');
  const API_URL = 'http://localhost:8000/api';

  const [agentToEdit, setAgentToEdit] = useState(null);
  const [agentToDelete, setAgentToDelete] = useState(null);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [apiError, setApiError] = useState('');
  const [formData, setFormData] = useState({
    cedula: '',
    nombre: '',
    apellido: '',
    email: '',
    telefono: '',
    ciudad: '',
    estado: '',
    direccion: '',
    curp: '',
    rfc: '',
    fecha_nacimiento: '',
    fecha_alta: '',
    status: 'activo',
    claves: [{ aseguradora_id: '', clave_agente: '' }],
  });

  const safeParse = (value) => {
    try {
      return value ? JSON.parse(value) : null;
    } catch {
      return null;
    }
  };

  const getAuthContext = () => {
    const token = localStorage.getItem('token');
    const workspaceRaw = localStorage.getItem('workspace_activo');
    const currentIdRaw = localStorage.getItem('current_workspace_id');
    const userRaw = localStorage.getItem('user');
    const workspace = safeParse(workspaceRaw);
    const user = safeParse(userRaw);
    const fromStorageId = currentIdRaw ? Number(currentIdRaw) : null;
    const workspaceId =
      workspace?.id ||
      (Number.isFinite(fromStorageId) ? fromStorageId : null) ||
      user?.current_workspace_id ||
      user?.workspace_id ||
      user?.workspace?.id ||
      (Array.isArray(user?.workspaces) ? user.workspaces[0]?.id : null);
    return { token, workspaceId };
  };

  const normalizeWorkspaceAgent = (item) => ({
    id: item.id,
    workspace_id: item.workspace_id,
    agente_id: item.agente_id,
    status: item.status || 'activo',
    nombreCompleto: `${item.agente?.nombre || ''} ${item.agente?.apellido || ''}`.trim(),
    nombre: item.agente?.nombre || '',
    apellido: item.agente?.apellido || '',
    cedula: item.agente?.cedula || '',
    email: item.agente?.email || '',
    telefono: item.agente?.telefono || '',
    ciudad: item.agente?.ciudad || '',
    estado: item.agente?.estado || '',
    direccion: item.agente?.direccion || '',
    curp: item.agente?.curp || '',
    rfc: item.agente?.rfc || '',
    fecha_nacimiento: item.agente?.fecha_nacimiento || '',
    fecha_alta: item.agente?.fecha_alta || '',
    claves_aseguradora: item.claves_aseguradora || [],
  });

  const resetForm = () => {
    setFormData({
      cedula: '',
      nombre: '',
      apellido: '',
      email: '',
      telefono: '',
      ciudad: '',
      estado: '',
      direccion: '',
      curp: '',
      rfc: '',
      fecha_nacimiento: '',
      fecha_alta: '',
      status: 'activo',
      claves: [{ aseguradora_id: '', clave_agente: '' }],
    });
  };

  const openAddModal = () => {
    setApiError('');
    resetForm();
    setIsAddModalOpen(true);
  };

  const openEditModal = (agent) => {
    setApiError('');
    setFormData({
      cedula: agent.cedula || '',
      nombre: agent.nombre || '',
      apellido: agent.apellido || '',
      email: agent.email || '',
      telefono: agent.telefono || '',
      ciudad: agent.ciudad || '',
      estado: agent.estado || '',
      direccion: agent.direccion || '',
      curp: agent.curp || '',
      rfc: agent.rfc || '',
      fecha_nacimiento: agent.fecha_nacimiento || '',
      fecha_alta: agent.fecha_alta || '',
      status: agent.status || 'activo',
      claves: (agent.claves_aseguradora || []).length
        ? agent.claves_aseguradora.map((c) => ({
            aseguradora_id: String(c.aseguradora_id || ''),
            clave_agente: c.clave_agente || '',
          }))
        : [{ aseguradora_id: '', clave_agente: '' }],
    });
    setAgentToEdit(agent);
  };

  const empresas = useMemo(() => {
    const unique = [
      ...new Set(
        data.flatMap((item) =>
          (item.claves_aseguradora || [])
            .map((clave) => clave.aseguradora?.nombre)
            .filter(Boolean)
        )
      ),
    ];
    return ['todos', ...unique];
  }, [data]);

  const fetchAgentes = useCallback(async () => {
    try {
      const { token, workspaceId } = getAuthContext();
      setApiError('');
      if (!token) {
        setApiError('No hay sesión activa.');
        setLoading(false);
        return;
      }
      if (!workspaceId) {
        setApiError('No se detectó workspace activo.');
        setLoading(false);
        return;
      }
      const res = await fetch(`${API_URL}/agentes_workspace?workspace_id=${workspaceId}&per_page=15`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      if (!res.ok) {
        setApiError(json?.message || 'Error al cargar agentes.');
        setData([]);
        return;
      }
      setData((json.data || []).map(normalizeWorkspaceAgent));
    } catch (error) {
      console.error('Error cargando agentes:', error);
      setApiError('Error de conexión con el servidor.');
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchAseguradoras = useCallback(async () => {
    try {
      const { token } = getAuthContext();
      if (!token) return;
      const res = await fetch(`${API_URL}/aseguradoras?per_page=100`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      if (res.ok) setAseguradoras(json.data || []);
    } catch (error) {
      console.error('Error cargando aseguradoras:', error);
    }
  }, []);

  useEffect(() => {
    fetchAgentes();
    fetchAseguradoras();
  }, [fetchAgentes, fetchAseguradoras]);

  const datosFiltrados = useMemo(() => {
    let filtered = [...data];
    if (filtroEstado !== 'todos') {
      filtered = filtered.filter((item) => item.status === filtroEstado);
    }
    if (filtroEmpresa !== 'todos') {
      filtered = filtered.filter((item) =>
        (item.claves_aseguradora || []).some(
          (clave) => clave.aseguradora?.nombre === filtroEmpresa
        )
      );
    }
    switch (ordenamiento) {
      case 'reciente':
        filtered.sort((a, b) => b.id - a.id);
        break;
      case 'antiguo':
        filtered.sort((a, b) => a.id - b.id);
        break;
      case 'nombre':
        filtered.sort((a, b) => a.nombreCompleto.localeCompare(b.nombreCompleto));
        break;
      default:
        break;
    }
    return filtered;
  }, [data, filtroEstado, filtroEmpresa, ordenamiento]);

  const stats = useMemo(() => ({
    total: datosFiltrados.length,
    activos: datosFiltrados.filter((a) => a.status === 'activo').length,
    inactivos: datosFiltrados.filter((a) => a.status === 'inactivo').length,
  }), [datosFiltrados]);

  const columns = useMemo(() => [
    {
      accessorKey: 'nombreCompleto',
      header: 'Agente',
      cell: info => (
        <div className="agents-agent-cell">
          <button className="agents-agent-name">
            {/* Se sustituyó la imagen por el SVG solicitado */}
            <svg 
              stroke="currentColor" 
              fill="none" 
              strokeWidth="2" 
              viewBox="0 0 24 24" 
              strokeLinecap="round" 
              strokeLinejoin="round" 
              className="agents-agent-avatar tramites-agent-icon" 
              height="1em" 
              width="1em" 
              xmlns="http://www.w3.org/2000/svg"
            >
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <div className="agents-agent-info">
              <p className="agents-agent-fullname">{info.getValue()}</p>
              <p className="agents-agent-email">{info.row.original.email}</p>
            </div>
          </button>
        </div>
      ),
    },
    { accessorKey: 'cedula', header: 'Cédula' },
    { 
      accessorKey: 'telefono', 
      header: 'Teléfono', 
      cell: info => <span className="agents-phone-text">{info.getValue()}</span> 
    },
    { 
      accessorKey: 'ciudad', 
      header: 'Ciudad', 
      cell: info => <span className="agents-city-text">{info.getValue()}</span> 
    },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: info => <span className="agents-city-text">{info.getValue()}</span>,
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: info => (
        <span className={`agents-status-badge ${info.getValue() === 'activo' ? 'agents-status-active' : 'agents-status-inactive'}`}>
          {info.getValue()}
        </span>
      ),
    },
    { accessorKey: 'fecha_nacimiento', header: 'Nacimiento' },
    { accessorKey: 'curp', header: 'CURP' },
    { accessorKey: 'rfc', header: 'RFC' },
    { accessorKey: 'direccion', header: 'Dirección' },
    { accessorKey: 'fecha_alta', header: 'Alta' },
    {
      id: 'claves',
      header: 'Claves',
      cell: info => (
        <div className="agents-city-text">
          {(info.row.original.claves_aseguradora || []).map((clave) => (
            <div key={clave.id || `${clave.aseguradora_id}-${clave.clave_agente}`}>
              {(clave.aseguradora?.nombre || 'Aseguradora')}: {clave.clave_agente}
            </div>
          ))}
        </div>
      ),
    },
    {
      id: 'actions',
      header: '',
      cell: info => (
        <Menu as="div" className="agents-actions-menu">
          <Menu.Button as="div" className="agents-actions-button">
            < MoreVertical size={18} />
          </Menu.Button>
          <Transition as={Fragment} enter="transition duration-100 ease-out" enterFrom="transform scale-95 opacity-0" enterTo="transform scale-100 opacity-100" leave="transition duration-75 ease-in" leaveFrom="transform scale-100 opacity-100" leaveTo="transform scale-95 opacity-0">
            <Menu.Items className="agents-menu-items">
              <div className="p-1">
                <Menu.Item>
                  {({ active }) => (
                    <button onClick={() => openEditModal(info.row.original)} className={`agents-menu-item ${active ? 'agents-menu-item-active' : ''}`}>
                      <Edit2 size={16} className="mr-2" /> Editar
                    </button>
                  )}
                </Menu.Item>
                <Menu.Item>
                  {({ active }) => (
                    <button onClick={() => setAgentToDelete(info.row.original)} className={`agents-menu-item ${active ? 'agents-menu-item-delete-active' : 'agents-menu-item-delete'}`}>
                      <Trash2 size={16} className="mr-2" /> Eliminar
                    </button>
                  )}
                </Menu.Item>
              </div>
            </Menu.Items>
          </Transition>
        </Menu>
      ),
    },
  ], []);

  const table = useReactTable({
    data: datosFiltrados,
    columns,
    state: { globalFilter, sorting },
    onGlobalFilterChange: setGlobalFilter,
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    getSortedRowModel: getSortedRowModel(),
    initialState: { pagination: { pageSize: 8 } },
  });

  return (
    <div className="agents-container">
      <Sidebar onExpand={setIsSidebarExpanded} /> 

      <div className={`agents-main-content ${isSidebarExpanded ? 'sidebar-expanded' : 'sidebar-collapsed'}`}>
        <Navbar /> 

        <main className="agents-main">
          {apiError && <p className="agents-empty-subtext">{apiError}</p>}
          {loading && <p className="agents-empty-subtext">Cargando agentes...</p>}
          <div className="agents-page-header">
            <h1 className="agents-page-title">Agentes</h1>
            <p className="agents-page-subtitle">Gestiona los permisos y perfiles de tu equipo.</p>
          </div>

          {/* Tarjetas de estadísticas */}
          <div className="agents-stats-grid">
            <StatCard 
              label="Total de Agentes" 
              value={stats.total} 
              icon={<Users size={24}/>} 
              color="agents-blue" 
            />
            <StatCard 
              label="Agentes Activos" 
              value={stats.activos} 
              icon={<CheckCircle2 size={24}/>} 
              color="agents-green" 
            />
            <StatCard 
              label="Agentes Inactivos" 
              value={stats.inactivos} 
              icon={<XCircle size={24}/>} 
              color="agents-red" 
            />
          </div>

          {/* Barra de búsqueda y filtros */}
          <div className="agents-filters-section">
            <div className="agents-search-wrapper">
              <Search className="agents-search-icon" size={20} />
              <input 
                type="text" 
                value={globalFilter} 
                onChange={e => setGlobalFilter(e.target.value)} 
                placeholder="Buscar por nombre, empresa..." 
                className="agents-search-input"
              />
            </div>

            <div className="agents-filters-wrapper">
              {/* Filtro de ordenamiento */}
              <div className="agents-filter-group">
                <Calendar size={16} className="agents-filter-icon" />
                <select 
                  value={ordenamiento} 
                  onChange={(e) => setOrdenamiento(e.target.value)}
                  className="agents-filter-select"
                >
                  <option value="reciente">Más reciente</option>
                  <option value="antiguo">Más antiguo</option>
                  <option value="nombre">Por nombre</option>
                </select>
                <ChevronDown size={14} className="agents-select-chevron" />
              </div>

              {/* Filtro por estado */}
              <div className="agents-filter-group">
                <Filter size={16} className="agents-filter-icon" />
                <select 
                  value={filtroEstado} 
                  onChange={(e) => setFiltroEstado(e.target.value)}
                  className="agents-filter-select"
                >
                  <option value="todos">Todos los estados</option>
                  <option value="activo">Activos</option>
                  <option value="inactivo">Inactivos</option>
                </select>
                <ChevronDown size={14} className="agents-select-chevron" />
              </div>

              {/* Filtro por empresa */}
              <div className="agents-filter-group">
                <Building size={16} className="agents-filter-icon" />
                <select 
                  value={filtroEmpresa} 
                  onChange={(e) => setFiltroEmpresa(e.target.value)}
                  className="agents-filter-select"
                >
                  <option value="todos">Todas las aseguradoras</option>
                  {empresas.filter(e => e !== 'todos').map(empresa => (
                    <option key={empresa} value={empresa}>{empresa}</option>
                  ))}
                </select>
                <ChevronDown size={14} className="agents-select-chevron" />
              </div>

              <button onClick={openAddModal} className="agents-add-button">
                <UserPlus size={18} />
                <span>Agregar</span>
              </button>
            </div>
          </div>

          {/* Tabla de agentes */}
          <div className="agents-table-container">
            <div className="agents-table-header">
              <h2 className="agents-table-title">Lista de Agentes</h2>
              {(filtroEstado !== 'todos' || filtroEmpresa !== 'todos' || ordenamiento !== 'reciente' || globalFilter !== '') && (
                <button 
                  className="agents-clear-filters"
                  onClick={() => {
                    setFiltroEstado('todos');
                    setFiltroEmpresa('todos');
                    setOrdenamiento('reciente');
                    setGlobalFilter('');
                  }}
                >
                  <X size={14} />
                  Limpiar filtros
                </button>
              )}
            </div>
            
            <div className="agents-table-responsive">
              <table className="agents-table">
                <thead>
                  {table.getHeaderGroups().map(hg => (
                    <tr key={hg.id}>
                      {hg.headers.map(header => (
                        <th key={header.id} className="agents-table-head" onClick={header.column.getToggleSortingHandler()}>
                          <div className="agents-table-head-content">
                            {flexRender(header.column.columnDef.header, header.getContext())}
                            {header.column.getCanSort() && (
                              <ArrowUpDown size={14} className="agents-sort-icon" />
                            )}
                          </div>
                        </th>
                      ))}
                    </tr>
                  ))}
                </thead>
                <tbody>
                  {table.getRowModel().rows.length > 0 ? (
                    table.getRowModel().rows.map(row => (
                      <tr key={row.id} className="agents-table-row">
                        {row.getVisibleCells().map(cell => (
                          <td key={cell.id} className="agents-table-cell">
                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                          </td>
                        ))}
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={columns.length} className="agents-table-empty">
                        <div className="agents-empty-state">
                          <Users size={48} className="agents-empty-icon" />
                          <p className="agents-empty-text">No se encontraron agentes</p>
                          <p className="agents-empty-subtext">Intenta con otros filtros</p>
                        </div>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {/* Paginación */}
            <div className="agents-pagination">
              <p className="agents-pagination-info">
                Mostrando {table.getRowModel().rows.length} de {datosFiltrados.length} registros
              </p>
              <div className="agents-pagination-controls">
                <button 
                  onClick={() => table.previousPage()} 
                  disabled={!table.getCanPreviousPage()} 
                  className="agents-pagination-button"
                >
                  <ChevronLeft size={18} />
                </button>
                <span className="agents-pagination-page">
                  Página {table.getState().pagination.pageIndex + 1} de {table.getPageCount()}
                </span>
                <button 
                  onClick={() => table.nextPage()} 
                  disabled={!table.getCanNextPage()} 
                  className="agents-pagination-button"
                >
                  <ChevronRight size={18} />
                </button>
              </div>
            </div>
          </div>
        </main>
      </div>

      {/* Modal de Agregar/Editar */}
      <AnimatePresence>
        {(agentToEdit || isAddModalOpen) && (
          <Dialog as="div" className="agents-modal" onClose={() => { setAgentToEdit(null); setIsAddModalOpen(false); }} open={true}>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="agents-modal-overlay" />
            <div className="agents-modal-container">
              <Dialog.Panel className="agents-modal-panel">
                <div className="agents-modal-header">
                  <h2 className="agents-modal-title">{agentToEdit ? 'Editar Agente' : 'Agregar Nuevo Agente'}</h2>
                  <button onClick={() => {setAgentToEdit(null); setIsAddModalOpen(false);}} className="agents-modal-close">
                    <X size={20}/>
                  </button>
                </div>
                {apiError && <p className="agents-empty-subtext">{apiError}</p>}
                <div className="agents-modal-form">
                  <ModalInput label="Cédula" icon={<User size={16}/>} value={formData.cedula} onChange={(e) => setFormData({ ...formData, cedula: e.target.value })} />
                  <ModalInput label="Nombre" icon={<User size={16}/>} value={formData.nombre} onChange={(e) => setFormData({ ...formData, nombre: e.target.value })} />
                  <ModalInput label="Apellido" icon={<User size={16}/>} value={formData.apellido} onChange={(e) => setFormData({ ...formData, apellido: e.target.value })} />
                  <ModalInput label="Email" icon={<Mail size={16}/>} value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} />
                  <ModalInput label="Teléfono" icon={<Phone size={16}/>} value={formData.telefono} onChange={(e) => setFormData({ ...formData, telefono: e.target.value })} />
                  <ModalInput label="Ciudad" icon={<MapPin size={16}/>} value={formData.ciudad} onChange={(e) => setFormData({ ...formData, ciudad: e.target.value })} />
                  <ModalInput label="Estado" icon={<MapPin size={16}/>} value={formData.estado} onChange={(e) => setFormData({ ...formData, estado: e.target.value })} />
                  <ModalInput label="CURP" icon={<User size={16}/>} value={formData.curp} onChange={(e) => setFormData({ ...formData, curp: e.target.value })} />
                  <ModalInput label="RFC" icon={<User size={16}/>} value={formData.rfc} onChange={(e) => setFormData({ ...formData, rfc: e.target.value })} />
                  <div className="agents-modal-input-group agents-full-width">
                    <label className="agents-modal-input-label">Status</label>
                    <div className="agents-modal-input-wrapper">
                      <span className="agents-modal-input-icon"><Filter size={16} /></span>
                      <select className="agents-modal-input-field" value={formData.status} onChange={(e) => setFormData({ ...formData, status: e.target.value })}>
                        <option value="activo">activo</option>
                        <option value="inactivo">inactivo</option>
                      </select>
                    </div>
                  </div>
                  <div className="agents-full-width">
                    <p className="agents-modal-input-label">Claves por aseguradora</p>
                    {formData.claves.map((clave, idx) => (
                      <div key={idx} className="agents-modal-form">
                        <div className="agents-modal-input-group">
                          <label className="agents-modal-input-label">Aseguradora</label>
                          <div className="agents-modal-input-wrapper">
                            <span className="agents-modal-input-icon"><Building size={16} /></span>
                            <select
                              className="agents-modal-input-field"
                              value={clave.aseguradora_id}
                              onChange={(e) => {
                                const next = [...formData.claves];
                                next[idx].aseguradora_id = e.target.value;
                                setFormData({ ...formData, claves: next });
                              }}
                            >
                              <option value="">Selecciona</option>
                              {aseguradoras.map((a) => (
                                <option key={a.id} value={a.id}>{a.nombre}</option>
                              ))}
                            </select>
                          </div>
                        </div>
                        <ModalInput
                          label="Clave agente"
                          icon={<User size={16} />}
                          value={clave.clave_agente}
                          onChange={(e) => {
                            const next = [...formData.claves];
                            next[idx].clave_agente = e.target.value;
                            setFormData({ ...formData, claves: next });
                          }}
                        />
                      </div>
                    ))}
                    <button
                      type="button"
                      className="agents-modal-cancel"
                      onClick={() => setFormData({
                        ...formData,
                        claves: [...formData.claves, { aseguradora_id: '', clave_agente: '' }],
                      })}
                    >
                      + Agregar clave
                    </button>
                  </div>
                </div>
                <div className="agents-modal-actions">
                  <button onClick={() => {setAgentToEdit(null); setIsAddModalOpen(false);}} className="agents-modal-cancel">
                    Cancelar
                  </button>
                  <button
                    type="button"
                    className="agents-modal-confirm"
                    disabled={saving}
                    onClick={async () => {
                      try {
                        const { token, workspaceId } = getAuthContext();
                        if (!token || !workspaceId) {
                          setApiError('Falta token o workspace activo.');
                          return;
                        }
                        if (!formData.cedula || !formData.nombre) {
                          setApiError('Cédula y nombre son obligatorios.');
                          return;
                        }
                        setSaving(true);
                        setApiError('');
                        const endpoint = agentToEdit
                          ? `${API_URL}/agentes_workspace/${agentToEdit.id}`
                          : `${API_URL}/agentes_workspace`;
                        const method = agentToEdit ? 'PUT' : 'POST';
                        const payload = {
                          workspace_id: workspaceId,
                          status: formData.status,
                          agente: {
                            cedula: formData.cedula,
                            nombre: formData.nombre,
                            apellido: formData.apellido || null,
                            email: formData.email || null,
                            telefono: formData.telefono || null,
                            fecha_nacimiento: formData.fecha_nacimiento || null,
                            curp: formData.curp || null,
                            rfc: formData.rfc || null,
                            estado: formData.estado || null,
                            ciudad: formData.ciudad || null,
                            direccion: formData.direccion || null,
                            fecha_alta: formData.fecha_alta || null,
                            activo: formData.status === 'activo',
                            foto_url: null,
                          },
                          claves: formData.claves
                            .filter((c) => c.aseguradora_id && c.clave_agente)
                            .map((c) => ({
                              aseguradora_id: Number(c.aseguradora_id),
                              clave_agente: c.clave_agente,
                            })),
                        };
                        const res = await fetch(endpoint, {
                          method,
                          headers: {
                            Authorization: `Bearer ${token}`,
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                          },
                          body: JSON.stringify(payload),
                        });
                        const json = await res.json();
                        if (!res.ok) {
                          setApiError(json?.message || 'No se pudo guardar el agente.');
                          return;
                        }
                        setAgentToEdit(null);
                        setIsAddModalOpen(false);
                        await fetchAgentes();
                      } catch (error) {
                        console.error(error);
                        setApiError('Error de conexión con el servidor.');
                      } finally {
                        setSaving(false);
                      }
                    }}
                  >
                    {saving ? 'Guardando...' : 'Guardar Cambios'}
                  </button>
                </div>
              </Dialog.Panel>
            </div>
          </Dialog>
        )}
      </AnimatePresence>

      {/* Modal de Eliminar */}
      <AnimatePresence>
        {agentToDelete && (
          <Dialog as="div" className="agents-modal" onClose={() => setAgentToDelete(null)} open={true}>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="agents-modal-overlay agents-modal-overlay-delete" />
            <div className="agents-modal-container">
              <Dialog.Panel className="agents-modal-panel agents-modal-panel-delete">
                <div className="agents-delete-icon">
                  <AlertTriangle size={40} />
                </div>
                <h3 className="agents-delete-title">¿Eliminar Agente?</h3>
                <p className="agents-delete-text">
                  Vas a eliminar a {agentToDelete?.nombreCompleto || 'este agente'}. Esta acción no se puede deshacer.
                </p>
                <button 
                  onClick={async () => {
                    if (!agentToDelete) return;
                    try {
                      const { token } = getAuthContext();
                      setApiError('');
                      const res = await fetch(`${API_URL}/agentes_workspace/${agentToDelete.id}`, {
                        method: 'DELETE',
                        headers: {
                          Authorization: `Bearer ${token}`,
                          Accept: 'application/json',
                        },
                      });
                      if (!res.ok) {
                        const json = await res.json();
                        setApiError(json?.message || 'No se pudo eliminar el agente.');
                        return;
                      }
                      setData((prev) => prev.filter((a) => a.id !== agentToDelete.id));
                      setAgentToDelete(null);
                    } catch (error) {
                      console.error(error);
                      setApiError('Error de conexión con el servidor.');
                    }
                  }} 
                  className="agents-delete-confirm"
                >
                  Eliminar Ahora
                </button>
                <button onClick={() => setAgentToDelete(null)} className="agents-delete-cancel">
                  Cancelar
                </button>
              </Dialog.Panel>
            </div>
          </Dialog>
        )}
      </AnimatePresence>
    </div>
  );
}

function StatCard({ label, value, icon, color }) {
  return (
    <motion.div 
      whileHover={{ y: -4, transition: { duration: 0.2 } }}
      className="agents-stat-card"
    >
      <div className="agents-stat-content">
        <div>
          <p className="agents-stat-label">{label}</p>
          <p className="agents-stat-value">{value}</p>
        </div>
        <div className={`agents-stat-icon ${color}`}>
          {icon}
        </div>
      </div>
      <div className="agents-stat-trend">
        <span className="agents-trend-indicator"></span>
        <span className="agents-trend-text">Total registrado</span>
      </div>
    </motion.div>
  );
}

function ModalInput({ label, icon, className = '', ...props }) {
  return (
    <div className={`agents-modal-input-group ${className}`}>
      <label className="agents-modal-input-label">{label}</label>
      <div className="agents-modal-input-wrapper">
        <span className="agents-modal-input-icon">{icon}</span>
        <input {...props} className="agents-modal-input-field" />
      </div>
    </div>
  );
}