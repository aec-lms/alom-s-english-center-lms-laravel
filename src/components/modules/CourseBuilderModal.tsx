import React, { useEffect, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { X, Plus, BookOpen, Trash2, Check, ArrowRight, UploadCloud } from 'lucide-react';
import { Course } from '../../types/lms';
import { apiClient } from '../../services/apiClient';
import heroEducatorImg from '../../assets/images/hero_educator_studio_1794552233836.jpg';
import ieltsCourseImg from '../../assets/images/course_ielts_masterclass_1794552235026.jpg';
import spokenCourseImg from '../../assets/images/course_spoken_fluency_1794552235255.jpg';
import grammarCourseImg from '../../assets/images/course_grammar_bundle_1794552235221.jpg';
import instructorTanvirImg from '../../assets/images/instructor_tanvir_1794552825904.jpg';

interface CourseBuilderModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const CourseBuilderModal: React.FC<CourseBuilderModalProps> = ({ isOpen, onClose }) => {
  const { addCourse, currentUser } = useLms();
  const isAdmin = currentUser.role === 'admin';
  const [branches, setBranches] = useState<Array<{id:number;name:string;code:string}>>([]);
  const [branchId, setBranchId] = useState('');

  useEffect(() => {
    if (!isOpen || !isAdmin) return;
    let active = true;
    apiClient.branches.getAll().then(result => {
      if (active && result.success && Array.isArray(result.data)) {
        setBranches((result.data as Array<Record<string, unknown>>).filter(row => Number(row.is_active) === 1).map(row => ({ id: Number(row.id), name: String(row.name || ''), code: String(row.code || '') })));
      }
    });
    return () => { active = false; };
  }, [isOpen, isAdmin]);

  const [title, setTitle] = useState('');
  const [titleBn, setTitleBn] = useState('');
  const [category, setCategory] = useState<'IELTS' | 'Spoken' | 'Grammar' | 'Writing' | 'Corporate'>('IELTS');
  const [price, setPrice] = useState(6500);
  const [originalPrice, setOriginalPrice] = useState(9000);
  const [durationWeeks, setDurationWeeks] = useState(8);
  const [level, setLevel] = useState<'Beginner' | 'Intermediate' | 'Advanced' | 'All Levels'>('Intermediate');
  const [subtitle, setSubtitle] = useState('');
  const [description, setDescription] = useState('');
  const [instructorName, setInstructorName] = useState('Tanvir Ahmed');
  const [thumbnail, setThumbnail] = useState<string>(ieltsCourseImg);
  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState('');

  const handleThumbnailUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => {
      if (typeof e.target?.result === 'string') setThumbnail(e.target.result);
    };
    reader.readAsDataURL(file);
  };

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isSaving) return;
    setIsSaving(true);
    setSaveError('');
    const newCourse: Course = {
      id: `crs_${Date.now()}`,
      branchId: isAdmin ? branchId : currentUser.branchId,
      title,
      titleBn: titleBn || title,
      category: category as any,
      subtitle: subtitle || 'Professional English competency program',
      subtitleBn: 'আন্তর্জাতিক মানসম্মত ইংরেজি প্রশিক্ষণ',
      description: description || 'Comprehensive practical learning course with interactive live sessions.',
      descriptionBn: 'বাস্তবমুখী লাইভ সেশনের সাথে পূর্ণাঙ্গ প্রস্তুতি।',
      instructorName,
      instructorTitle: 'British Council & IDP Certified Mentor',
      instructorAvatar: instructorTanvirImg,
      price: Number(price),
      originalPrice: Number(originalPrice),
      durationWeeks: Number(durationWeeks),
      totalLessons: 24,
      totalStudents: 0,
      rating: 5.0,
      reviewCount: 0,
      level,
      thumbnail: thumbnail || ieltsCourseImg,
      featured: true,
      syllabus: [
        { week: 1, title: 'Orientation & Core Diagnostic', topics: ['Foundation Baseline', 'Study Plan Architecture'] },
        { week: 2, title: 'Core Fluency / Band 7.5+ Strategy', topics: ['Sentence Modeling', 'Active Vocabulary'] }
      ]
    };

    const result = await addCourse(newCourse);
    setIsSaving(false);
    if (!result.success) {
      setSaveError(result.error || 'Course could not be saved. Please try again.');
      return;
    }
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs animate-in fade-in overflow-y-auto">
      <div className="bg-white w-full max-w-2xl rounded-2xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col my-6 max-h-[90vh]">
        <div className="bg-[#0b1e36] text-white px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <BookOpen className="w-5 h-5 text-blue-400" />
            <h3 className="font-bold text-base">Add New Course & Curriculum</h3>
          </div>
          <button onClick={onClose} className="text-slate-300 hover:text-white">
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 overflow-y-auto space-y-4 text-xs">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block font-semibold text-slate-700 mb-1">Course Title (English) *</label>
              <input
                type="text"
                required
                value={title}
                onChange={e => setTitle(e.target.value)}
                placeholder="e.g. IELTS Academic Writing Masterclass"
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-hidden"
              />
            </div>
            <div>
              <label className="block font-semibold text-slate-700 mb-1">কোর্সের নাম (বাংলা)</label>
              <input
                type="text"
                value={titleBn}
                onChange={e => setTitleBn(e.target.value)}
                placeholder="যেমন: আইইএলটিএস অ্যাকাডেমিক রাইটিং"
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-hidden"
              />
            </div>
          </div>

          {isAdmin && <div>
            <label className="block font-semibold text-slate-700 mb-1">Course Branch</label>
            <select value={branchId} onChange={e => setBranchId(e.target.value)} className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
              <option value="">Global course (visible across branches)</option>
              {branches.map(branch => <option key={branch.id} value={branch.id}>{branch.name} ({branch.code})</option>)}
            </select>
            <p className="mt-1 text-[10px] text-slate-500">Choose a branch to restrict this course to that campus, or leave it global.</p>
          </div>}

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="block font-semibold text-slate-700 mb-1">Category</label>
              <select
                value={category}
                onChange={e => setCategory(e.target.value as any)}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white focus:outline-hidden"
              >
                <option value="IELTS">IELTS</option>
                <option value="Spoken">Spoken English</option>
                <option value="Grammar">Grammar Foundation</option>
                <option value="Writing">Writing Skills</option>
                <option value="Corporate">Corporate English</option>
              </select>
            </div>

            <div>
              <label className="block font-semibold text-slate-700 mb-1">Course Fee (BDT ৳) *</label>
              <input
                type="number"
                required
                value={price}
                onChange={e => setPrice(Number(e.target.value))}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono focus:outline-hidden"
              />
            </div>

            <div>
              <label className="block font-semibold text-slate-700 mb-1">Regular Fee (BDT ৳)</label>
              <input
                type="number"
                value={originalPrice}
                onChange={e => setOriginalPrice(Number(e.target.value))}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono focus:outline-hidden"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block font-semibold text-slate-700 mb-1">Duration (Weeks)</label>
              <input
                type="number"
                value={durationWeeks}
                onChange={e => setDurationWeeks(Number(e.target.value))}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono focus:outline-hidden"
              />
            </div>

            <div>
              <label className="block font-semibold text-slate-700 mb-1">Lead Instructor</label>
              <input
                type="text"
                value={instructorName}
                onChange={e => setInstructorName(e.target.value)}
                className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-hidden"
              />
            </div>
          </div>

          <div>
            <label className="block font-semibold text-slate-700 mb-1">Course Thumbnail Picture</label>
            <div className="flex flex-wrap items-center gap-3">
              <img src={thumbnail} alt="Course thumbnail preview" className="w-20 h-12 rounded-lg object-cover border border-slate-200" />
              <label className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 bg-slate-50 hover:bg-slate-100 font-semibold text-slate-700 cursor-pointer">
                <UploadCloud className="w-3.5 h-3.5 text-blue-600" />
                <span>Upload Picture</span>
                <input type="file" accept="image/*" onChange={handleThumbnailUpload} className="hidden" />
              </label>
              {[ieltsCourseImg, spokenCourseImg, grammarCourseImg, heroEducatorImg].map((preset, idx) => (
                <button
                  key={idx}
                  type="button"
                  onClick={() => setThumbnail(preset)}
                  className={`w-10 h-8 rounded overflow-hidden border-2 cursor-pointer ${thumbnail === preset ? 'border-blue-600' : 'border-transparent'}`}
                >
                  <img src={preset} alt={`Preset ${idx + 1}`} className="w-full h-full object-cover" />
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block font-semibold text-slate-700 mb-1">Short Description / Subtitle</label>
            <textarea
              rows={2}
              value={subtitle}
              onChange={e => setSubtitle(e.target.value)}
              placeholder="e.g. Master IELTS Band 8.0 essay writing in 8 weeks with Cambridge examiners..."
              className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-hidden"
            />
          </div>

          {saveError && (
            <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
              {saveError}
            </div>
          )}

          <div className="pt-2 flex justify-end gap-2 border-t border-slate-100">
            <button
              type="button"
              disabled={isSaving}
              onClick={onClose}
              className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="px-5 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white font-bold rounded-lg shadow-xs transition-colors"
            >
              {isSaving ? 'Saving Course…' : 'Publish Course to LMS'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
