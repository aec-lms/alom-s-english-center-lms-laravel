import React, { useState } from 'react';
import { LmsProvider, useLms } from './context/LmsContext';
import { PublicNavbar } from './components/layout/PublicNavbar';
import { DashboardSidebar } from './components/layout/DashboardSidebar';
import { DashboardHeader } from './components/layout/DashboardHeader';
import { PublicFooter } from './components/layout/PublicFooter';
import { HeroSection } from './components/public/HeroSection';
import { ExamsPage } from './components/public/ExamsPage';
import { LearnPage } from './components/public/LearnPage';
import { InstructorsPage } from './components/public/InstructorsPage';
import { ProductsPage } from './components/public/ProductsPage';
import { EbooksPage } from './components/public/EbooksPage';
import { CourseCatalogManager } from './components/modules/CourseCatalogManager';
import { StorefrontOrdersManager } from './components/modules/StorefrontOrdersManager';
import { StorefrontCatalogManager } from './components/modules/StorefrontCatalogManager';
import { AdmissionModal } from './components/public/AdmissionModal';
import { CertificateVerifyModal } from './components/public/CertificateVerifyModal';
import { VideoPreviewModal } from './components/public/VideoPreviewModal';
import { AdminOverview } from './components/dashboard/AdminOverview';
import { StudentOverview } from './components/dashboard/StudentOverview';
import { TeacherOverview } from './components/dashboard/TeacherOverview';
import { AccountantOverview } from './components/dashboard/AccountantOverview';
import { GuardianOverview } from './components/dashboard/GuardianOverview';
import { StudentManagement } from './components/modules/StudentManagement';
import { StudentIdCardModal } from './components/modules/StudentIdCardModal';
import { ClassroomViewer } from './components/modules/ClassroomViewer';
import { ExamSimulator } from './components/modules/ExamSimulator';
import { ExamManagement } from './components/modules/ExamManagement';
import { ExamPortal } from './components/modules/ExamPortal';
import { PaymentApiPanel } from './components/modules/PaymentApiPanel';
import { LoginPage } from './components/public/LoginPage';
import { LiveClassManager } from './components/modules/LiveClassManager';
import { AttendanceManager } from './components/modules/AttendanceManager';
import { CertificateManager } from './components/modules/CertificateManager';
import { StudentCertificates } from './components/modules/StudentCertificates';
import { ProfileSettings } from './components/modules/ProfileSettings';
import { CPanelPackageExporter } from './components/modules/CPanelPackageExporter';
import { RoadmapTracker } from './components/modules/RoadmapTracker';
import { DatabaseMigrationManager } from './components/modules/DatabaseMigrationManager';
import { AuthPermissionManager } from './components/modules/AuthPermissionManager';
import { UserManagement } from './components/modules/UserManagement';
import { EnrollmentManagement } from './components/modules/EnrollmentManagement';
import { CmsManager } from './components/modules/CmsManager';
import { SecurityAuditConsole } from './components/modules/SecurityAuditConsole';
import { CourseBuilderModal } from './components/modules/CourseBuilderModal';
import { ContentLibraryManager } from './components/modules/ContentLibraryManager';
import { SiteSettingsManager } from './components/modules/SiteSettingsManager';
import { SuperAdminSaaSPanel } from './components/modules/SuperAdminSaaSPanel';
import { BatchRoutineManager } from './components/modules/BatchRoutineManager';
import { QuestionBankMarksheetManager } from './components/modules/QuestionBankMarksheetManager';
import { CampusFacilitiesManager } from './components/modules/CampusFacilitiesManager';
import { StudentLibraryLoans } from './components/modules/StudentLibraryLoans';
import { LibraryFineManagementPanel } from './components/modules/LibraryFineManagementPanel';
import { FacilityFeeManagementPanel } from './components/modules/FacilityFeeManagementPanel';
import { ReportAnalyticsGenerator } from './components/modules/ReportAnalyticsGenerator';
import { ExpenseManagementPanel } from './components/modules/ExpenseManagementPanel';
import { NotificationCenterModal } from './components/modules/NotificationCenterModal';
import { ConfirmDeleteModal } from './components/common/ConfirmDeleteModal';
import { PluginManager } from './components/modules/PluginManager';
import { RecycleBinManager } from './components/modules/RecycleBinManager';
import { TeacherCommissionManager } from './components/modules/TeacherCommissionManager';
import { MarketingAnalyticsManager } from './components/modules/MarketingAnalyticsManager';
import { NoticeBoardManager } from './components/modules/NoticeBoardManager';
import { PromotionManager } from './components/modules/PromotionManager';
import { MarketingTracking } from './components/public/MarketingTracking';
import { FeaturedPromotions } from './components/public/FeaturedPromotions';
import { EnvatoPackageExporter } from './components/modules/EnvatoPackageExporter';
import { BranchManagement } from './components/modules/BranchManagement';
import { AiAdminCopilot } from './components/modules/AiAdminCopilot';
import { Course, User } from './types/lms';
import heroEducatorImg from './assets/images/hero_educator_studio_1794552233836.jpg';
import ieltsCourseImg from './assets/images/course_ielts_masterclass_1794552235026.jpg';
import spokenCourseImg from './assets/images/course_spoken_fluency_1794552235255.jpg';
import grammarCourseImg from './assets/images/course_grammar_bundle_1794552235221.jpg';
import { filterCoursesByCategory } from './utils/courseCatalog';
import { 
  BookOpen, Star, ArrowRight, Play, CheckCircle2, GraduationCap,
  MapPin, Phone, Mail, Award, Users, ShieldCheck, Download, Plus, Trash2 
} from 'lucide-react';

function LmsApp() {
  const { 
    currentUser, activeView, setActiveView, language, t, isAuthenticated, authLoading, 
    courses, activeExamId, setActiveExamId, activeClassroomId, setActiveClassroomId,
    activeIdCardStudent, setActiveIdCardStudent, deleteCourse, softDeleteCourse, websiteSettings 
  } = useLms();

  const [courseCategoryFilter, setCourseCategoryFilter] = useState<string>('all');

  const [currentDashboardTab, setCurrentDashboardTab] = useState('overview');
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  // Modals
  const [showAdmissionModal, setShowAdmissionModal] = useState(false);
  const [selectedCourseForAdmission, setSelectedCourseForAdmission] = useState<Course | null>(null);
  const [showVerifyModal, setShowVerifyModal] = useState(false);
  const [showVideoModal, setShowVideoModal] = useState(false);
  const [showCourseBuilder, setShowCourseBuilder] = useState(false);
  const [showNotificationModal, setShowNotificationModal] = useState(false);
  const [courseToDelete, setCourseToDelete] = useState<Course | null>(null);

  const handleOpenAdmission = (course?: Course) => {
    setSelectedCourseForAdmission(course || null);
    setShowAdmissionModal(true);
  };

  const handleStartCourse = (courseId: string) => {
    setActiveClassroomId(courseId);
    setActiveView('classroom');
  };

  const handleLaunchExam = (examId?: string) => {
    setActiveExamId(examId || 'ex_ielts_01');
    setActiveView('take_exam');
  };

  const handlePrintIdCard = (student: User) => {
    setActiveIdCardStudent(student);
  };

  // Render Dashboard main contents based on active tab
  const renderDashboardContent = () => {
    switch (currentDashboardTab) {
      case 'overview':
        if (currentUser.role === 'student') {
          return (
            <StudentOverview
              onNavigateTab={setCurrentDashboardTab}
              onContinueCourse={handleStartCourse}
              onTakeExam={handleLaunchExam}
            />
          );
        } else if (currentUser.role === 'teacher' || currentUser.role === 'assistant_teacher') {
          return <TeacherOverview onNavigateTab={setCurrentDashboardTab} />;
        } else if (currentUser.role === 'accountant') {
          return <AccountantOverview onNavigateTab={setCurrentDashboardTab} />;
        } else if (currentUser.role === 'guardian') {
          return <GuardianOverview onNavigateTab={setCurrentDashboardTab} />;
        } else if (currentUser.role === 'branch_manager') {
          return <BranchManagement />;
        } else {
          return <AdminOverview onNavigateTab={setCurrentDashboardTab} />;
        }

      case 'profile':
        return <ProfileSettings />;

      case 'roadmap':
        return <RoadmapTracker onSelectStepTab={setCurrentDashboardTab} />;

      case 'migrations':
        return <DatabaseMigrationManager />;

      case 'permissions':
        return <AuthPermissionManager />;

      case 'users_admin':
        return <UserManagement />;

      case 'branch_management':
      case 'branch_info':
        return <BranchManagement />;

      case 'enrollments_admin':
        return <EnrollmentManagement />;

      case 'cms':
        return currentUser.role === 'admin'
          ? <CmsManager />
          : <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">CMS management permission is required.</div>;

      case 'site_settings':
        return currentUser.role === 'admin'
          ? <SiteSettingsManager />
          : <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">Website settings permission is required.</div>;

      case 'security':
        return <SecurityAuditConsole />;

      case 'students':
        return (
          <StudentManagement
            onPrintIdCard={handlePrintIdCard}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        );

      case 'courses':
        if (currentUser.role !== 'student') {
          return (
            <div className="space-y-6">
              <CourseCatalogManager
                onStartCourse={handleStartCourse}
                onLaunchExam={handleLaunchExam}
                onOpenCreateModal={() => setShowCourseBuilder(true)}
                onDeleteCourse={setCourseToDelete}
              />
              {currentUser.role === 'admin' && <ContentLibraryManager />}
            </div>
          );
        }
      case 'my_courses':
        return (
          <div className="space-y-6">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
              <div>
                <h2 className="text-lg font-bold text-slate-900 leading-tight">
                  {currentUser.role === 'student' ? 'My Enrolled Courses' : 'Course Management & Curriculum'}
                </h2>
                <p className="text-xs text-slate-500 mt-0.5">
                  Cambridge & British Council aligned syllabus with video lectures and resources
                </p>
              </div>

              {currentUser.role !== 'student' && (
                <button
                  onClick={() => setShowCourseBuilder(true)}
                  className="flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-xs transition-colors self-start sm:self-auto"
                >
                  <Plus className="w-4 h-4" />
                  <span>Create New Course</span>
                </button>
              )}
            </div>

            <FeaturedPromotions placement="branch_directory" />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {courses.map(course => (
                <div key={course.id} className="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-2xs flex flex-col justify-between">
                  <div className="relative h-48 bg-slate-900">
                    <img src={course.thumbnail} alt={course.title} className="w-full h-full object-cover" />
                    <span className="absolute top-3 left-3 px-2.5 py-1 rounded bg-blue-600 text-white font-bold text-xs uppercase tracking-wider">
                      {course.category}
                    </span>
                  </div>

                  <div className="p-6 space-y-4">
                    <div>
                      <h3 className="font-bold text-base text-slate-900">{course.title}</h3>
                      <p className="text-xs text-slate-500 mt-1">{course.subtitle}</p>
                    </div>

                    <div className="pt-2 border-t border-slate-100 flex items-center justify-between">
                      <div className="text-xs text-slate-500">
                        Instructor: <strong className="text-slate-800">{course.instructorName}</strong>
                      </div>
                      <div className="flex items-center gap-2">
                        {currentUser.role !== 'student' && (
                          <button
                            onClick={() => setCourseToDelete(course)}
                            className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg border border-transparent hover:border-rose-200 transition-colors cursor-pointer"
                            title="Delete Course (With Confirmation)"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        )}
                        <button
                          onClick={() => handleStartCourse(course.id)}
                          className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-xs flex items-center gap-1.5 transition-colors cursor-pointer"
                        >
                          <Play className="w-3.5 h-3.5 fill-white" />
                          <span>Launch Classroom</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            {(currentUser.role === 'admin') && <ContentLibraryManager />}
          </div>
        );

      case 'online_exam':
      case 'exams':
        return currentUser.role === 'admin'
          ? <ExamManagement />
          : <ExamPortal onStartExam={handleLaunchExam} />;

      case 'my_results':
        return <ExamPortal onStartExam={handleLaunchExam} showResults />;

      case 'my_library':
        return <StudentLibraryLoans />;

      case 'my_facility_fees':
      case 'facility_fees':
        return <FacilityFeeManagementPanel />;

      case 'live_classes':
        return <LiveClassManager />;

      case 'payments':
      case 'my_payments':
        return <PaymentApiPanel />;

      case 'expenses':
        return <ExpenseManagementPanel />;

      case 'library_fines':
        return <LibraryFineManagementPanel />;

      case 'attendance':
      case 'ward_attendance':
        return <AttendanceManager />;

      case 'certificates':
        return currentUser.role === 'student' ? <StudentCertificates /> : <CertificateManager />;

      case 'enrollments':
        return <EnrollmentManagement />;

      case 'saas_superadmin':
        return <SuperAdminSaaSPanel />;

      case 'batches_routine':
        return <BatchRoutineManager />;

      case 'question_bank_marksheets':
        return <QuestionBankMarksheetManager />;

      case 'facilities':
        return <CampusFacilitiesManager />;

      case 'reports':
      case 'reports_analytics':
        return <ReportAnalyticsGenerator />;

      case 'cpanel_export':
        return <CPanelPackageExporter />;

      case 'plugins':
        return <PluginManager />;

      case 'ai_admin':
        return (currentUser.role === 'admin')
          ? <AiAdminCopilot />
          : <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">Administrator permission is required.</div>;

      case 'recycle_bin':
        return <RecycleBinManager />;

      case 'teacher_commissions':
        return <TeacherCommissionManager />;

      case 'marketing_analytics':
        return <MarketingAnalyticsManager />;

      case 'notices':
        return <NoticeBoardManager />;

      case 'promotions':
        return currentUser.role === 'admin' ? <PromotionManager /> : <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">Admin permission is required.</div>;

      case 'envato_packages':
        return <EnvatoPackageExporter />;

      case 'id_card':
        return (
          <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
            <h3 className="text-base font-bold text-slate-900">Student Identity Card</h3>
            <p className="text-xs text-slate-500">Official student identity card for Rafiq Hasan</p>
            <button
              onClick={() => handlePrintIdCard(currentUser)}
              className="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs"
            >
              Open Printable ID Card
            </button>
          </div>
        );

      default:
        return <AdminOverview onNavigateTab={setCurrentDashboardTab} />;
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-slate-50 text-slate-800 antialiased selection:bg-blue-600 selection:text-white">
      
      {/* 1. PUBLIC WEBSITE VIEWS */}
      {activeView === 'home' && (
        <>
          <PublicNavbar
            onOpenAdmission={() => handleOpenAdmission()}
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenVideoPreview={() => setShowVideoModal(true)}
          />
          <main className="flex-1">
            <HeroSection
              onOpenAdmission={handleOpenAdmission}
              onOpenVideoPreview={() => setShowVideoModal(true)}
              onSelectCourse={handleOpenAdmission}
              onOpenVerifyModal={() => setShowVerifyModal(true)}
              onSelectCategory={cat => {
                setCourseCategoryFilter(cat);
                setActiveView('courses');
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
            />
            <FeaturedPromotions placement="homepage_featured" />
            <FeaturedPromotions placement="notice_banner" />
          </main>
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {/* 2. PUBLIC COURSES PAGE */}
      {activeView === 'courses' && (() => {
        const theme = websiteSettings.themeSettings;
        const canvasBg = `linear-gradient(135deg, ${theme?.lightCanvasStart || '#EAE9FF'} 0%, ${theme?.lightCanvasMid || '#F2F3FF'} 45%, ${theme?.lightCanvasEnd || '#E5F4FE'} 100%)`;
        const categories = Array.from(new Set(courses.map(c => c.category).filter(Boolean)));
        const filteredCourses = filterCoursesByCategory(courses, courseCategoryFilter);
        const resolveCourseImg = (url?: string, cat = '') => {
          if (url && !url.includes('images.unsplash.com') && !url.includes('placeholder')) return url;
          const lower = cat.toLowerCase();
          if (lower.includes('spoken') || lower.includes('phonetics')) return spokenCourseImg;
          if (lower.includes('grammar') || lower.includes('writing')) return grammarCourseImg;
          if (lower.includes('ielts')) return ieltsCourseImg;
          return heroEducatorImg;
        };

        return (
          <>
            <PublicNavbar
              onOpenAdmission={() => handleOpenAdmission()}
              onOpenVerifyModal={() => setShowVerifyModal(true)}
              onOpenVideoPreview={() => setShowVideoModal(true)}
            />
            <main className="flex-1 py-12 sm:py-14 px-4 sm:px-6 w-full" style={{ background: canvasBg }}>
              <div className="max-w-[1200px] mx-auto space-y-8">
                <div className="text-center max-w-2xl mx-auto space-y-3">
                  <div className="inline-flex items-center justify-center gap-3">
                    <span className="w-11 h-11 rounded-xl bg-gradient-to-br from-[#2563EB] to-[#4F46E5] text-white flex items-center justify-center shadow-md shadow-[#2563EB]/25">
                      <GraduationCap className="w-5 h-5" />
                    </span>
                    <h1 className="font-heading text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                      {language === 'bn' ? (
                        <span>আমাদের সকল <span className="text-[#24306E]">কোর্সসমূহ</span></span>
                      ) : (
                        <span>All <span className="text-[#24306E]">Courses</span></span>
                      )}
                    </h1>
                  </div>
                  <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    {language === 'bn'
                      ? 'দক্ষ ও অভিজ্ঞ মেন্টরদের তত্ত্বাবধানে পরিচালিত লাইভ ও রেকর্ডেড ব্যাচসমূহে এখনই যুক্ত হোন।'
                      : 'Explore our structured live and recorded academic programs led by certified mentors.'}
                  </p>

                  {/* Category Filter Pills */}
                  <div className="pt-2 flex flex-wrap items-center justify-center gap-2">
                    <button
                      type="button"
                      onClick={() => setCourseCategoryFilter('all')}
                      className={`px-4 py-1.5 rounded-full text-xs font-bold inline-flex items-center gap-2 transition-all cursor-pointer ${
                        courseCategoryFilter === 'all'
                          ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md'
                          : 'bg-white text-slate-700 border border-slate-200 hover:border-blue-300'
                      }`}
                    >
                      <span>{language === 'bn' ? 'সকল কোর্স' : 'All Courses'}</span>
                      <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-mono ${courseCategoryFilter === 'all' ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-600'}`}>
                        {courses.length}
                      </span>
                    </button>
                    {categories.map(cat => {
                      const active = courseCategoryFilter.toLowerCase() === cat.toLowerCase();
                      const count = courses.filter(c => c.category.toLowerCase() === cat.toLowerCase()).length;
                      return (
                        <button
                          key={cat}
                          type="button"
                          onClick={() => setCourseCategoryFilter(cat)}
                          className={`px-4 py-1.5 rounded-full text-xs font-semibold inline-flex items-center gap-2 transition-all cursor-pointer ${
                            active
                              ? 'bg-gradient-to-r from-[#2563EB] to-[#4F46E5] text-white shadow-md font-bold'
                              : 'bg-white text-slate-600 border border-slate-200 hover:border-blue-300'
                          }`}
                        >
                          <span>{cat}</span>
                          <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-mono ${active ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500'}`}>
                            {count}
                          </span>
                        </button>
                      );
                    })}
                  </div>
                </div>

                <FeaturedPromotions placement="course_listing" />

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                  {filteredCourses.map(course => (
                    <article key={course.id} className="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-xs hover:shadow-xl hover:-translate-y-1 transition-all duration-200 flex flex-col">
                      <div className="relative h-48 bg-[#022D41] overflow-hidden">
                        <img
                          src={resolveCourseImg(course.thumbnail, course.category)}
                          alt={course.title}
                          onError={e => { e.currentTarget.src = heroEducatorImg; }}
                          className="w-full h-full object-cover"
                        />
                        <span className="absolute top-3 left-3 px-2.5 py-1 rounded-full bg-[#0284C7] text-white font-bold text-[10px] uppercase tracking-wider shadow-sm">
                          {course.category}
                        </span>
                        <span className="absolute bottom-3 right-3 px-2.5 py-1 rounded-full bg-slate-950/80 text-amber-300 font-bold text-[10px]">
                          {course.durationWeeks} Weeks
                        </span>
                      </div>

                      <div className="p-5 flex-1 flex flex-col justify-between space-y-4">
                        <div className="space-y-1.5">
                          <h3 className="font-heading font-extrabold text-base text-slate-900 leading-snug line-clamp-2">{course.title}</h3>
                          <p className="text-xs text-slate-500 leading-relaxed line-clamp-2">{course.description}</p>
                        </div>

                        <div className="bg-slate-50/90 p-3.5 rounded-xl border border-slate-200/70 space-y-1.5 text-[11px]">
                          <span className="font-bold text-slate-800 block">Curriculum Highlights:</span>
                          <ul className="space-y-1 text-slate-600">
                            {course.syllabus.slice(0, 3).map((s, i) => (
                              <li key={i} className="truncate"><strong>W{s.week}:</strong> {s.title}</li>
                            ))}
                          </ul>
                        </div>

                        <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                          <div>
                            <div className="text-lg font-extrabold font-mono text-[#0284C7]">
                              {t.currency}{course.price.toLocaleString()}
                            </div>
                            {course.originalPrice > course.price && (
                              <div className="text-[11px] font-mono text-slate-400 line-through">
                                {t.currency}{course.originalPrice.toLocaleString()}
                              </div>
                            )}
                          </div>

                          <div className="flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => handleStartCourse(course.id)}
                              className="px-3 py-2 bg-[#EFF6FF] hover:bg-blue-100 text-[#0284C7] font-bold rounded-lg text-[11px] transition-colors cursor-pointer"
                            >
                              Details
                            </button>
                            <button
                              type="button"
                              onClick={() => handleOpenAdmission(course)}
                              className="px-3.5 py-2 bg-[#0284C7] hover:bg-[#0369A1] text-white font-bold rounded-lg text-[11px] shadow-xs transition-colors cursor-pointer"
                            >
                              Enroll Now
                            </button>
                          </div>
                        </div>
                      </div>
                    </article>
                  ))}
                </div>
              </div>
            </main>
            <PublicFooter
              onOpenVerifyModal={() => setShowVerifyModal(true)}
              onOpenAdmission={() => handleOpenAdmission()}
            />
          </>
        );
      })()}

      {/* 3. PUBLIC EXAMS, LEARN, INSTRUCTORS, PRODUCTS, EBOOKS */}
      {activeView === 'exams' && (
        <>
          <PublicNavbar onOpenAdmission={() => handleOpenAdmission()} onOpenVerifyModal={() => setShowVerifyModal(true)} />
          <ExamsPage onLaunchExam={handleLaunchExam} />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {activeView === 'learn' && (
        <>
          <PublicNavbar onOpenAdmission={() => handleOpenAdmission()} onOpenVerifyModal={() => setShowVerifyModal(true)} />
          <LearnPage />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {activeView === 'instructors' && (
        <>
          <PublicNavbar onOpenAdmission={() => handleOpenAdmission()} onOpenVerifyModal={() => setShowVerifyModal(true)} />
          <InstructorsPage />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {activeView === 'products' && (
        <>
          <PublicNavbar onOpenAdmission={() => handleOpenAdmission()} onOpenVerifyModal={() => setShowVerifyModal(true)} />
          <ProductsPage />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {activeView === 'ebooks' && (
        <>
          <PublicNavbar onOpenAdmission={() => handleOpenAdmission()} onOpenVerifyModal={() => setShowVerifyModal(true)} />
          <EbooksPage />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {activeView === 'about' && (
        <>
          <PublicNavbar
            onOpenAdmission={() => handleOpenAdmission()}
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenVideoPreview={() => setShowVideoModal(true)}
          />
          <main className="flex-1 py-16 px-4 sm:px-8 max-w-5xl mx-auto space-y-12">
            <div className="text-center space-y-3">
              <span className="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-bold uppercase tracking-wider">
                About Alom's English Center
              </span>
              <h1 className="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">
                Empowering Bangladesh with Confident English Fluency
              </h1>
              <p className="text-base text-slate-600 max-w-2xl mx-auto leading-relaxed">
                Founded by Engr. Mahbubul Alom, Alom's English Center has grown into one of Bangladesh's most trusted institutes for Cambridge IELTS, spoken confidence, and career-defining English communication.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center bg-white p-8 rounded-2xl border border-slate-200 shadow-xs">
              <img
                src={heroEducatorImg}
                alt="Alom's English Center Classroom"
                className="rounded-xl w-full h-72 object-cover"
              />
              <div className="space-y-4">
                <h3 className="text-xl font-bold text-slate-900">Why Students Choose Alom Sir</h3>
                <ul className="space-y-2.5 text-xs text-slate-600">
                  <li className="flex items-start gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                    <span><strong>100% Practical Speaking:</strong> No translation memory, active speaking drills from Day 1.</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                    <span><strong>Cambridge & IDP Certified Mentors:</strong> All lead trainers have achieved IELTS Band 8.5+.</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                    <span><strong>Multi-Campus & Online:</strong> Mirpur 10, Dhanmondi, Chittagong, Sylhet, and live global Zoom/Meet.</span>
                  </li>
                </ul>
              </div>
            </div>
          </main>
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {/* 4. PUBLIC CONTACT PAGE */}
      {activeView === 'contact' && (
        <>
          <PublicNavbar
            onOpenAdmission={() => handleOpenAdmission()}
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenVideoPreview={() => setShowVideoModal(true)}
          />
          <main className="flex-1 py-16 px-4 sm:px-8 max-w-5xl mx-auto space-y-10">
            <div className="text-center space-y-2">
              <h1 className="text-3xl font-extrabold text-slate-900 tracking-tight">
                Contact & Campus Helplines
              </h1>
              <p className="text-sm text-slate-500">
                Visit our campuses or reach our 24/7 admission desk via call / WhatsApp.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                <h3 className="font-bold text-base text-slate-900 flex items-center gap-2">
                  <MapPin className="w-5 h-5 text-blue-600" />
                  <span>Dhaka Main Campus (Mirpur 10)</span>
                </h3>
                <p className="text-xs text-slate-600 leading-relaxed">
                  Plot 14, Sector 6, Mirpur 10 Roundabout (Opposite to Fire Service), Dhaka-1216.
                </p>
                <div className="space-y-1 text-xs text-slate-600 font-mono">
                  <div>Hotline: +880 1712 345678</div>
                  <div>WhatsApp: +880 1711 001122</div>
                </div>
              </div>

              <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-2xs space-y-4">
                <h3 className="font-bold text-base text-slate-900 flex items-center gap-2">
                  <MapPin className="w-5 h-5 text-blue-600" />
                  <span>Chittagong Branch (GEC Circle)</span>
                </h3>
                <p className="text-xs text-slate-600 leading-relaxed">
                  CDA Avenue, GEC Circle (Adjacent to Sanmar Ocean City), Chattogram.
                </p>
                <div className="space-y-1 text-xs text-slate-600 font-mono">
                  <div>Hotline: +880 1819 556677</div>
                </div>
              </div>
            </div>
          </main>
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}

      {/* 5. INTERACTIVE CLASSROOM VIEW */}
      {activeView === 'classroom' && isAuthenticated && (
        <div className="min-h-screen bg-slate-100 flex flex-col">
          <DashboardHeader title="Interactive Virtual Classroom" />
          <div className="flex-1 p-4 sm:p-8 max-w-7xl mx-auto w-full">
            <ClassroomViewer
              courseId={activeClassroomId || 'crs_ielts'}
              onBack={() => setActiveView('dashboard')}
            />
          </div>
        </div>
      )}

      {/* 6. ONLINE EXAM SIMULATOR VIEW */}
      {activeView === 'take_exam' && isAuthenticated && (
        <div className="min-h-screen bg-slate-100 flex flex-col">
          <DashboardHeader title="Online Examination Engine" />
          <div className="flex-1 p-4 sm:p-8 max-w-7xl mx-auto w-full">
            <ExamSimulator
              examId={activeExamId || 'ex_ielts_01'}
              onExit={() => setActiveView('dashboard')}
            />
          </div>
        </div>
      )}

      {((['dashboard', 'classroom', 'take_exam'].includes(activeView) && !isAuthenticated && !authLoading) || activeView === 'login') && (
        <>
          <PublicNavbar
            onOpenAdmission={() => handleOpenAdmission()}
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenVideoPreview={() => setShowVideoModal(true)}
          />
          <LoginPage />
          <PublicFooter
            onOpenVerifyModal={() => setShowVerifyModal(true)}
            onOpenAdmission={() => handleOpenAdmission()}
          />
        </>
      )}
      {authLoading && ['dashboard', 'classroom', 'take_exam'].includes(activeView) && <div className="flex min-h-screen items-center justify-center text-sm text-slate-500">Checking secure session…</div>}

      {activeView === 'dashboard' && isAuthenticated && (
        <div className="flex min-h-screen bg-slate-100">
          {/* Sidebar */}
          <DashboardSidebar
            currentTab={currentDashboardTab}
            setCurrentTab={setCurrentDashboardTab}
            isOpenMobile={isMobileMenuOpen}
            onCloseMobile={() => setIsMobileMenuOpen(false)}
          />

          {/* Main Content Area */}
          <div className="flex-1 flex flex-col min-w-0">
            <DashboardHeader
              onToggleMobileMenu={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
              onOpenNotifications={() => setShowNotificationModal(true)}
            />

            <main className="flex-1 p-4 sm:p-8 overflow-y-auto">
              <div className="max-w-7xl mx-auto">
                {renderDashboardContent()}
              </div>
            </main>
          </div>
        </div>
      )}

      <MarketingTracking pageKey={activeView} />

      {/* GLOBAL MODALS */}
      <NotificationCenterModal
        isOpen={showNotificationModal}
        onClose={() => setShowNotificationModal(false)}
        onNavigateTab={(tab) => {
          setCurrentDashboardTab(tab);
          setShowNotificationModal(false);
        }}
      />

      <AdmissionModal
        isOpen={showAdmissionModal}
        onClose={() => setShowAdmissionModal(false)}
        preSelectedCourse={selectedCourseForAdmission}
      />

      <CertificateVerifyModal
        isOpen={showVerifyModal}
        onClose={() => setShowVerifyModal(false)}
      />

      <VideoPreviewModal
        isOpen={showVideoModal}
        onClose={() => setShowVideoModal(false)}
      />

      <StudentIdCardModal
        isOpen={Boolean(activeIdCardStudent)}
        onClose={() => setActiveIdCardStudent(null)}
        student={activeIdCardStudent}
      />

      <CourseBuilderModal
        isOpen={showCourseBuilder}
        onClose={() => setShowCourseBuilder(false)}
      />

      {/* Confirmation Modal to Prevent Accidental Course Deletion */}
      <ConfirmDeleteModal
        isOpen={Boolean(courseToDelete)}
        onClose={() => setCourseToDelete(null)}
        onConfirm={async () => {
          if (!courseToDelete) return false;
          const archived = await softDeleteCourse(courseToDelete.id);
          if (archived) setCourseToDelete(null);
          return archived;
        }}
        title="Confirm Course Archival"
        titleBn="কোর্স মুছে ফেলার নিশ্চিতকরণ"
        itemName={courseToDelete ? `${courseToDelete.title} (${courseToDelete.category})` : ''}
        message="Are you sure you want to move this course to the Recycle Bin? It will be safely retained in the Recycle Bin for 30 days, where you can restore it anytime with complete syllabus, lessons, and progress intact."
        messageBn="আপনি কি নিশ্চিতভাবে এই কোর্সটি রিসাইকেল বিনে সরাতে চান? এটি পরবর্তী ৩০ দিন রিসাইকেল বিনে সুরক্ষিত থাকবে এবং যেকোনো সময় সম্পূর্ণ সিলেবাস ও লেসনসহ পুনরুদ্ধার করা যাবে।"
        confirmText="Yes, Move to Recycle Bin"
        confirmTextBn="হ্যাঁ, রিসাইকেল বিনে সরান"
        requireTyping={false}
        language={language}
      />

    </div>
  );
}

export default function App() {
  return (
    <LmsProvider>
      <LmsApp />
    </LmsProvider>
  );
}
