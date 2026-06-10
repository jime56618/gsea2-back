import React, { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  User,
  FileText,
  Calendar,
  DollarSign,
  GraduationCap,
  ClipboardList,
  LogOut,
  Settings,
  UserCog,
  CreditCard,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import logoGSEA from '../assets/images/logo-gsea.png';
import { useAuth } from './context/AuthContext';
import { ADMIN_MENU_ITEMS, MENU_ITEMS, filterMenuByPermissions } from './utils/permissions';
export default function SidebarGSEA({ onExpand }) {
  const [isExpanded, setIsExpanded] = useState(true);
  const location = useLocation();
  const { session, logout } = useAuth();

  const mainItems = filterMenuByPermissions(MENU_ITEMS, session);
  const adminItems = filterMenuByPermissions(ADMIN_MENU_ITEMS, session);

  useEffect(() => {
    onExpand?.(isExpanded);
  }, [isExpanded, onExpand]);

  const toggleExpand = () => setIsExpanded(!isExpanded);

  const renderLink = (item) => {
    const isActive = location.pathname === item.path;
    return (
      <Link key={item.path} to={item.path} className="block group">
        <motion.div
          className={`relative flex items-center h-11 rounded-xl transition-all duration-300 menu-item-hover ${
            isActive
              ? 'bg-gradient-to-r from-blue-600 to-blue-500 shadow-lg shadow-blue-600/30'
              : 'hover:bg-blue-800/30'
          } px-3`}
          whileHover={{ x: isExpanded ? 8 : 0 }}
          whileTap={{ scale: 0.98 }}
        >
          {isActive && isExpanded && (
            <motion.div
              className="absolute left-0 w-1 h-6 bg-white rounded-r-full"
              layoutId="activeIndicator"
            />
          )}
          <div
            className={`${isActive ? 'text-white' : 'text-blue-300/80 group-hover:text-blue-200'} transition-colors`}
          >
            {item.icon}
          </div>
          <AnimatePresence mode="wait">
            {isExpanded && (
              <motion.span
                className="ml-4 font-medium text-sm tracking-wide"
                initial={{ opacity: 0, x: -10 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -10 }}
              >
                {item.label}
              </motion.span>
            )}
          </AnimatePresence>
        </motion.div>
      </Link>
    );
  };

  const iconForAdmin = (path) => {
    if (path.includes('equipo')) return <UserCog size={20} />;
    if (path.includes('roles')) return <Settings size={20} />;
    if (path.includes('facturacion')) return <CreditCard size={20} />;
    return <Settings size={20} />;
  };

  return (
    <>
      <style>{`
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .menu-item-hover { position: relative; overflow: hidden; }
      `}</style>

      <motion.aside
        initial={false}
        animate={{ width: isExpanded ? 280 : 88 }}
        transition={{ type: 'spring', stiffness: 300, damping: 30 }}
        className="fixed inset-y-0 left-0 bg-[#001E42] text-white flex flex-col shadow-2xl z-50 border-r border-blue-900/30"
      >
        <div
          className="absolute right-0 top-0 bottom-0 w-2 cursor-ew-resize hover:bg-blue-400/20 z-10"
          onClick={toggleExpand}
        />

        <div className="h-24 flex items-center px-6 flex-shrink-0">
          <div className="flex items-center gap-4">
            <img src={logoGSEA} alt="GSEA" className="w-12 h-12 object-contain" />
            <AnimatePresence>
              {isExpanded && (
                <motion.h2
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  className="text-xl font-bold"
                >
                  GSEA CRM
                </motion.h2>
              )}
            </AnimatePresence>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto no-scrollbar px-3 py-4 space-y-1">
          {mainItems.map((item) =>
            renderLink({
              ...item,
              icon:
                item.path === '/dashboard' ? (
                  <LayoutDashboard size={22} />
                ) : item.path === '/tramites' ? (
                  <ClipboardList size={22} />
                ) : item.path === '/agentes' ? (
                  <Users size={22} />
                ) : item.path === '/clientes' ? (
                  <User size={22} />
                ) : item.path === '/seguimiento-polizas' ? (
                  <FileText size={22} />
                ) : item.path === '/seguimiento-cobranza' ? (
                  <DollarSign size={22} />
                ) : item.path === '/capacitacion' ? (
                  <GraduationCap size={22} />
                ) : (
                  <Calendar size={22} />
                ),
            })
          )}

          {adminItems.length > 0 && isExpanded && (
            <p className="text-xs text-blue-300/60 uppercase tracking-wider px-3 pt-4 pb-1">Configuración</p>
          )}
          {adminItems.map((item) =>
            renderLink({
              ...item,
              icon: iconForAdmin(item.path),
            })
          )}
        </nav>

        <div className="px-3 pb-4">
          <motion.button
            onClick={logout}
            className="flex items-center w-full h-11 px-3 rounded-xl hover:bg-red-500/10 text-red-400"
          >
            <LogOut size={22} />
            {isExpanded && <span className="ml-4 text-sm">Cerrar sesión</span>}
          </motion.button>
        </div>
      </motion.aside>
    </>
  );
}
