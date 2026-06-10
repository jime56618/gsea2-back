import React from 'react';
import Sidebar from '../Sidebar';
import Navbar from '../Navbar';
import TrialBanner from './TrialBanner';

/**
 * Layout estándar: sidebar + navbar + banner trial + contenido.
 */
export default function AppShell({ children, onSidebarExpand }) {
  const [isSidebarExpanded, setIsSidebarExpanded] = React.useState(false);

  const handleExpand = (expanded) => {
    setIsSidebarExpanded(expanded);
    onSidebarExpand?.(expanded);
  };

  return (
    <div className="gsea-app-shell">
      <Sidebar onExpand={handleExpand} />
      <div
        className={`gsea-app-main ${isSidebarExpanded ? 'gsea-app-main--expanded' : 'gsea-app-main--collapsed'}`}
      >
        <TrialBanner />
        <Navbar />
        {children}
      </div>
    </div>
  );
}
