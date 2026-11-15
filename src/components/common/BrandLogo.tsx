import React from 'react';

interface BrandEmblemProps {
  className?: string;
}

export const BrandEmblem: React.FC<BrandEmblemProps> = ({ className = 'w-9 h-9' }) => (
  <svg
    viewBox="0 0 64 64"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    className={className}
    aria-hidden="true"
  >
    {/* Thin inner silver-slate ring inspired by AEC Logo.png */}
    <circle cx="32" cy="32" r="24" stroke="#CBD5E1" strokeWidth="1.6" />
    {/* Outer broken amber-orange ring */}
    <path
      d="M13.5 16.5A26.5 26.5 0 0 1 56.5 23.5"
      stroke="#F59E0B"
      strokeWidth="3.2"
      strokeLinecap="round"
    />
    <path
      d="M56.5 40.5A26.5 26.5 0 0 1 11.2 48.8"
      stroke="#F59E0B"
      strokeWidth="3.2"
      strokeLinecap="round"
    />
    <path
      d="M7.5 39.5A26.5 26.5 0 0 1 10.5 20"
      stroke="#F59E0B"
      strokeWidth="3.2"
      strokeLinecap="round"
    />
    {/* Bold slate-navy geometric 'A' + center 'E' crossbar */}
    <path
      d="M13 46L21.5 18H36.5L33.5 23H25L20 46H13Z"
      fill="#1E293B"
    />
    <path d="M23.5 28L26.5 46H32.5L28.5 25L23.5 28Z" fill="#334155" />
    <path d="M27.5 31.5H42.5V36.5H27.5V31.5Z" fill="#1E293B" />
    {/* Vivid amber-orange 'C' arc */}
    <path
      d="M47 24.5C44.8 20.5 40.6 18 35.8 18C28.7 18 23.2 24.1 23.2 32C23.2 39.9 28.7 46 35.8 46C40.7 46 45 43.3 47.1 39.2L41.8 36.8C40.5 39.2 38.3 40.5 35.8 40.5C31.9 40.5 29.1 36.9 29.1 32C29.1 27.1 31.9 23.5 35.8 23.5C38.3 23.5 40.4 24.8 41.7 27.1L47 24.5Z"
      fill="#F59E0B"
    />
    {/* Open book glyph nestled on the right */}
    <path
      d="M44 29.5C46 28.8 48.2 29 49.5 30.2C50.8 29 53 28.8 55 29.5V36.5C53 35.8 50.8 36 49.5 37.2C48.2 36 46 35.8 44 36.5V29.5Z"
      fill="#FFFFFF"
      stroke="#64748B"
      strokeWidth="1.3"
      strokeLinejoin="round"
    />
    <path d="M49.5 30.2V37.2" stroke="#64748B" strokeWidth="1.2" />
  </svg>
);

interface BrandLogoProps {
  title?: string;
  tagline?: string;
  logoStyle?: string;
  logoUrl?: string;
  size?: 'sm' | 'md' | 'lg';
  variant?: 'stacked' | 'inline';
}

export const BrandLogo: React.FC<BrandLogoProps> = ({
  title = "ALOM'S",
  tagline = 'ENGLISH CENTER',
  logoStyle = 'icon_and_text',
  logoUrl = '',
  size = 'md',
  variant = 'stacked',
}) => {
  if (logoStyle === 'image_only' && logoUrl) {
    return (
      <img
        src={logoUrl}
        alt={`${title} ${tagline}`.trim()}
        className={size === 'lg' ? 'h-11 max-w-[190px] object-contain' : 'h-9 max-w-[180px] object-contain'}
      />
    );
  }

  const iconSize = size === 'lg' ? 'w-10 h-10' : size === 'sm' ? 'w-8 h-8' : 'w-9 h-9';

  return (
    <span className="inline-flex items-center gap-2.5 text-left">
      {logoStyle !== 'text_only' && <BrandEmblem className={`${iconSize} shrink-0`} />}
      {variant === 'inline' ? (
        <span className="text-base font-extrabold text-slate-900 tracking-tight">
          {title}
        </span>
      ) : (
        <span className="leading-[1.06]">
          <span
            className={`block font-black tracking-tight text-slate-900 uppercase ${
              size === 'lg' ? 'text-base' : 'text-[13px] sm:text-sm'
            }`}
          >
            {title}
          </span>
          {tagline && (
            <span
              className={`block font-extrabold tracking-tight text-slate-800 uppercase ${
                size === 'lg' ? 'text-xs' : 'text-[10px] sm:text-[11px]'
              }`}
            >
              {tagline}
            </span>
          )}
        </span>
      )}
    </span>
  );
};
