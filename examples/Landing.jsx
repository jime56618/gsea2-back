import React from 'react';
import { Link } from 'react-router-dom';
import SplitText from "../Animacion/SplitText";
import ScrollReveal from "../Animacion/ScrollReveal";
import dashboardImg from "../assets/images/Hero.png";
import logoGSEA from "../assets/images/logo-gsea.png"; 
import { Zap, MessageSquare, ShieldCheck, ChevronRight, Users, Briefcase, Building } from 'lucide-react';
import './css/Landig.css'; 

const GSeaLanding = () => {
  return (
    <div className="antialiased font-['Plus_Jakarta_Sans',_sans-serif] bg-[#E5E9EC] text-[#09324A] scroll-smooth overflow-x-hidden">
      
      {/* Header */}
      <header className="bg-[#B8D0F9]/75 backdrop-blur-md border-b border-[#09324A]/5 px-6 py-4 flex justify-between items-center sticky top-0 z-50">
        <div className="flex items-center gap-3">
          <img src={logoGSEA} alt="GSEA Logo" className="h-10 w-auto object-contain" />
          <span className="font-extrabold text-2xl tracking-tighter text-[#09324A] hidden sm:block">G-SEA</span>
        </div>
        
        <nav className="hidden lg:flex gap-10 text-sm font-bold uppercase tracking-wider">
          {['inicio', 'que-ofrecemos', 'servicios', 'como-funciona', 'segmentos', 'precios', 'contacto'].map((item) => (
            <a key={item} href={`#${item}`} className="hover:text-[#1B6F81] transition-colors">
              {item.replace('-', ' ')}
            </a>
          ))}
        </nav>

        <div className="flex gap-4 items-center">
          <Link to="/register" className="px-6 py-2 text-sm font-bold hover:text-[#1B6F81] transition-colors">
            Login
          </Link>
          <a href="#precios" className="bg-[#09324A] text-white px-6 py-2 rounded-full text-sm font-bold hover:bg-[#1B6F81] transition-all shadow-lg flex items-center">
            Empezar Ahora
          </a>
        </div>
      </header>

      {/* Hero Section */}
      <section id="inicio" className="relative min-h-screen flex items-center pt-20 pb-32 px-6 overflow-hidden bg-[#09324A] scroll-mt-20">
        <div className="absolute inset-0 z-0">
          <img src={dashboardImg} alt="Background" className="w-full h-full object-cover opacity-60" />
          <div className="absolute inset-0 bg-[#09324A]/40"></div>
        </div>
        <div className="relative z-10 max-w-7xl mx-auto w-full">
          <ScrollReveal baseOpacity={0} translateY={30} duration={1}>
            <div className="max-w-3xl text-center lg:text-left">
              <span className="inline-block px-4 py-2 bg-[#1B6F81] rounded-full text-xs font-bold text-white mb-6 uppercase tracking-widest italic shadow-lg">
                InsurTech de Nueva Generación
              </span>
              <SplitText
                text="Digitaliza tu Promotoría con Inteligencia."
                tag="h1"
                className="text-5xl md:text-7xl font-extrabold mb-8 leading-[1.05] tracking-tight text-white"
                delay={40}
                duration={0.8}
                textAlign="left"
              />
              <p className="text-xl text-white/80 mb-10 max-w-xl mx-auto lg:mx-0 font-light font-medium">
                Centraliza la comunicación, automatiza cotizaciones y gestiona tu cartera de agentes desde un solo tablero profesional.
              </p>
              <div className="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                <a href="#contacto" className="bg-[#1B6F81] text-white px-10 py-5 rounded-2xl font-black text-lg shadow-2xl hover:bg-[#248da5] hover:scale-105 transition-all text-center">
                  Agendar Demo
                </a>
                <a href="#que-ofrecemos" className="bg-white/10 backdrop-blur-md border border-white/20 text-white px-10 py-5 rounded-2xl font-black text-lg hover:bg-white/20 transition-all text-center">
                  Saber más
                </a>
              </div>
            </div>
          </ScrollReveal>
        </div>
      </section>

      {/* Que Ofrecemos - Revelación Lateral */}
      <section id="que-ofrecemos" className="bg-[#F3F2EA] py-28 px-6 border-y border-[#09324A]/5 scroll-mt-20 overflow-hidden">
        <div className="max-w-7xl mx-auto grid md:grid-cols-2 gap-24 items-center">
          <ScrollReveal baseOpacity={0} translateX={-100} duration={1.2}>
            <div className="rounded-[3.5rem] overflow-hidden shadow-2xl border-8 border-white relative group">
              <img src="https://images.unsplash.com/photo-1551434678-e076c223a692?auto=format&fit=crop&w=800&q=80" alt="Dashboard" className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
            </div>
          </ScrollReveal>
          <ScrollReveal baseOpacity={0} translateX={100} duration={1.2}>
            <div className="space-y-8">
              <h2 className="text-5xl md:text-6xl font-extrabold leading-tight tracking-tighter text-[#09324A]">
                Comunicación instantánea, <br />
                <span className="text-[#1B6F81]">cotizaciones rápidas.</span>
              </h2>
              <p className="text-xl text-[#09324A]/60 leading-relaxed font-light">
                Diseñamos una interfaz intuitiva donde la velocidad es la prioridad. Cotiza y gestiona en segundos con tecnología de alto nivel.
              </p>
              <button className="px-8 py-4 bg-[#09324A] text-white rounded-2xl font-bold hover:bg-[#1B6F81] transition-all flex items-center gap-2">
                Explorar Funciones <ChevronRight size={20} />
              </button>
            </div>
          </ScrollReveal>
        </div>
      </section>

      {/* Servicios - Revelación con Stagger (Escalonada) */}
      <section id="servicios" className="bg-[#F8F9FA] py-32 px-6 scroll-mt-20">
        <div className="max-w-7xl mx-auto">
          <ScrollReveal baseOpacity={0} translateY={40} duration={1}>
            <div className="text-center mb-24 space-y-4">
              <span className="text-[#1B6F81] font-bold tracking-widest uppercase text-sm">Nuestras Ventajas</span>
              <h2 className="text-5xl md:text-6xl font-extrabold tracking-tight text-[#09324A]">Soluciones de Alto Impacto</h2>
            </div>
          </ScrollReveal>
          <div className="grid md:grid-cols-3 gap-10 items-stretch">
            <ScrollReveal translateY={60} delay={0.1} duration={0.8}>
              <FeatureCard icon={<Zap />} title="Cotización Express" description="Algoritmos que entregan precios reales en menos de 30 segundos." />
            </ScrollReveal>
            <ScrollReveal translateY={60} delay={0.3} duration={0.8}>
              <FeatureCard icon={<MessageSquare />} title="Chat Integrado" description="Comunicación directa entre promotoría y agente sin salir de la plataforma." />
            </ScrollReveal>
            <ScrollReveal translateY={60} delay={0.5} duration={0.8}>
              <FeatureCard icon={<ShieldCheck />} title="Emisión Digital" description="Cero papeles. Emite pólizas al instante con firma digital válida." />
            </ScrollReveal>
          </div>
        </div>
      </section>

      {/* Video - Zoom In Reveal */}
      <section id="como-funciona" className="py-32 px-6 bg-[#E5E9EC] scroll-mt-20">
        <div className="max-w-5xl mx-auto text-center">
          <ScrollReveal scale={0.8} baseOpacity={0} duration={1}>
            <h2 className="text-4xl font-black mb-16 text-[#09324A] tracking-tighter">Mira cómo transformamos tu flujo de trabajo</h2>
            <div className="relative group cursor-pointer rounded-[4rem] overflow-hidden shadow-2xl border-8 border-white bg-[#09324A] aspect-video flex items-center justify-center">
              <div className="w-24 h-24 bg-[#FFFB08] text-[#09324A] rounded-full flex items-center justify-center text-3xl shadow-2xl group-hover:scale-110 transition-all z-10">
                <span className="ml-1.5">▶</span>
              </div>
              <img src="https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80" alt="Preview" className="absolute inset-0 w-full h-full object-cover opacity-40 transition-transform duration-1000 group-hover:scale-110" />
            </div>
          </ScrollReveal>
        </div>
      </section>

      {/* Segmentos */}
      <section id="segmentos" className="py-32 px-6 max-w-7xl mx-auto scroll-mt-20">
        <ScrollReveal translateY={30} duration={1}>
          <div className="text-center mb-20">
            <h2 className="text-4xl md:text-5xl font-extrabold text-[#09324A]">Diseñado para cada <span className="text-[#1B6F81]">rol</span></h2>
          </div>
        </ScrollReveal>
        <div className="grid lg:grid-cols-3 gap-10 items-stretch">
          <ScrollReveal translateX={-50} delay={0.2}><SegmentCard icon={<Users />} title="Promotoras" subtitle="Control total administrativo y seguimiento de metas en tiempo real." img="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=400&q=80" accent="border-[#1B6F81]" /></ScrollReveal>
          <ScrollReveal translateY={50} delay={0.4}><SegmentCard icon={<Briefcase />} title="Agentes" subtitle="CRM inteligente móvil para gestionar tu cartera donde estés." img="https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=400&q=80" accent="border-[#FFFB08]" /></ScrollReveal>
          <ScrollReveal translateX={50} delay={0.6}><SegmentCard icon={<Building />} title="Empresas" subtitle="Conectividad API corporativa para flujos masivos de datos." img="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=400&q=80" accent="border-[#1B6F81]" /></ScrollReveal>
        </div>
      </section>

      {/* Precios */}
      <section id="precios" className="py-32 px-6 bg-[#F3F2EA] border-y border-[#09324A]/5 scroll-mt-20">
        <div className="max-w-7xl mx-auto">
          <ScrollReveal translateY={30}>
            <div className="text-center mb-20 space-y-4">
              <h2 className="text-5xl font-extrabold text-[#09324A]">Planes que crecen contigo</h2>
              <p className="text-[#09324A]/60 text-lg font-medium">Transparencia total para potenciar tu equipo.</p>
            </div>
          </ScrollReveal>
          <div className="grid md:grid-cols-3 gap-8 items-stretch">
            <ScrollReveal translateY={40} delay={0.1}><PricingCard tier="Plan Inicial" price="2" features={['Hasta 5 Agentes', 'Soporte Básico', 'Cotizaciones ilimitadas']} /></ScrollReveal>
            <ScrollReveal translateY={40} delay={0.3}><PricingCard tier="Business" price="5" features={['Agentes Ilimitados', 'CRM Avanzado', 'Lead Management']} popular /></ScrollReveal>
            <ScrollReveal translateY={40} delay={0.5}><PricingCard tier="Elite" price="10" features={['Marca Blanca', 'API Full Access', 'Soporte VIP']} /></ScrollReveal>
          </div>
        </div>
      </section>

      {/* Contacto */}
      <section id="contacto" className="py-32 px-6 bg-[#E1F5F2] scroll-mt-20">
        <ScrollReveal translateY={60} duration={1}>
          <div className="max-w-6xl mx-auto bg-white rounded-[4rem] overflow-hidden flex flex-col md:flex-row shadow-2xl border-8 border-white">
            <div className="md:w-1/2 p-12 lg:p-16 space-y-10">
              <h2 className="text-5xl font-extrabold tracking-tighter text-[#09324A]">Hablemos de tu <br /> próximo nivel.</h2>
              <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
                <input type="text" placeholder="Nombre de tu Promotoría" className="w-full p-5 bg-gray-50 rounded-2xl border border-gray-100 outline-none font-semibold focus:ring-2 ring-[#1B6F81]" />
                <input type="email" placeholder="Correo corporativo" className="w-full p-5 bg-gray-50 rounded-2xl border border-gray-100 outline-none font-semibold focus:ring-2 ring-[#1B6F81]" />
                <button className="w-full bg-[#09324A] text-white py-5 rounded-2xl font-black text-xl hover:bg-[#1B6F81] transition-all mt-6 shadow-xl">Enviar Mensaje</button>
              </form>
            </div>
            <div className="md:w-1/2 relative min-h-[400px]">
              <img src="https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=1024&q=80" alt="Contact" className="absolute inset-0 w-full h-full object-cover" />
            </div>
          </div>
        </ScrollReveal>
      </section>

      {/* Footer */}
      <footer className="bg-[#09324A] text-white py-24 px-10">
        <div className="max-w-7xl mx-auto text-center md:text-left">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-12 mb-16">
            <div className="space-y-6">
              <img src={logoGSEA} alt="GSEA Footer Logo" className="h-12 w-auto brightness-0 invert" />
              <p className="text-white/60 text-sm leading-relaxed">Potenciamos la industria de seguros con tecnología de vanguardia.</p>
            </div>
            <FooterCol title="Características" links={['Funciones principales', 'Experiencia pro', 'Integraciones']} />
            <FooterCol title="Aprender más" links={['Blog Informativo', 'Casos de estudio', 'Historias']} />
            <FooterCol title="Soporte" links={['Contacto directo', 'Centro de ayuda', 'Aviso Legal']} />
          </div>
          <div className="pt-8 border-t border-white/10 text-center text-white/20 text-xs font-bold uppercase tracking-tighter">
            © 2026 G-SEA Soluciones Digitales. Todos los derechos reservados.
          </div>
        </div>
      </footer>
    </div>
  );
};

/* --- COMPONENTES AUXILIARES --- */

const FeatureCard = ({ icon, title, description }) => (
  <div className="h-full group bg-white p-10 rounded-[3rem] shadow-sm hover:shadow-2xl transition-all hover:-translate-y-2 border border-slate-50 flex flex-col">
    <div className="w-16 h-16 bg-[#09324A] rounded-2xl flex items-center justify-center mb-8 shadow-lg text-white group-hover:scale-110 transition-transform">
      {React.cloneElement(icon, { size: 28, strokeWidth: 2.5 })}
    </div>
    <h3 className="text-2xl font-bold mb-4 text-[#09324A]">{title}</h3>
    <p className="text-base text-[#09324A]/60 font-medium leading-relaxed">{description}</p>
  </div>
);

const SegmentCard = ({ icon, title, subtitle, img, accent }) => (
  <div className={`h-full group bg-white p-10 rounded-[3.5rem] text-center border-b-8 border-transparent hover:${accent} transition-all shadow-sm flex flex-col`}>
    <div className="w-20 h-20 mx-auto bg-[#F3F2EA] rounded-3xl flex items-center justify-center mb-8 group-hover:scale-110 transition-transform text-[#1B6F81]">
      {React.cloneElement(icon, { size: 35 })}
    </div>
    <h3 className="font-bold text-2xl mb-4 text-[#09324A]">{title}</h3>
    <p className="text-sm text-[#09324A]/60 mb-8 font-medium flex-grow">{subtitle}</p>
    <div className="rounded-2xl overflow-hidden mt-auto shadow-inner bg-gray-100">
      <img src={img} alt={title} className="w-full h-48 object-cover opacity-90 group-hover:scale-105 transition-transform duration-500" />
    </div>
  </div>
);

const PricingCard = ({ tier, price, features, popular }) => (
  <div className={`plan-card h-full bg-white p-12 rounded-[3.5rem] flex flex-col items-center text-center shadow-lg border border-slate-100 ${popular ? 'ring-2 ring-[#1B6F81]' : ''}`}>
    {popular && <span className="bg-[#1B6F81] text-white px-4 py-1 rounded-full text-[10px] font-bold uppercase mb-6">Más Popular</span>}
    <h4 className="plan-subtitle font-bold uppercase tracking-widest text-xs mb-8 opacity-50 transition-colors">{tier}</h4>
    <div className="price-text text-7xl font-black mb-12 tracking-tighter text-[#09324A] transition-colors flex items-start">
      <span className="text-3xl mt-2">$</span>{price}
    </div>
    <ul className="space-y-4 mb-14 text-sm font-semibold opacity-80 flex-grow">
      {features.map((f, i) => (
        <li key={i} className="flex items-center gap-2">
          <ShieldCheck className="check-icon text-[#1B6F81] transition-colors" size={16} /> {f}
        </li>
      ))}
    </ul>
    <button className="btn-plan w-full py-4 rounded-2xl border-2 border-[#09324A] font-bold uppercase text-xs tracking-widest transition-all mt-auto">
      Seleccionar
    </button>
  </div>
);

const FooterCol = ({ title, links }) => (
  <div>
    <h4 className="font-extrabold text-sm mb-8 uppercase tracking-widest text-[#FFFB08]">{title}</h4>
    <ul className="space-y-4 text-sm text-white/60 font-medium">
      {links.map((link) => (<li key={link}><a href="#" className="hover:text-white transition-colors">{link}</a></li>))}
    </ul>
  </div>
);

export default GSeaLanding;