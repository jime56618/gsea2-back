import React, { useState, useEffect } from 'react';
import Sidebar from './Sidebar'; 
import Navbar from './Navbar';
import { 
  PlayCircle, X, Check, FileText, ChevronDown, 
  Lock, ArrowLeft, KeyRound, CheckCircle2, ChevronLeft,
  Award, Clock, BookOpen, Target, TrendingUp,
  Star, Zap, Shield, Users, Calendar, Heart,
  Stethoscope, Car, Home
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import './css/Capacitacion.css';

// --- DATOS DE PRUEBA ---
const COURSES_DATA = [
  { 
    id: 1, 
    title: "Seguros de Vida", 
    subtitle: "Inducción GSEA", 
    duration: "1:30 hrs", 
    code: "GSEA2026", 
    color: "course-vida",
    icon: <Heart size={24} />,
    level: "Básico",
    progress: 75,
    modules: 4,
    completed: 3,
    description: "Fundamentos y técnicas de venta de seguros de vida"
  },
  { 
    id: 2, 
    title: "Gastos Médicos", 
    subtitle: "Cotización Avanzada", 
    duration: "2:30 hrs", 
    code: "MED2026", 
    color: "course-medicos",
    icon: <Stethoscope size={24} />,
    level: "Intermedio",
    progress: 30,
    modules: 6,
    completed: 2,
    description: "Coberturas, deducibles y primas en gastos médicos"
  },
  { 
    id: 3, 
    title: "Autos", 
    subtitle: "Coberturas y Exclusiones", 
    duration: "3:00 hrs", 
    code: "AUTO2026", 
    color: "course-autos",
    icon: <Car size={24} />,
    level: "Básico",
    progress: 0,
    modules: 5,
    completed: 0,
    description: "Tipos de cobertura, deducibles y siniestros"
  },
  { 
    id: 4, 
    title: "Daños", 
    subtitle: "Patrimoniales", 
    duration: "2:00 hrs", 
    code: "DANOS2026", 
    color: "course-danos",
    icon: <Home size={24} />,
    level: "Avanzado",
    progress: 60,
    modules: 8,
    completed: 5,
    description: "Seguros de hogar, negocios y responsabilidad civil"
  }
];

const MODULES_DATA = [
  { 
    id: 1, 
    title: "Módulo 1: Fundamentos", 
    description: "Conceptos básicos y principios esenciales",
    duration: "45 min",
    items: [
      { id: '1.1', label: "Introducción a los seguros de vida", status: "visto", duration: "10 min", type: "video" },
      { id: '1.2', label: "El origen de las historias", status: "visto", duration: "15 min", type: "video" },
      { id: '1.3', label: "El storytelling digital", status: "visto", duration: "12 min", type: "video" },
      { id: '1.4', label: "Marcos y técnicas", status: "pendiente", duration: "8 min", type: "lectura" },
    ], 
    completed: false,
    progress: 75
  },
  { 
    id: 2, 
    title: "Módulo 2: Técnicas Avanzadas", 
    description: "Estrategias y herramientas profesionales",
    duration: "1:30 hrs",
    items: [
      { id: '2.1', label: "Los objetivos comerciales", status: "bloqueado", duration: "20 min", type: "video" },
      { id: '2.2', label: "El papel de los KPI", status: "bloqueado", duration: "25 min", type: "video" },
      { id: '2.3', label: "Análisis de resultados", status: "bloqueado", duration: "30 min", type: "ejercicio" },
    ], 
    completed: false,
    requires: 1,
    progress: 0
  },
  { 
    id: 3, 
    title: "Módulo 3: Certificación", 
    description: "Evaluación final y práctica",
    duration: "2:00 hrs",
    items: [
      { id: '3.1', label: "Examen práctico", status: "bloqueado", duration: "1:30 hrs", type: "examen" },
      { id: '3.2', label: "Evaluación final", status: "bloqueado", duration: "30 min", type: "evaluacion" },
    ], 
    completed: false,
    requires: 2,
    progress: 0
  }
];

const CapacitacionPage = () => {
  const [isSidebarExpanded, setIsSidebarExpanded] = useState(false);
  const [view, setView] = useState('list');
  const [showAuthModal, setShowAuthModal] = useState(false);
  const [selectedCourse, setSelectedCourse] = useState(null);
  const [selectedItem, setSelectedItem] = useState(null);
  const [inputCode, setInputCode] = useState('');
  const [error, setError] = useState(false);
  const [expandedModules, setExpandedModules] = useState([1]);
  const [courseProgress, setCourseProgress] = useState(0);

  useEffect(() => {
    if (selectedCourse) {
      const totalItems = MODULES_DATA.reduce((acc, module) => acc + module.items.length, 0);
      const completedItems = MODULES_DATA.reduce((acc, module) => 
        acc + module.items.filter(item => item.status === 'visto').length, 0
      );
      setCourseProgress(Math.round((completedItems / totalItems) * 100));
    }
  }, [selectedCourse]);

  const handleCourseClick = (course) => {
    setSelectedCourse(course);
    setShowAuthModal(true);
  };

  const handleVerifyCode = () => {
    if (inputCode === selectedCourse.code) {
      setView('modules');
      setShowAuthModal(false);
      setInputCode('');
      setError(false);
    } else {
      setError(true);
      setTimeout(() => setError(false), 2000);
    }
  };

  const handleBack = () => {
    if (view === 'content') setView('modules');
    else if (view === 'modules') setView('list');
  };

  const toggleModule = (moduleId) => {
    setExpandedModules(prev => 
      prev.includes(moduleId) 
        ? prev.filter(id => id !== moduleId)
        : [...prev, moduleId]
    );
  };

  const getStatusIcon = (status) => {
    switch(status) {
      case 'visto': return <CheckCircle2 size={18} className="status-icon visto" />;
      case 'pendiente': return <div className="status-icon pendiente" />;
      case 'bloqueado': return <Lock size={16} className="status-icon bloqueado" />;
      default: return null;
    }
  };

  const getTypeIcon = (type) => {
    switch(type) {
      case 'video': return <PlayCircle size={14} className="type-icon video" />;
      case 'lectura': return <FileText size={14} className="type-icon lectura" />;
      case 'ejercicio': return <Target size={14} className="type-icon ejercicio" />;
      case 'examen': return <Award size={14} className="type-icon examen" />;
      default: return null;
    }
  };

  // --- COMPONENTE MODAL DE ACCESO (SIN ANIMACIONES) ---
  const AuthModal = () => (
    <div 
      className="capacitacion-modal-overlay"
      onClick={() => setShowAuthModal(false)}
    >
      <div 
        className="capacitacion-modal"
        onClick={(e) => e.stopPropagation()}
      >
        <button 
          onClick={() => setShowAuthModal(false)}
          className="capacitacion-modal-close"
        >
          <X size={20} />
        </button>

        <div className="capacitacion-modal-icon">
          <KeyRound size={32} />
        </div>
        
        <h2 className="capacitacion-modal-title">Acceso Protegido</h2>
        <p className="capacitacion-modal-subtitle">
          Desbloquear: <span>{selectedCourse?.title}</span>
        </p>
        
        <div className="capacitacion-modal-form">
          <input 
            type="text" 
            autoFocus
            value={inputCode}
            onChange={(e) => setInputCode(e.target.value.toUpperCase())}
            placeholder="CÓDIGO DE ACCESO"
            className={`capacitacion-modal-input ${error ? 'error' : ''}`}
            onKeyPress={(e) => e.key === 'Enter' && handleVerifyCode()}
          />
          
          <button 
            onClick={handleVerifyCode} 
            className="capacitacion-modal-button"
          >
            Validar y Entrar
          </button>
          
          <p className="capacitacion-modal-footer">
            Si no tienes el código, solicítalo a tu coordinador.
          </p>
        </div>
      </div>
    </div>
  );

  // --- VISTA DE MÓDULOS CON LÍNEA DE SEGUIMIENTO ---
  if (view === 'modules') {
    return (
      <div className="capacitacion-container">
        <Sidebar onExpand={setIsSidebarExpanded} activePage="capacitacion" />
        
        <div className={`capacitacion-main ${isSidebarExpanded ? 'sidebar-expanded' : 'sidebar-collapsed'}`}>
          <Navbar title={selectedCourse?.title} />

          <main className="capacitacion-content">
            <button onClick={handleBack} className="capacitacion-back-button">
              <ArrowLeft size={16} />
              Volver a cursos
            </button>

            {/* Cabecera del curso con línea de progreso */}
            <div className="capacitacion-course-header">
              <div>
                <h1 className="capacitacion-course-title">{selectedCourse?.title}</h1>
                <p className="capacitacion-course-subtitle">{selectedCourse?.subtitle}</p>
              </div>
              
              <div className="capacitacion-course-stats">
                <div className="capacitacion-stat-item">
                  <Clock size={16} />
                  <span>{selectedCourse?.duration}</span>
                </div>
                <div className="capacitacion-stat-item">
                  <BookOpen size={16} />
                  <span>{selectedCourse?.modules} módulos</span>
                </div>
                <div className="capacitacion-stat-item">
                  <TrendingUp size={16} />
                  <span>Nivel {selectedCourse?.level}</span>
                </div>
              </div>

              {/* Línea de seguimiento tipo Amazon */}
              <div className="capacitacion-tracking">
                <div className="capacitacion-tracking-bar">
                  <motion.div 
                    className="capacitacion-tracking-progress"
                    initial={{ width: 0 }}
                    animate={{ width: `${courseProgress}%` }}
                    transition={{ duration: 1, ease: "easeOut" }}
                  />
                </div>
                <div className="capacitacion-tracking-steps">
                  {MODULES_DATA.map((module, index) => {
                    const isCompleted = module.completed;
                    const isActive = module.requires 
                      ? MODULES_DATA.find(m => m.id === module.requires)?.completed 
                      : true;
                    
                    return (
                      <div 
                        key={module.id} 
                        className={`capacitacion-tracking-step ${isCompleted ? 'completed' : ''} ${isActive ? 'active' : ''}`}
                      >
                        <div className="capacitacion-step-indicator">
                          {isCompleted ? <Check size={12} /> : index + 1}
                        </div>
                        <div className="capacitacion-step-label">
                          <span className="capacitacion-step-title">{module.title}</span>
                          <span className="capacitacion-step-duration">{module.duration}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>

            {/* Módulos */}
            <div className="capacitacion-modules">
              {MODULES_DATA.map((module) => {
                const isLocked = module.requires && !MODULES_DATA.find(m => m.id === module.requires)?.completed;
                const isExpanded = expandedModules.includes(module.id);

                return (
                  <motion.div 
                    key={module.id} 
                    className={`capacitacion-module ${isLocked ? 'locked' : ''}`}
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: module.id * 0.1 }}
                  >
                    <div 
                      className="capacitacion-module-header"
                      onClick={() => !isLocked && toggleModule(module.id)}
                    >
                      <div className="capacitacion-module-title">
                        {isLocked ? <Lock size={18} /> : (
                          <motion.div
                            animate={{ rotate: isExpanded ? 180 : 0 }}
                            transition={{ duration: 0.2 }}
                          >
                            <ChevronDown size={18} />
                          </motion.div>
                        )}
                        <div>
                          <h3>{module.title}</h3>
                          <p className="capacitacion-module-description">{module.description}</p>
                        </div>
                      </div>
                      
                      <div className="capacitacion-module-stats">
                        <span className="capacitacion-module-duration">{module.duration}</span>
                        <div className="capacitacion-module-progress">
                          <div 
                            className="capacitacion-module-progress-bar" 
                            style={{ width: `${module.progress}%` }}
                          />
                        </div>
                      </div>
                    </div>

                    <AnimatePresence>
                      {isExpanded && !isLocked && (
                        <motion.div 
                          className="capacitacion-module-items"
                          initial={{ opacity: 0, height: 0 }}
                          animate={{ opacity: 1, height: 'auto' }}
                          exit={{ opacity: 0, height: 0 }}
                        >
                          {module.items.map((item) => (
                            <motion.div
                              key={item.id}
                              className={`capacitacion-module-item ${item.status}`}
                              onClick={() => item.status !== 'bloqueado' && (setSelectedItem(item), setView('content'))}
                              whileHover={{ scale: 1.01 }}
                              whileTap={{ scale: 0.99 }}
                            >
                              <div className="capacitacion-item-icon">
                                {getStatusIcon(item.status)}
                              </div>
                              
                              <div className="capacitacion-item-content">
                                <div className="capacitacion-item-header">
                                  <h4 className="capacitacion-item-title">{item.label}</h4>
                                  <div className="capacitacion-item-type">
                                    {getTypeIcon(item.type)}
                                    <span>{item.type}</span>
                                  </div>
                                </div>
                                
                                <div className="capacitacion-item-meta">
                                  <Clock size={12} />
                                  <span>{item.duration}</span>
                                </div>
                              </div>

                              {item.status === 'visto' && (
                                <div className="capacitacion-item-check">
                                  <Check size={14} />
                                </div>
                              )}
                            </motion.div>
                          ))}
                        </motion.div>
                      )}
                    </AnimatePresence>
                  </motion.div>
                );
              })}
            </div>
          </main>
        </div>
      </div>
    );
  }

  // --- VISTA DE CONTENIDO (REPRODUCTOR) ---
  if (view === 'content') {
    return (
      <div className="capacitacion-viewer">
        <div className="capacitacion-viewer-header">
          <button onClick={handleBack} className="capacitacion-viewer-back">
            <ChevronLeft size={20} />
          </button>
          <span className="capacitacion-viewer-title">{selectedItem?.label}</span>
          <button onClick={handleBack} className="capacitacion-viewer-close">
            <X size={20} />
          </button>
        </div>

        <div className="capacitacion-viewer-content">
          <div className="capacitacion-viewer-player">
            <div className="capacitacion-player-placeholder">
              <PlayCircle size={80} className="capacitacion-player-icon" />
              <p className="capacitacion-player-text">Reproduciendo: {selectedItem?.label}</p>
            </div>
          </div>

          <div className="capacitacion-viewer-info">
            <h2>{selectedItem?.label}</h2>
            <div className="capacitacion-info-tags">
              <span className="capacitacion-info-tag">{selectedItem?.type}</span>
              <span className="capacitacion-info-tag">{selectedItem?.duration}</span>
            </div>
            <p className="capacitacion-info-description">
              Contenido en desarrollo. Aquí se reproducirá el video de capacitación correspondiente.
            </p>
          </div>
        </div>
      </div>
    );
  }

  // --- VISTA PRINCIPAL (LISTA DE CURSOS) ---
  return (
    <div className="capacitacion-container">
      <Sidebar onExpand={setIsSidebarExpanded} activePage="capacitacion" />
      
      {showAuthModal && <AuthModal />}

      <div className={`capacitacion-main ${isSidebarExpanded ? 'sidebar-expanded' : 'sidebar-collapsed'}`}>
        <Navbar title="Capacitación" />

        <main className="capacitacion-content">
          <div className="capacitacion-header">
            <div>
              <h1 className="capacitacion-title">Cursos Disponibles</h1>
              <p className="capacitacion-subtitle">Especialización para Agentes G-SEA</p>
            </div>

            <div className="capacitacion-header-stats">
              <div className="capacitacion-header-stat">
                <Award size={20} />
                <div>
                  <span className="capacitacion-stat-value">{COURSES_DATA.length}</span>
                  <span className="capacitacion-stat-label">Cursos</span>
                </div>
              </div>
              <div className="capacitacion-header-stat">
                <Zap size={20} />
                <div>
                  <span className="capacitacion-stat-value">85%</span>
                  <span className="capacitacion-stat-label">Completado</span>
                </div>
              </div>
            </div>
          </div>

          <div className="capacitacion-courses-grid">
            {COURSES_DATA.map((curso, index) => (
              <motion.div 
                key={curso.id} 
                className={`capacitacion-course-card ${curso.color}`}
                onClick={() => handleCourseClick(curso)}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: index * 0.1 }}
                whileHover={{ y: -8 }}
              >
                <div className="capacitacion-course-header">
                  <div className="capacitacion-course-icon">
                    {curso.icon}
                  </div>
                  <div className="capacitacion-course-level">
                    <span>{curso.level}</span>
                  </div>
                </div>

                <div className="capacitacion-course-body">
                  <h3 className="capacitacion-course-body-title">{curso.title}</h3>
                  <p className="capacitacion-course-body-subtitle">{curso.subtitle}</p>
                  
                  <p className="capacitacion-course-description">{curso.description}</p>

                  <div className="capacitacion-course-progress">
                    <div className="capacitacion-progress-bar">
                      <motion.div 
                        className="capacitacion-progress-fill"
                        initial={{ width: 0 }}
                        animate={{ width: `${curso.progress}%` }}
                        transition={{ duration: 1, delay: 0.5 + index * 0.1 }}
                      />
                    </div>
                    <span className="capacitacion-progress-text">{curso.progress}%</span>
                  </div>

                  <div className="capacitacion-course-meta">
                    <div className="capacitacion-meta-item">
                      <Clock size={14} />
                      <span>{curso.duration}</span>
                    </div>
                    <div className="capacitacion-meta-item">
                      <BookOpen size={14} />
                      <span>{curso.completed}/{curso.modules} módulos</span>
                    </div>
                  </div>
                </div>

                <div className="capacitacion-course-footer">
                  <span className="capacitacion-course-code">{curso.code}</span>
                  <motion.div 
                    className="capacitacion-course-play"
                    whileHover={{ scale: 1.1 }}
                    whileTap={{ scale: 0.9 }}
                  >
                    <PlayCircle size={20} />
                  </motion.div>
                </div>
              </motion.div>
            ))}
          </div>
        </main>
      </div>
    </div>
  );
};

export default CapacitacionPage;