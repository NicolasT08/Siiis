import type { ReactNode } from 'react';

// lucide-react 1.x ya no incluye iconos de marcas: trazos propios con el mismo estilo (24×24, stroke).
function Svg({ children }: { children: ReactNode }) {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {children}
    </svg>
  );
}

const Facebook = () => (
  <Svg>
    <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" />
  </Svg>
);
const XLogo = () => (
  <Svg>
    <path d="M4 4l11.7 16H20L8.3 4z" />
    <path d="M4 20l6.8-6.8M13.2 10.8L20 4" />
  </Svg>
);
const Instagram = () => (
  <Svg>
    <rect x="2" y="2" width="20" height="20" rx="5" />
    <circle cx="12" cy="12" r="4" />
    <path d="M17.5 6.5h.01" />
  </Svg>
);
const Linkedin = () => (
  <Svg>
    <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z" />
    <rect x="2" y="9" width="4" height="12" />
    <circle cx="4" cy="4" r="2" />
  </Svg>
);
const Youtube = () => (
  <Svg>
    <path d="M2.5 17a24 24 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.6 49.6 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24 24 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.6 49.6 0 0 1-16.2 0A2 2 0 0 1 2.5 17" />
    <path d="m10 15 5-3-5-3z" />
  </Svg>
);

const SOCIAL_NETWORKS = [
  { name: 'Facebook', Icon: Facebook },
  { name: 'X', Icon: XLogo },
  { name: 'Instagram', Icon: Instagram },
  { name: 'LinkedIn', Icon: Linkedin },
  { name: 'YouTube', Icon: Youtube },
];

// TODO: enlazar a los perfiles oficiales cuando el semillero confirme las URLs.
export default function SocialLinks() {
  return (
    <ul className="socials" aria-label="Redes sociales">
      {SOCIAL_NETWORKS.map(({ name, Icon }) => (
        <li key={name} className="social-badge" title={name}>
          <Icon />
          <span className="visually-hidden">{name}</span>
        </li>
      ))}
    </ul>
  );
}
