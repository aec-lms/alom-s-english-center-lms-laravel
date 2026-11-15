import React from 'react';

interface StudentReviewAvatarProps {
  name: string;
  avatarUrl?: string;
  index?: number;
}

const AVATAR_PALETTES = [
  { bg: '#FBCFE8', hair: '#1E293B', skin: '#FDBA74', shirt: '#0284C7', female: true },
  { bg: '#C7D2FE', hair: '#0F172A', skin: '#FDBA74', shirt: '#4F46E5', female: false },
  { bg: '#FECDD3', hair: '#451A03', skin: '#FDE68A', shirt: '#E11D48', female: true },
  { bg: '#A7F3D0', hair: '#1E293B', skin: '#FDBA74', shirt: '#059669', female: false },
  { bg: '#DDD6FE', hair: '#312E81', skin: '#FDE68A', shirt: '#7C3AED', female: true },
  { bg: '#BAE6FD', hair: '#0F172A', skin: '#FDBA74', shirt: '#0369A1', female: false },
  { bg: '#FDE68A', hair: '#78350F', skin: '#FDBA74', shirt: '#D97706', female: true },
  { bg: '#99F6E4', hair: '#0F172A', skin: '#FDE68A', shirt: '#0D9488', female: false },
];

const isValidAvatarUrl = (url?: string): boolean => {
  if (!url || !url.trim()) return false;
  return !url.includes('images.unsplash.com') && !url.includes('placeholder');
};

export const StudentReviewAvatar: React.FC<StudentReviewAvatarProps> = ({
  name,
  avatarUrl,
  index = 0,
}) => {
  const [imgFailed, setImgFailed] = React.useState(false);

  if (isValidAvatarUrl(avatarUrl) && !imgFailed) {
    return (
      <img
        src={avatarUrl}
        alt={name}
        referrerPolicy="no-referrer"
        onError={() => setImgFailed(true)}
        className="w-10 h-10 rounded-full object-cover border border-white/15 bg-slate-800 shrink-0"
      />
    );
  }

  const hash = Array.from(name).reduce((acc, ch) => acc + ch.charCodeAt(0), index);
  const p = AVATAR_PALETTES[hash % AVATAR_PALETTES.length];

  return (
    <svg
      viewBox="0 0 48 48"
      className="w-10 h-10 rounded-full border border-white/20 shrink-0"
      aria-hidden="true"
    >
      <circle cx="24" cy="24" r="24" fill={p.bg} />
      {/* Shoulders / Collar */}
      <path
        d="M9 46C10.5 37.5 16.5 33.5 24 33.5C31.5 33.5 37.5 37.5 39 46Z"
        fill={p.shirt}
      />
      {/* Hair back for female portraits */}
      {p.female && (
        <path
          d="M14 23C14 14 18.5 10 24 10C29.5 10 34 14 34 23V31H14V23Z"
          fill={p.hair}
        />
      )}
      {/* Neck */}
      <rect x="21" y="27" width="6" height="8" rx="3" fill={p.skin} />
      {/* Face */}
      <circle cx="24" cy="21.5" r="8" fill={p.skin} />
      {/* Hair top */}
      <path
        d={
          p.female
            ? 'M16 20C16 14.5 19.5 11.5 24 11.5C28.5 11.5 32 14.5 32 20C29.5 17 26.5 16 24 16C21.5 16 18.5 17 16 20Z'
            : 'M16 19.5C16 13.8 19.6 11 24 11C28.4 11 32 13.8 32 19.5C30.2 16.5 27.5 15.5 24 15.5C20.5 15.5 17.8 16.5 16 19.5Z'
        }
        fill={p.hair}
      />
      {/* Eyes & Smile */}
      <circle cx="21.2" cy="21.5" r="1" fill="#1E293B" />
      <circle cx="26.8" cy="21.5" r="1" fill="#1E293B" />
      <path
        d="M21.5 24.8C22.3 25.8 25.7 25.8 26.5 24.8"
        stroke="#9A3412"
        strokeWidth="1.3"
        strokeLinecap="round"
        fill="none"
      />
    </svg>
  );
};
