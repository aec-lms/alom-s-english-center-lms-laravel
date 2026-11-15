import crypto from 'node:crypto';
import express from 'express';
import type { Request, Response, NextFunction } from 'express';

interface UserRow {
  id: number;
  name: string;
  email: string;
  phone: string;
  password: string;
  role: string;
  branch_id: number | null;
  is_active: boolean;
  email_verified_at: string | null;
  phone_verified_at: string | null;
  created_at: string;
}

interface SessionData {
  userId: number;
  csrfToken: string;
}

function parseCookies(cookieHeader: string | undefined): Record<string, string> {
  const cookies: Record<string, string> = {};
  if (!cookieHeader) return cookies;
  for (const part of cookieHeader.split(';')) {
    const idx = part.indexOf('=');
    if (idx === -1) continue;
    const key = part.slice(0, idx).trim();
    const val = decodeURIComponent(part.slice(idx + 1).trim());
    if (key) cookies[key] = val;
  }
  return cookies;
}

function ok(res: Response, data: unknown = null, message?: string, status = 200) {
  return res.status(status).json(message ? { success: true, data, message } : { success: true, data });
}

function fail(res: Response, message: string, status = 400) {
  return res.status(status).json({ success: false, message, error: message });
}

export function createMockApiRouter() {
  const router = express.Router();
  router.use(express.json({ limit: '5mb' }));
  router.use(express.urlencoded({ extended: true }));

  const sessions = new Map<string, SessionData>();

  // --- Seeded in-memory LMS state ---
  let nextId = 100;
  const genId = () => ++nextId;

  const branches = [
    { id: 1, code: 'DHK-01', name: 'Dhaka Main Campus (Mirpur 10)', city: 'Dhaka', address: 'Plot 14, Sector 6, Mirpur 10 Roundabout, Dhaka-1216', phone: '+880 1712 345678', is_active: 1 },
    { id: 2, code: 'DHK-02', name: 'Dhanmondi Executive Branch', city: 'Dhaka', address: 'House 28, Road 7/A, Dhanmondi, Dhaka', phone: '+880 1712 345679', is_active: 1 },
    { id: 3, code: 'CTG-01', name: 'Chittagong Branch (GEC Circle)', city: 'Chittagong', address: 'CDA Avenue, GEC Circle, Chattogram', phone: '+880 1819 556677', is_active: 1 },
    { id: 4, code: 'SYL-01', name: 'Sylhet Branch (Zindabazar)', city: 'Sylhet', address: 'Al-Hamra Tower, 4th Floor, Zindabazar, Sylhet', phone: '+880 1711 778899', is_active: 1 },
  ];

  const users: UserRow[] = [
    { id: 1, name: 'Shamim Reza', email: 'admin@alomsenglish.com', phone: '+880 1712 345678', password: '', role: 'admin', branch_id: 1, is_active: true, email_verified_at: '2025-01-01 09:00:00', phone_verified_at: '2025-01-01 09:00:00', created_at: '2025-01-01 09:00:00' },
    { id: 2, name: 'Engr. Mahbubul Alom', email: 'alom.director@alomsenglish.com', phone: '+880 1711 001122', password: '', role: 'admin', branch_id: 1, is_active: true, email_verified_at: '2025-01-01 09:00:00', phone_verified_at: '2025-01-01 09:00:00', created_at: '2025-01-01 09:00:00' },
    { id: 3, name: 'Farhana Yasmin', email: 'ctg.manager@alomsenglish.com', phone: '+880 1819 556677', password: '', role: 'branch_manager', branch_id: 3, is_active: true, email_verified_at: '2025-01-02 09:00:00', phone_verified_at: null, created_at: '2025-01-02 09:00:00' },
    { id: 4, name: 'Tanvir Ahmed (IELTS 8.5)', email: 'tanvir.ielts@alomsenglish.com', phone: '+880 1914 223344', password: '', role: 'teacher', branch_id: 1, is_active: true, email_verified_at: '2025-01-03 09:00:00', phone_verified_at: null, created_at: '2025-01-03 09:00:00' },
    { id: 5, name: 'Nusrat Jahan', email: 'nusrat.phonetics@alomsenglish.com', phone: '+880 1612 889900', password: '', role: 'assistant_teacher', branch_id: 1, is_active: true, email_verified_at: '2025-01-04 09:00:00', phone_verified_at: null, created_at: '2025-01-04 09:00:00' },
    { id: 6, name: 'Kamrul Islam, ACCA', email: 'accounts@alomsenglish.com', phone: '+880 1715 667788', password: '', role: 'accountant', branch_id: 1, is_active: true, email_verified_at: '2025-01-05 09:00:00', phone_verified_at: null, created_at: '2025-01-05 09:00:00' },
    { id: 7, name: 'Rafiq Hasan', email: 'rafiq.hasan2025@gmail.com', phone: '+880 1723 998877', password: '', role: 'student', branch_id: 1, is_active: true, email_verified_at: '2025-01-05 10:00:00', phone_verified_at: '2025-01-05 10:05:00', created_at: '2025-01-05 10:00:00' },
    { id: 8, name: 'Abdul Halim (Father of Rafiq)', email: 'abdul.halim.dhaka@gmail.com', phone: '+880 1711 445566', password: '', role: 'guardian', branch_id: 1, is_active: true, email_verified_at: '2025-01-06 10:00:00', phone_verified_at: null, created_at: '2025-01-06 10:00:00' },
  ];

  const categories = [
    { id: 1, name: 'IELTS', name_bn: 'আইইএলটিএস', slug: 'ielts', description: 'Cambridge IELTS Academic and General Training preparation', color: 'blue', sort_order: 1, is_active: 1 },
    { id: 2, name: 'Spoken', name_bn: 'স্পোকেন ইংলিশ', slug: 'spoken', description: 'Fluency, pronunciation, and real-life conversation', color: 'emerald', sort_order: 2, is_active: 1 },
    { id: 3, name: 'Grammar', name_bn: 'গ্রামার', slug: 'grammar', description: 'Sentence structure, tenses, and error correction', color: 'indigo', sort_order: 3, is_active: 1 },
    { id: 4, name: 'Writing', name_bn: 'রাইটিং', slug: 'writing', description: 'Academic essays, reports, and formal emails', color: 'purple', sort_order: 4, is_active: 1 },
    { id: 5, name: 'Kids', name_bn: 'কিডস ইংলিশ', slug: 'kids', description: 'Phonics and storytelling for young learners', color: 'amber', sort_order: 5, is_active: 1 },
    { id: 6, name: 'Corporate', name_bn: 'কর্পোরেট ইংলিশ', slug: 'corporate', description: 'Executive communication and business presentations', color: 'sky', sort_order: 6, is_active: 1 },
  ];

  const courses = [
    {
      id: 1, category_id: 1, branch_id: 1, category_name: 'IELTS',
      title: 'IELTS Preparation Masterclass', slug: 'ielts-preparation-masterclass',
      subtitle: 'Get your desired band 7.5+ in 12 weeks with Cambridge certified mentors',
      description: 'Comprehensive training for Academic & General Training modules. Covers Listening, Reading, Writing Task 1 & 2, and Speaking with live one-on-one mock interviews and personalized feedback.',
      price: 8500, original_price: 12000, level: 'Intermediate', duration_weeks: 12,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-10 09:00:00',
      syllabus: [
        { week: 1, title: 'Orientation & Diagnostic Assessment', topics: ['Exam Format Deep-dive', 'Baseline Mock Test'] },
        { week: 2, title: 'Listening & Reading Speed Hacks', topics: ['Map Labeling', 'True/False/Not Given'] },
        { week: 3, title: 'Writing Task 1 & 2 + Speaking Mock', topics: ['Data Synthesis', 'Band 8 Essay Structure'] },
      ],
    },
    {
      id: 2, category_id: 2, branch_id: 1, category_name: 'Spoken',
      title: 'Spoken English & Fluency Bootcamp', slug: 'spoken-english-fluency-bootcamp',
      subtitle: 'Speak English fluently, confidently, and naturally without translation hesitation',
      description: 'Learn practical real-life English conversation, British phonetics, connected speech, active vocabulary, and interview skills with live speaking partner sessions.',
      price: 4500, original_price: 6500, level: 'Beginner', duration_weeks: 8,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-12 09:00:00',
      syllabus: [
        { week: 1, title: 'Breaking Hesitation & Fluency Frameworks', topics: ['Think in English', 'Everyday Starters'] },
        { week: 2, title: 'British Phonetics & Connected Speech', topics: ['Clear Consonant Sounds', 'Natural Intonation'] },
        { week: 3, title: 'Real-Life Interviews & Roleplay', topics: ['Debate Clubs', 'Presentation Confidence'] },
      ],
    },
    {
      id: 3, category_id: 3, branch_id: 1, category_name: 'Grammar',
      title: 'Grammar Foundation & Error Correction', slug: 'grammar-foundation-error-correction',
      subtitle: 'Master complete English grammar logically without memorizing dry rules',
      description: 'Eliminate common grammatical mistakes made by Bengali speakers. Tenses, subject-verb agreement, modifiers, clauses, prepositions, and active sentence creation.',
      price: 3500, original_price: 5000, level: 'All Levels', duration_weeks: 6,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-14 09:00:00',
      syllabus: [
        { week: 1, title: 'Sentence Architecture & Tense Timeline', topics: ['Active vs Passive', 'Time Markers'] },
        { week: 2, title: 'Agreement, Clauses & Modifiers', topics: ['Complex Sentences', 'Parallelism'] },
        { week: 3, title: 'Academic & Workplace Editing Clinic', topics: ['Preposition Patterns', 'Punctuation'] },
      ],
    },
    {
      id: 4, category_id: 4, branch_id: 1, category_name: 'Writing',
      title: 'Advanced Writing Skills & Composition', slug: 'advanced-writing-skills-composition',
      subtitle: 'Write high-scoring professional reports, academic essays, and formal emails',
      description: 'Structure complex ideas into coherent, grammatically sophisticated paragraphs with rich academic vocabulary and transition devices.',
      price: 5000, original_price: 7000, level: 'Advanced', duration_weeks: 8,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-16 09:00:00',
      syllabus: [
        { week: 1, title: 'Paragraph Unity & Topic Sentences', topics: ['Cohesive Devices', 'Academic Tone'] },
        { week: 2, title: 'Persuasive Essays & Data Reports', topics: ['Counter-arguments', 'Executive Summaries'] },
        { week: 3, title: 'Line-by-Line Editorial Workshop', topics: ['Lexical Upgrade', 'Concise Editing'] },
      ],
    },
    {
      id: 5, category_id: 5, branch_id: 1, category_name: 'Kids',
      title: 'Phonics & Spoken English for Young Learners', slug: 'phonics-spoken-english-kids',
      subtitle: 'Interactive English foundation for school students aged 6-12',
      description: 'Fun audio-visual lessons teaching early phonemic awareness, joyful rhymes, story reading, and basic conversational etiquette.',
      price: 3200, original_price: 4500, level: 'Beginner', duration_weeks: 6,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-18 09:00:00',
      syllabus: [
        { week: 1, title: '44 English Sounds & Blending Games', topics: ['Short & Long Vowels', 'Sight Words'] },
        { week: 2, title: 'Guided Story Reading & Retelling', topics: ['Expression', 'Picture Descriptions'] },
        { week: 3, title: 'School Presentation & Show-and-Tell', topics: ['Polite Expressions', 'Confidence Games'] },
      ],
    },
    {
      id: 6, category_id: 6, branch_id: 1, category_name: 'Corporate',
      title: 'Corporate Executive Business English & Pitching', slug: 'corporate-executive-business-english',
      subtitle: 'Professional English for executives, sales pitches, negotiations, and global meetings',
      description: 'Elevate leadership communication: formal boardroom presentations, client email drafting, negotiation tactics, and international business etiquette.',
      price: 9500, original_price: 15000, level: 'Advanced', duration_weeks: 4,
      thumbnail: '', status: 'published', deleted_at: null as string | null, created_at: '2025-01-20 09:00:00',
      syllabus: [
        { week: 1, title: 'Boardroom Briefings & Stake-holder Pitches', topics: ['Executive Opening', 'Q&A Handling'] },
        { week: 2, title: 'High-Impact Emails & Proposals', topics: ['Persuasive Requests', 'Client Diplomacy'] },
        { week: 3, title: 'Cross-Cultural Negotiation Lab', topics: ['Diplomatic Disagreement', 'Closing Deals'] },
      ],
    },
  ];

  const contentItems = [
    { id: 1, title: 'IELTS Format Deep-Dive & Band Score Strategy', title_bn: 'আইইএলটিএস ফরম্যাট ও ব্যান্ড স্কোর কৌশল', content_type: 'lesson', body: 'Learn how Listening, Reading, Writing, and Speaking are scored from Band 5.0 to 9.0, and discover the fastest ways to avoid common scoring traps.', media_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', duration_minutes: 18, status: 'published', is_public: 1 },
    { id: 2, title: 'Speaking Without Mental Translation', title_bn: 'মনে মনে অনুবাদ ছাড়াই ইংরেজিতে কথা বলার কৌশল', content_type: 'lesson', body: 'Practice thinking in English sentence chunks and daily conversational templates for immediate speaking confidence.', media_url: '', duration_minutes: 15, status: 'published', is_public: 1 },
    { id: 3, title: 'Tenses & Subject-Verb Agreement Master Guide', title_bn: 'টেন্স ও সাবজেক্ট-ভার্ব এগ্রিমেন্ট গাইড', content_type: 'lesson', body: 'Eliminate the top 10 grammatical mistakes in IELTS Writing and formal emails with clear side-by-side examples.', media_url: '', duration_minutes: 22, status: 'published', is_public: 1 },
    { id: 4, title: 'Academic Writing Task 2 Essay Blueprint', title_bn: 'রাইটিং টাস্ক ২ এসে ব্লুপ্রিন্ট', content_type: 'lesson', body: 'Build Band 8 thesis statements, cohesive body paragraphs, and balanced conclusions under exam time pressure.', media_url: '', duration_minutes: 25, status: 'published', is_public: 1 },
  ];

  const courseContentLinks = [
    { course_id: 1, content_item_id: 1, sort_order: 1, is_preview: 1 },
    { course_id: 1, content_item_id: 4, sort_order: 2, is_preview: 1 },
    { course_id: 2, content_item_id: 2, sort_order: 1, is_preview: 1 },
    { course_id: 3, content_item_id: 3, sort_order: 1, is_preview: 1 },
  ];

  const lessonProgress: Array<{ user_id: number; course_id: number; content_item_id: number; is_completed: number; notes: string; updated_at: string }> = [];

  let websiteSettings: Record<string, unknown> = {};
  let marketingSettings: Record<string, string> = {
    googleAnalyticsId: '',
    googleTagManagerId: '',
    metaPixelId: '',
    googleSearchConsoleToken: '',
    siteTitleSeo: "Alom's English Center | Learn with Purpose",
    siteMetaDescription: "Alom's English Center — structured English learning, expert guidance, practical assessments and measurable progress.",
    canonicalUrl: '',
    robotsTxtContent: 'User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/',
  };

  const cmsPosts = [
    {
      id: 1,
      title: 'How to Move from Band 6.0 to 7.5 in IELTS Writing',
      titleBn: 'আইইএলটিএস রাইটিংয়ে ব্যান্ড ৬.০ থেকে ৭.৫ পাওয়ার কৌশল',
      slug: 'ielts-writing-band-6-to-75',
      excerpt: 'Focus on task response, lexical precision, and cohesive paragraphing instead of memorizing complex templates.',
      content: 'Moving from Band 6.0 to 7.5 requires addressing every part of the prompt directly, developing arguments with concrete examples, and using natural collocations accurately.',
      author: 'Tanvir Ahmed',
      date: '2025-02-10',
      category: 'IELTS',
      thumbnail: '',
      readTime: '6 min read',
      seoTitle: 'IELTS Writing Band 7.5 Strategy',
      seoDescription: 'Practical steps to boost your IELTS Writing score from 6.0 to 7.5.',
      status: 'published',
    },
    {
      id: 2,
      title: '5 Daily Habits to Build Spoken English Fluency',
      titleBn: 'স্পোকেন ইংলিশে সাবলীল হওয়ার ৫টি দৈনন্দিন অভ্যাস',
      slug: '5-daily-habits-spoken-english-fluency',
      excerpt: 'Short shadowing sessions and structured speaking drills outperform passive grammar memorization.',
      content: 'Spend 15 minutes every morning shadowing native speakers, recording your 2-minute reflections, and reviewing high-frequency conversational phrases.',
      author: 'Nusrat Jahan',
      date: '2025-02-14',
      category: 'Spoken English',
      thumbnail: '',
      readTime: '5 min read',
      seoTitle: '5 Daily Habits for Spoken English Fluency',
      seoDescription: 'Build lasting English speaking fluency with 15-minute daily drills.',
      status: 'published',
    },
  ];

  const cmsTestimonials = [
    {
      id: 1,
      studentName: 'Sadia Afrin',
      achievement: 'IELTS Overall Band 8.0',
      quote: 'The weekly mock interviews and line-by-line Writing Task 2 feedback helped me jump from 6.5 to 8.0 in eight weeks.',
      quoteBn: 'সাপ্তাহিক মক ইন্টারভিউ এবং রাইটিং ফিডব্যাক আমাকে ৮ সপ্তাহে ব্যান্ড ৬.৫ থেকে ৮.০ পেতে সাহায্য করেছে।',
      rating: 5,
      avatar: '',
      course: 'IELTS Preparation Masterclass',
      status: 'published',
    },
    {
      id: 2,
      studentName: 'Mehedi Hasan',
      achievement: 'Promoted to Senior Analyst',
      quote: 'Spoken English & Fluency Bootcamp removed my hesitation during international client meetings.',
      quoteBn: 'স্পোকেন ইংলিশ বুটক্যাম্প আন্তর্জাতিক ক্লায়েন্ট মিটিংয়ে আমার জড়তা দূর করেছে।',
      rating: 5,
      avatar: '',
      course: 'Spoken English & Fluency Bootcamp',
      status: 'published',
    },
    {
      id: 3,
      studentName: 'Shamim Shohan',
      achievement: 'Academic Writing Band 7.5',
      quote: 'To be honest, this Basic to Pro series is super effective! Before joining I struggled with essay structure, and now I feel completely confident.',
      quoteBn: 'আগে রাইটিং ও গ্রামারে অনেক দুর্বল ছিলাম। এই কোর্সের প্রতিটি টপিক এতো সুন্দর করে বোঝানো হয় যে এখন সব নিয়ম হাতের মুঠোয়।',
      rating: 5,
      avatar: '',
      course: 'Grammar Foundation',
      status: 'published',
    },
    {
      id: 4,
      studentName: 'Afrina Jahan',
      achievement: 'Verified Student',
      quote: 'Every grammar concept from tense timeline to free-hand translation is explained with crystal clarity. Truly one of the best online English academies!',
      quoteBn: 'ক্লাসগুলো এতো বেশি হেল্পফুল কী বলবো! বেসিক থেকে অ্যাডভান্সড পর্যন্ত প্রতিটি টপিক একদম ক্লিয়ার হয়ে গেছে আলহামদুলিল্লাহ।',
      rating: 5,
      avatar: '',
      course: 'Grammar Foundation',
      status: 'published',
    },
    {
      id: 5,
      studentName: 'Borhan Uddin Shifat',
      achievement: 'Verified Student',
      quote: 'What I could not learn in two years of traditional coaching, I mastered within two months in the live interactive batch here.',
      quoteBn: 'গত দুই বছরে যা ইংরেজি শিখতে পারিনি, মাত্র দুই মাসে লাইভ ক্লাস ও নোটস থেকেই তা আয়ত্ত করতে পেরেছি। এক কথায় অসাধারণ!',
      rating: 5,
      avatar: '',
      course: 'IELTS Preparation Masterclass',
      status: 'published',
    },
    {
      id: 6,
      studentName: 'Tasannun Tarin',
      achievement: 'Overall Band 7.5',
      quote: 'One single reading strategy session taught us how to solve ten different True/False/Not Given traps in seconds.',
      quoteBn: 'একটা রিডিং প্যাসেজ সলভ করানোর মাধ্যমে দশটা প্রশ্নের নিয়ম একসাথে বুঝিয়ে দেন। এক্সামে হুবহু সেই টাইপ কমন আসে!',
      rating: 5,
      avatar: '',
      course: 'IELTS Reading Intensive',
      status: 'published',
    },
    {
      id: 7,
      studentName: 'Zai Nab',
      achievement: 'Verified Student',
      quote: 'After attending the classes here, I never needed another tutor. The recorded archive and PDF sheets make revision effortless.',
      quoteBn: 'সত্যি বলতে আমি এখন অন্য কোনো টিচারের ক্লাস খুঁজি না। স্যারের বোঝানোর ধরন ও নোটস অতুলনীয়!',
      rating: 5,
      avatar: '',
      course: 'Complete Fluency Combo',
      status: 'published',
    },
    {
      id: 8,
      studentName: 'Sumaiya Sumu',
      achievement: 'Verified Student',
      quote: 'The listening and pronunciation sessions helped me catch British accents naturally. Consistent practice here guarantees a strong score!',
      quoteBn: 'লিসেনিং ও প্রনান্সিয়েশন ক্লাসগুলো এতো সুন্দর করে নেওয়া হয় যে ধৈর্য সহকারে ক্লাস করলে নিশ্চিত ভালো রেজাল্ট আসবেই।',
      rating: 5,
      avatar: '',
      course: 'British Phonetics & Spoken',
      status: 'published',
    },
  ];

  const exams = [
    {
      id: 1,
      course_id: 1,
      course_title: 'IELTS Preparation Masterclass',
      title: 'IELTS Academic Diagnostic Mock Test',
      description: 'Core reading, grammar, and vocabulary readiness check.',
      duration_minutes: 20,
      total_marks: 3,
      pass_marks: 2,
      negative_mark_per_wrong: 0,
      max_attempts: 3,
      status: 'published',
      questions: [
        { id: 1, question: 'Choose the most accurate academic synonym for "rapid":', options: ['Slow', 'Swift', 'Gradual', 'Delayed'], correct_option_index: 1, explanation: '"Swift" means happening quickly or promptly.', marks: 1 },
        { id: 2, question: 'Which sentence uses the Present Perfect tense correctly?', options: ['She has finished the report yesterday.', 'She has already submitted the assignment.', 'She have finish the essay.', 'She is finished the test last week.'], correct_option_index: 1, explanation: 'Present Perfect pairs "has/have" + past participle without a finished past time marker.', marks: 1 },
        { id: 3, question: 'In IELTS Writing Task 1, which verb best describes a sharp upward movement?', options: ['Plummeted', 'Fluctuated', 'Surged', 'Plateaued'], correct_option_index: 2, explanation: '"Surged" indicates a sudden, strong increase.', marks: 1 },
      ],
    },
    {
      id: 2,
      course_id: 2,
      course_title: 'Spoken English & Fluency Bootcamp',
      title: 'Spoken Fluency & Pronunciation Check',
      description: 'Assess natural conversational idioms and polite expressions.',
      duration_minutes: 15,
      total_marks: 2,
      pass_marks: 1,
      negative_mark_per_wrong: 0,
      max_attempts: 3,
      status: 'published',
      questions: [
        { id: 4, question: 'What is the most polite way to ask a colleague to repeat something?', options: ['Speak again!', 'What did you say?', 'Could you please say that again?', 'Repeat it now.'], correct_option_index: 2, explanation: '"Could you please..." is polite and professional.', marks: 1 },
        { id: 5, question: 'Which word has a silent "k"?', options: ['Keep', 'Knowledge', 'Kind', 'Kitchen'], correct_option_index: 1, explanation: 'The "k" in "kn-" words like knowledge is silent.', marks: 1 },
      ],
    },
  ];

  const examAttempts: Array<Record<string, unknown>> = [];
  const questionBank: Array<Record<string, unknown>> = [
    { id: 1, course_id: 1, topic: 'Vocabulary', difficulty: 'medium', question: 'Select the best academic replacement for "a lot of":', options: ['A bunch of', 'Substantial', 'Tons of', 'Many much'], correct_option_index: 1, explanation: '"Substantial" fits formal academic writing.', marks: 1, created_at: '2025-02-01' },
    { id: 2, course_id: 3, topic: 'Grammar', difficulty: 'easy', question: 'Neither the teacher nor the students ___ present.', options: ['is', 'was', 'were', 'has been'], correct_option_index: 2, explanation: 'With "neither...nor", the verb agrees with the closer subject ("students").', marks: 1, created_at: '2025-02-02' },
  ];

  const enrollments = [
    { id: 1, user_id: 7, student_name: 'Rafiq Hasan', student_email: 'rafiq.hasan2025@gmail.com', course_id: 1, course_title: 'IELTS Preparation Masterclass', branch_id: 1, status: 'active', starts_at: '2025-01-15', ends_at: null as string | null, notes: 'Spring 2025 batch', created_at: '2025-01-15 10:00:00' },
    { id: 2, user_id: 7, student_name: 'Rafiq Hasan', student_email: 'rafiq.hasan2025@gmail.com', course_id: 2, course_title: 'Spoken English & Fluency Bootcamp', branch_id: 1, status: 'completed', starts_at: '2024-11-01', ends_at: '2025-01-01', notes: 'Completed with distinction', created_at: '2024-11-01 10:00:00' },
  ];

  const payments = [
    { id: 1, user_id: 7, student_name: 'Rafiq Hasan', course_id: 1, course_title: 'IELTS Preparation Masterclass', amount: '8500.00', method: 'bKash', trx_id: 'TRX9981234', sender_phone: '01723998877', status: 'verified', refunded_amount: '0.00', invoice_number: 'INV-2025-1001', created_at: '2025-01-15 10:05:00' },
  ];

  const certificates = [
    {
      id: 1,
      certificateNumber: 'AEC-CERT-2025-1001',
      studentUserId: 7,
      studentId: '7',
      studentName: 'Rafiq Hasan',
      courseId: 2,
      courseTitle: 'Spoken English & Fluency Bootcamp',
      completionDate: '2025-01-05',
      grade: 'A+',
      issueDate: '2025-01-06',
      authorizedBy: 'Engr. Mahbubul Alom',
      status: 'issued' as 'issued' | 'revoked',
      revokedAt: null as string | null,
      revokeReason: null as string | null,
    },
  ];

  const admissions: Array<Record<string, unknown>> = [];
  const attendanceRecords: Array<Record<string, unknown>> = [];
  const batches = [
    { id: 1, course_id: 1, course_title: 'IELTS Preparation Masterclass', branch_id: 1, instructor_id: 4, instructor_name: 'Tanvir Ahmed (IELTS 8.5)', name: 'IELTS Morning Batch A', batch_code: 'IELTS-M1', schedule: 'Sun, Tue, Thu · 10:00 AM', room_number: 'Room 302', max_capacity: 30, enrolled_count: 18, start_date: '2025-02-01', end_date: '2025-04-30', status: 'ongoing', is_active: 1 },
  ];
  const routines = [
    { id: 1, batch_id: 1, batch_name: 'IELTS Morning Batch A', instructor_id: 4, instructor_name: 'Tanvir Ahmed (IELTS 8.5)', day_of_week: 'Sunday', start_time: '10:00', end_time: '11:30', subject: 'Listening & Reading Strategy', room_number: 'Room 302' },
  ];
  const liveClasses = [
    { id: 1, course_id: 1, course_title: 'IELTS Preparation Masterclass', instructor_id: 4, instructor_name: 'Tanvir Ahmed', title: 'IELTS Speaking Part 2 Cue Card Clinic', date: '2026-11-20', time: '19:00', meeting_platform: 'zoom', meeting_url: 'https://zoom.us/j/123456789', status: 'upcoming', recording_url: '' },
  ];
  const marksheets: Array<Record<string, unknown>> = [];
  const notifications = [
    { id: 1, title: 'Welcome to Alom\'s English Center LMS', title_bn: 'আলমস ইংলিশ সেন্টার এলএমএস-এ স্বাগতম', message: 'Your dashboard, courses, exams, and certificates are ready.', message_bn: 'আপনার ড্যাশবোর্ড, কোর্স ও সার্টিফিকেট প্রস্তুত রয়েছে।', type: 'info', target_role: 'all', read_at: null as string | null, created_at: '2025-02-01 09:00:00' },
  ];
  const notices = [
    { id: 1, title: 'IELTS Spring Batch Orientation', body: 'Orientation begins this Sunday at 10:00 AM in Mirpur 10 Main Campus and online via Zoom.', audience_role: 'all', branch_id: 1, status: 'published', starts_at: '2025-01-01 00:00:00', ends_at: '2027-12-31 23:59:59', is_read: false, created_at: '2025-02-01 09:00:00' },
  ];
  const promotions: Array<Record<string, unknown>> = [];
  const libraryBooks = [
    { id: 1, branch_id: 1, title: 'Cambridge IELTS 19 Academic', author: 'Cambridge University Press', isbn: '978-1009454568', category: 'IELTS', total_copies: 12, available_copies: 10, daily_fine: 10, is_active: 1 },
    { id: 2, branch_id: 1, title: 'English Grammar in Use (5th Ed)', author: 'Raymond Murphy', isbn: '978-1108457651', category: 'Grammar', total_copies: 15, available_copies: 14, daily_fine: 10, is_active: 1 },
  ];
  const libraryLoans: Array<Record<string, unknown>> = [];
  const libraryFinePayments: Array<Record<string, unknown>> = [];
  const hostelRooms = [
    { id: 1, branch_id: 1, building_name: 'Mirpur Scholars Residence', room_number: 'A-101', room_type: 'shared', monthly_rent: '4500.00', capacity: 2, occupied_count: 1, is_active: 1 },
  ];
  const hostelAllocations: Array<Record<string, unknown>> = [];
  const transportRoutes = [
    { id: 1, branch_id: 1, route_name: 'Uttara - Mirpur 10 Campus Shuttle', vehicle_number: 'DHK-METRO-GA-1142', driver_name: 'Karim Mia', driver_phone: '01711223344', monthly_fee: '1200.00', capacity: 24, is_active: 1 },
  ];
  const transportAssignments: Array<Record<string, unknown>> = [];
  const facilityInvoices: Array<Record<string, unknown>> = [];
  const facilityPayments: Array<Record<string, unknown>> = [];
  const expenses: Array<Record<string, unknown>> = [];
  const expenseSuppliers: Array<Record<string, unknown>> = [];
  const expenseBudgets: Array<Record<string, unknown>> = [];
  const expenseRecurring: Array<Record<string, unknown>> = [];
  const commissionRules: Array<Record<string, unknown>> = [];
  const payoutRequests: Array<Record<string, unknown>> = [];
  const ebookFiles: Array<{ ebook_id: string; original_filename: string; size_bytes: number; uploaded_at: string }> = [];
  const ebookOrders: Array<Record<string, unknown>> = [];
  const productOrders: Array<Record<string, unknown>> = [];
  const mediaFiles: Array<Record<string, unknown>> = [];
  const mediaTrash: Array<Record<string, unknown>> = [];
  const plugins = [
    { id: 'plugin_bkash_pgw', name: 'bKash PGW (Tokenized Checkout)', version: '2.4.0', enabled: true, rollback_snapshots: ['2.3.1'] },
    { id: 'plugin_zoom', name: 'Zoom Education & Pro Integration', version: '3.1.2', enabled: true, rollback_snapshots: ['3.0.0'] },
  ];
  const saasTenants = [
    { id: 1, tenant_key: 'aec-hq', name: "Alom's English Center HQ", city: 'Dhaka', plan_key: 'enterprise', status: 'active', domain: 'alomsenglishcenter.com', created_at: '2025-01-01' },
  ];
  const saasPlans = [
    { plan_key: 'starter', name: 'Starter Academy', monthly_price: 2500, currency: 'BDT' },
    { plan_key: 'growth', name: 'Multi-Branch Growth', monthly_price: 6500, currency: 'BDT' },
    { plan_key: 'enterprise', name: 'Enterprise SaaS', monthly_price: 15000, currency: 'BDT' },
  ];

  // --- Auth helper ---
  function getSession(req: Request): { session: SessionData; user: UserRow } | null {
    const cookies = parseCookies(req.headers.cookie);
    const sid = cookies['aec_sid'];
    if (!sid) return null;
    const session = sessions.get(sid);
    if (!session) return null;
    const user = users.find(u => u.id === session.userId && u.is_active);
    if (!user) return null;
    return { session, user };
  }

  function sanitizeUser(u: UserRow) {
    return {
      id: u.id,
      name: u.name,
      email: u.email,
      phone: u.phone,
      role: u.role,
      branch_id: u.branch_id,
      email_verified_at: u.email_verified_at,
      phone_verified_at: u.phone_verified_at,
      created_at: u.created_at,
    };
  }

  function requireAuth(req: Request, res: Response): { session: SessionData; user: UserRow } | null {
    const auth = getSession(req);
    if (!auth) {
      fail(res, 'Authentication required.', 401);
      return null;
    }
    return auth;
  }

  // --- 1. Auth & Accounts ---
  router.all('/auth.php', (req, res) => {
    const action = String(req.query.action || '');
    if (action === 'login' && req.method === 'POST') {
      const email = String(req.body?.email || '').trim().toLowerCase();
      const password = String(req.body?.password || '');
      if (!email || !password) return fail(res, 'Enter a valid email and password.', 422);
      const user = users.find(u => u.email.toLowerCase() === email && u.is_active);
      if (!user) return fail(res, 'Invalid email or password.', 401);
      if (user.password && user.password !== password) return fail(res, 'Invalid email or password.', 401);
      const sid = crypto.randomBytes(24).toString('hex');
      const csrfToken = crypto.randomBytes(32).toString('hex');
      sessions.set(sid, { userId: user.id, csrfToken });
      res.setHeader('Set-Cookie', `aec_sid=${sid}; Path=/; HttpOnly; SameSite=Lax`);
      return ok(res, { user: sanitizeUser(user), csrf_token: csrfToken }, 'Login successful.');
    }
    if (action === 'me' && req.method === 'GET') {
      const auth = requireAuth(req, res);
      if (!auth) return;
      return ok(res, { user: sanitizeUser(auth.user), csrf_token: auth.session.csrfToken });
    }
    if (action === 'logout' && req.method === 'POST') {
      const cookies = parseCookies(req.headers.cookie);
      if (cookies['aec_sid']) sessions.delete(cookies['aec_sid']);
      res.setHeader('Set-Cookie', 'aec_sid=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
      return ok(res, null, 'Logged out.');
    }
    if (action === 'change_password' && req.method === 'POST') {
      const auth = requireAuth(req, res);
      if (!auth) return;
      const next = String(req.body?.new_password || '');
      if (next.length < 12) return fail(res, 'New password must be at least 12 characters.', 422);
      auth.user.password = next;
      auth.session.csrfToken = crypto.randomBytes(32).toString('hex');
      return ok(res, { csrf_token: auth.session.csrfToken }, 'Password updated.');
    }
    return fail(res, 'Unknown authentication action.', 404);
  });

  router.all('/accounts.php', (req, res) => {
    const action = String(req.query.action || '');
    if (action === 'register' && req.method === 'POST') {
      const name = String(req.body?.name || '').trim();
      const email = String(req.body?.email || '').trim().toLowerCase();
      const phone = String(req.body?.phone || '').trim();
      const password = String(req.body?.password || '');
      if (!name || !email || password.length < 12) return fail(res, 'Name, valid email, and 12+ char password are required.', 422);
      if (users.some(u => u.email.toLowerCase() === email)) return fail(res, 'An account with this email already exists.', 409);
      const id = genId();
      users.push({
        id, name, email, phone, password, role: 'student', branch_id: 1,
        is_active: true, email_verified_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        phone_verified_at: null, created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      return ok(res, { email }, 'Account created and verified for local preview. You can sign in now.');
    }
    if (action === 'verify_email') return ok(res, null, 'Email verified. You can now sign in.');
    if (action === 'resend_verification') return ok(res, null, 'Verification instructions prepared.');
    if (action === 'request_password_reset') return ok(res, null, 'If an eligible account exists, password reset instructions have been sent.');
    if (action === 'reset_password') return ok(res, null, 'Password reset successful. Sign in with your new password.');
    if (action === 'profile') {
      const auth = requireAuth(req, res);
      if (!auth) return;
      if (req.method === 'POST') {
        const name = String(req.body?.name || '').trim();
        const phone = String(req.body?.phone || '').trim();
        if (name) auth.user.name = name;
        auth.user.phone = phone;
        return ok(res, { name: auth.user.name, phone: auth.user.phone }, 'Profile updated.');
      }
      return ok(res, sanitizeUser(auth.user));
    }
    if (action === 'email_delivery') return ok(res, { summary: { queued: 0, sent: 0, failed: 0 }, items: [] });
    if (action === 'request_verification') {
      const auth = requireAuth(req, res);
      if (!auth) return;
      auth.user.email_verified_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
      return ok(res, { email: auth.user.email }, 'Verification marked complete.');
    }
    if (action === 'admin_activate') return ok(res, null, 'Account activated.');
    return ok(res, null);
  });

  // --- 2. Categories & Courses ---
  router.all('/categories.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, categories);
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'create') {
      const id = genId();
      const cat = {
        id, name: String(req.body?.name || 'Category'), name_bn: String(req.body?.name_bn || req.body?.name || ''),
        slug: String(req.body?.slug || `cat-${id}`), description: String(req.body?.description || ''),
        color: 'blue', sort_order: Number(req.body?.sort_order || categories.length + 1), is_active: 1,
      };
      categories.push(cat);
      return ok(res, { id }, 'Category created.', 201);
    }
    if (action === 'update') {
      const id = Number(req.query.id);
      const found = categories.find(c => c.id === id);
      if (!found) return fail(res, 'Category not found.', 404);
      Object.assign(found, req.body || {});
      return ok(res, { id }, 'Category updated.');
    }
    if (action === 'delete') {
      const id = Number(req.query.id);
      const idx = categories.findIndex(c => c.id === id);
      if (idx !== -1) categories.splice(idx, 1);
      return ok(res, { id }, 'Category deleted.');
    }
    return fail(res, 'Unknown category action.', 404);
  });

  router.all('/courses.php', (req, res) => {
    const action = String(req.query.action || 'list');
    const auth = getSession(req);
    if (action === 'list') {
      const rows = courses.filter(c => !c.deleted_at).map(c => ({
        ...c,
        content_count: courseContentLinks.filter(l => l.course_id === c.id).length,
      }));
      return ok(res, rows);
    }
    if (action === 'detail') {
      const id = Number(req.query.id);
      const course = courses.find(c => c.id === id && !c.deleted_at);
      if (!course) return fail(res, 'Course not found.', 404);
      const links = courseContentLinks.filter(l => l.course_id === id);
      const content = links.map(l => {
        const item = contentItems.find(ci => ci.id === l.content_item_id);
        return item ? { ...item, sort_order: l.sort_order, is_preview: l.is_preview } : null;
      }).filter(Boolean);
      return ok(res, { ...course, can_track_progress: Boolean(auth), content });
    }
    if (!auth) return fail(res, 'Authentication required.', 401);
    if (action === 'create') {
      const id = genId();
      const cat = categories.find(c => c.id === Number(req.body?.category_id));
      courses.unshift({
        id,
        category_id: Number(req.body?.category_id || 1),
        branch_id: req.body?.branch_id ? Number(req.body.branch_id) : 1,
        category_name: cat?.name || 'IELTS',
        title: String(req.body?.title || 'Untitled Course'),
        slug: String(req.body?.slug || `course-${id}`),
        subtitle: String(req.body?.subtitle || ''),
        description: String(req.body?.description || ''),
        price: Number(req.body?.price || 0),
        original_price: Number(req.body?.original_price || 0),
        level: String(req.body?.level || 'All Levels'),
        duration_weeks: Number(req.body?.duration_weeks || 4),
        thumbnail: String(req.body?.thumbnail || ''),
        status: String(req.body?.status || 'published'),
        syllabus: Array.isArray(req.body?.syllabus) ? req.body.syllabus : [
          { week: 1, title: 'Orientation & Foundation', topics: ['Diagnostic assessment', 'Core study framework'] },
        ],
        deleted_at: null,
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      return ok(res, { id }, 'Course created.', 201);
    }
    if (action === 'update') {
      const id = Number(req.query.id);
      const course = courses.find(c => c.id === id);
      if (!course) return fail(res, 'Course not found.', 404);
      Object.assign(course, req.body || {});
      return ok(res, { id }, 'Course updated.');
    }
    if (action === 'delete') {
      const id = Number(req.query.id);
      const course = courses.find(c => c.id === id);
      if (course) course.deleted_at = new Date().toISOString();
      return ok(res, { id }, 'Course moved to recycle bin.');
    }
    if (action === 'restore') {
      const id = Number(req.query.id);
      const course = courses.find(c => c.id === id);
      if (course) course.deleted_at = null;
      return ok(res, { id }, 'Course restored.');
    }
    return fail(res, 'Unknown course action.', 404);
  });

  // --- 3. Content Library ---
  router.all('/content.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') {
      const rows = contentItems.map(item => {
        const link = courseContentLinks.find(l => l.content_item_id === item.id);
        const course = link ? courses.find(c => c.id === link.course_id) : null;
        return { ...item, course_id: course?.id ?? null, course_title: course?.title ?? 'Free Learning' };
      });
      return ok(res, rows);
    }
    if (action === 'detail') {
      const item = contentItems.find(i => i.id === Number(req.query.id));
      return item ? ok(res, item) : fail(res, 'Content not found.', 404);
    }
    if (action === 'links') {
      const id = Number(req.query.id);
      const links = courseContentLinks.filter(l => l.content_item_id === id).map(l => ({
        course_id: l.course_id,
        course_title: courses.find(c => c.id === l.course_id)?.title || '',
        sort_order: l.sort_order,
        is_preview: l.is_preview,
      }));
      return ok(res, links);
    }
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'progress') {
      const courseId = Number(req.query.course_id);
      return ok(res, lessonProgress.filter(p => p.user_id === auth.user.id && p.course_id === courseId));
    }
    if (action === 'progress_update') {
      const course_id = Number(req.body?.course_id);
      const content_item_id = Number(req.body?.content_item_id);
      const is_completed = req.body?.is_completed ? 1 : 0;
      const notes = String(req.body?.notes || '');
      const existing = lessonProgress.find(p => p.user_id === auth.user.id && p.course_id === course_id && p.content_item_id === content_item_id);
      if (existing) {
        existing.is_completed = is_completed;
        existing.notes = notes;
        existing.updated_at = new Date().toISOString();
      } else {
        lessonProgress.push({ user_id: auth.user.id, course_id, content_item_id, is_completed, notes, updated_at: new Date().toISOString() });
      }
      return ok(res, { course_id, content_item_id, is_completed: Boolean(is_completed), notes });
    }
    if (action === 'create') {
      const id = genId();
      contentItems.unshift({
        id, title: String(req.body?.title || 'Untitled'), title_bn: String(req.body?.title_bn || ''),
        content_type: String(req.body?.content_type || 'lesson'), body: String(req.body?.body || ''),
        media_url: String(req.body?.media_url || ''), duration_minutes: Number(req.body?.duration_minutes || 10),
        status: String(req.body?.status || 'published'), is_public: 1,
      });
      return ok(res, { id }, 'Content created.', 201);
    }
    if (action === 'update') {
      const id = Number(req.query.id);
      const item = contentItems.find(i => i.id === id);
      if (item) Object.assign(item, req.body || {});
      return ok(res, { id });
    }
    if (action === 'delete') {
      const id = Number(req.query.id);
      const idx = contentItems.findIndex(i => i.id === id);
      if (idx !== -1) contentItems.splice(idx, 1);
      return ok(res, { id });
    }
    if (action === 'attach') {
      courseContentLinks.push({ course_id: Number(req.body?.course_id), content_item_id: Number(req.body?.content_item_id), sort_order: Number(req.body?.sort_order || 0), is_preview: req.body?.is_preview ? 1 : 0 });
      return ok(res, { attached: true });
    }
    if (action === 'detach') {
      const cid = Number(req.body?.course_id);
      const itemId = Number(req.body?.content_item_id);
      const idx = courseContentLinks.findIndex(l => l.course_id === cid && l.content_item_id === itemId);
      if (idx !== -1) courseContentLinks.splice(idx, 1);
      return ok(res, { detached: true });
    }
    if (action === 'sync_links') {
      const cid = Number(req.body?.content_item_id);
      for (let i = courseContentLinks.length - 1; i >= 0; i--) {
        if (courseContentLinks[i].content_item_id === cid) courseContentLinks.splice(i, 1);
      }
      const links = Array.isArray(req.body?.links) ? req.body.links : [];
      for (const l of links) {
        courseContentLinks.push({ course_id: Number(l.course_id), content_item_id: cid, sort_order: Number(l.sort_order || 0), is_preview: l.is_preview ? 1 : 0 });
      }
      return ok(res, { content_item_id: cid, linked_courses: links.length });
    }
    return ok(res, []);
  });

  // --- 4. CMS ---
  router.all('/cms.php', (req, res) => {
    const action = String(req.query.action || 'settings');
    if (action === 'settings') return ok(res, websiteSettings);
    if (action === 'public_posts') return ok(res, cmsPosts.filter(p => p.status === 'published'));
    if (action === 'posts') return ok(res, cmsPosts);
    if (action === 'post') {
      const post = cmsPosts.find(p => p.slug === String(req.query.slug || ''));
      return post ? ok(res, post) : fail(res, 'Post not found.', 404);
    }
    if (action === 'public_testimonials') return ok(res, cmsTestimonials.filter(t => t.status === 'published'));
    if (action === 'testimonials') return ok(res, cmsTestimonials);

    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'save_settings') {
      websiteSettings = { ...websiteSettings, ...(req.body?.settings || {}) };
      return ok(res, websiteSettings, 'Website settings saved.');
    }
    if (action === 'create_post') {
      const id = genId();
      cmsPosts.unshift({
        id,
        title: String(req.body?.title || ''),
        titleBn: String(req.body?.title_bn || ''),
        slug: String(req.body?.slug || `post-${id}`),
        excerpt: String(req.body?.excerpt || ''),
        content: String(req.body?.content || ''),
        author: String(req.body?.author || "Alom's English Center"),
        date: new Date().toISOString().slice(0, 10),
        category: String(req.body?.category || 'General'),
        thumbnail: String(req.body?.thumbnail_url || ''),
        readTime: `${Number(req.body?.read_time_minutes || 5)} min read`,
        seoTitle: String(req.body?.seo_title || ''),
        seoDescription: String(req.body?.seo_description || ''),
        status: String(req.body?.status || 'published'),
      });
      return ok(res, { id }, 'Post created.', 201);
    }
    if (action === 'update_post') {
      const id = Number(req.query.id);
      const post = cmsPosts.find(p => p.id === id);
      if (post) Object.assign(post, req.body || {});
      return ok(res, { id });
    }
    if (action === 'delete_post') {
      const id = Number(req.query.id);
      const idx = cmsPosts.findIndex(p => p.id === id);
      if (idx !== -1) cmsPosts.splice(idx, 1);
      return ok(res, { id });
    }
    if (action === 'create_testimonial') {
      const id = genId();
      cmsTestimonials.unshift({
        id,
        studentName: String(req.body?.student_name || ''),
        achievement: String(req.body?.achievement || ''),
        quote: String(req.body?.quote || ''),
        quoteBn: String(req.body?.quote_bn || ''),
        rating: Number(req.body?.rating || 5),
        avatar: String(req.body?.avatar_url || ''),
        course: String(req.body?.course_name || ''),
        status: String(req.body?.status || 'published'),
      });
      return ok(res, { id }, 'Testimonial added.', 201);
    }
    if (action === 'update_testimonial') {
      const id = Number(req.query.id);
      const t = cmsTestimonials.find(x => x.id === id);
      if (t) Object.assign(t, req.body || {});
      return ok(res, { id });
    }
    if (action === 'delete_testimonial') {
      const id = Number(req.query.id);
      const idx = cmsTestimonials.findIndex(x => x.id === id);
      if (idx !== -1) cmsTestimonials.splice(idx, 1);
      return ok(res, { id });
    }
    return ok(res, null);
  });

  // --- 5. Exams & Question Bank & Marksheets ---
  router.all('/exams.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') {
      return ok(res, exams.map(e => ({ ...e, question_count: e.questions.length })));
    }
    if (action === 'detail') {
      const exam = exams.find(e => e.id === Number(req.query.id));
      return exam ? ok(res, exam) : fail(res, 'Exam not found.', 404);
    }
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'create') {
      const id = genId();
      exams.unshift({
        id, course_id: req.body?.course_id ? Number(req.body.course_id) : 1,
        course_title: courses.find(c => c.id === Number(req.body?.course_id))?.title || 'General',
        title: String(req.body?.title || 'New Exam'), description: String(req.body?.description || ''),
        duration_minutes: Number(req.body?.duration_minutes || 30), total_marks: 0,
        pass_marks: Number(req.body?.pass_marks || 10), negative_mark_per_wrong: Number(req.body?.negative_mark_per_wrong || 0),
        max_attempts: Number(req.body?.max_attempts || 1), status: String(req.body?.status || 'draft'), questions: [],
      });
      return ok(res, { id }, 'Exam created.', 201);
    }
    if (action === 'update') {
      const id = Number(req.query.id);
      const exam = exams.find(e => e.id === id);
      if (exam) Object.assign(exam, req.body || {});
      return ok(res, { id });
    }
    if (action === 'save_questions') {
      const id = Number(req.query.id);
      const exam = exams.find(e => e.id === id);
      if (!exam) return fail(res, 'Exam not found.', 404);
      const incoming = Array.isArray(req.body?.questions) ? req.body.questions : [];
      exam.questions = incoming.map((q: Record<string, unknown>, idx: number) => ({
        id: idx + 1,
        question: String(q.question || ''),
        options: Array.isArray(q.options) ? q.options.map(String) : [],
        correct_option_index: Number(q.correct_option_index || 0),
        explanation: String(q.explanation || ''),
        marks: Number(q.marks || 1),
      }));
      exam.total_marks = exam.questions.reduce((sum, q) => sum + q.marks, 0);
      return ok(res, { id, question_count: exam.questions.length, total_marks: exam.total_marks });
    }
    if (action === 'start') {
      const exam = exams.find(e => e.id === Number(req.query.id));
      if (!exam) return fail(res, 'Exam not found.', 404);
      const attemptId = genId();
      const now = new Date();
      const expires = new Date(now.getTime() + exam.duration_minutes * 60000);
      examAttempts.unshift({
        id: attemptId, exam_id: exam.id, exam_title: exam.title, student_user_id: auth.user.id,
        student_name: auth.user.name, started_at: now.toISOString(), expires_at: expires.toISOString(),
        submitted_at: null, score: null, total_marks: exam.total_marks, percentage: null, passed: null, status: 'in_progress', answers: {},
      });
      return ok(res, { attempt: { id: attemptId, started_at: now.toISOString(), expires_at: expires.toISOString(), remaining_seconds: exam.duration_minutes * 60, answers: {} } });
    }
    if (action === 'save_answers') {
      const attempt = examAttempts.find(a => a.id === Number(req.query.id));
      const answers = req.body?.answers || {};
      if (attempt) attempt.answers = answers;
      return ok(res, { attempt_id: Number(req.query.id), saved_answers: Object.keys(answers).length });
    }
    if (action === 'submit') {
      const attempt = examAttempts.find(a => a.id === Number(req.query.id));
      if (!attempt) return fail(res, 'Attempt not found.', 404);
      const exam = exams.find(e => e.id === attempt.exam_id);
      const answers = (req.body?.answers || {}) as Record<string, number>;
      let score = 0;
      let totalMarks = exam?.total_marks || 0;
      if (exam) {
        for (const q of exam.questions) {
          const picked = answers[String(q.id)];
          if (picked !== undefined) {
            if (Number(picked) === q.correct_option_index) score += q.marks;
            else score -= exam.negative_mark_per_wrong;
          }
        }
      }
      score = Math.max(0, score);
      const percentage = totalMarks > 0 ? Math.round((score / totalMarks) * 100) : 0;
      const passed = score >= (exam?.pass_marks || 0) ? 1 : 0;
      attempt.submitted_at = new Date().toISOString();
      attempt.score = score;
      attempt.percentage = percentage;
      attempt.passed = passed;
      attempt.status = 'submitted';
      attempt.answers = answers;
      return ok(res, attempt);
    }
    if (action === 'attempts') {
      const examId = req.query.exam_id ? Number(req.query.exam_id) : null;
      const list = examAttempts.filter(a => (!examId || a.exam_id === examId));
      return ok(res, list);
    }
    if (action === 'delete') {
      const id = Number(req.query.id);
      const idx = exams.findIndex(e => e.id === id);
      if (idx !== -1) exams.splice(idx, 1);
      return ok(res, { id });
    }
    return ok(res, []);
  });

  router.all('/question_bank.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, questionBank);
    if (action === 'detail') return ok(res, questionBank.find(q => q.id === Number(req.query.id)) || null);
    if (action === 'create') {
      const id = genId();
      questionBank.unshift({ id, ...(req.body || {}), created_at: new Date().toISOString().slice(0, 10) });
      return ok(res, { id }, 'Question created.', 201);
    }
    if (action === 'update') {
      const q = questionBank.find(item => item.id === Number(req.query.id));
      if (q) Object.assign(q, req.body || {});
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'delete') {
      const idx = questionBank.findIndex(item => item.id === Number(req.query.id));
      if (idx !== -1) questionBank.splice(idx, 1);
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'add_to_exam') {
      const examId = Number(req.query.exam_id);
      const exam = exams.find(e => e.id === examId);
      const ids: number[] = Array.isArray(req.body?.question_bank_ids) ? req.body.question_bank_ids : [];
      let imported = 0;
      if (exam) {
        for (const qid of ids) {
          const qb = questionBank.find(q => q.id === Number(qid));
          if (qb) {
            exam.questions.push({
              id: genId(),
              question: String(qb.question || ''),
              options: Array.isArray(qb.options) ? qb.options.map(String) : [],
              correct_option_index: Number(qb.correct_option_index || 0),
              explanation: String(qb.explanation || ''),
              marks: Number(qb.marks || 1),
            });
            imported++;
          }
        }
        exam.total_marks = exam.questions.reduce((s, q) => s + q.marks, 0);
      }
      return ok(res, { exam_id: examId, imported_count: imported, total_marks: exam?.total_marks || 0 });
    }
    return ok(res, []);
  });

  router.all('/marksheets.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, marksheets);
    if (action === 'eligible_attempts') return ok(res, examAttempts.filter(a => a.status === 'submitted'));
    if (action === 'issue') {
      const attempt = examAttempts.find(a => a.id === Number(req.body?.attempt_id));
      const id = genId();
      const num = `AEC-MS-${new Date().getFullYear()}-${id}`;
      const record = { id, marksheet_number: num, attempt_id: attempt?.id || 0, student_name: attempt?.student_name || 'Student', exam_title: attempt?.exam_title || 'Exam', score: attempt?.score || 0, total_marks: attempt?.total_marks || 0, grade: 'A', issued_at: new Date().toISOString().slice(0, 10) };
      marksheets.unshift(record);
      return ok(res, { id, marksheet_number: num, grade: 'A' }, 'Marksheet issued.', 201);
    }
    return ok(res, []);
  });

  // --- 6. Users, Branches, Enrollments, Payments, Certificates, Admissions ---
  router.all('/users.php', (req, res) => {
    const auth = requireAuth(req, res);
    if (!auth) return;
    const action = String(req.query.action || 'list');
    if (action === 'list') {
      const q = String(req.query.q || '').toLowerCase();
      const filtered = users.filter(u => !q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q));
      return ok(res, filtered.map(sanitizeUser));
    }
    if (action === 'create') {
      const id = genId();
      users.unshift({
        id,
        name: String(req.body?.name || ''),
        email: String(req.body?.email || '').toLowerCase(),
        phone: String(req.body?.phone || ''),
        password: String(req.body?.password || ''),
        role: String(req.body?.role || 'student'),
        branch_id: req.body?.branch_id ? Number(req.body.branch_id) : 1,
        is_active: true,
        email_verified_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        phone_verified_at: null,
        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      return ok(res, { id }, 'User created.', 201);
    }
    if (action === 'update') {
      const u = users.find(x => x.id === Number(req.query.id));
      if (u) Object.assign(u, req.body || {});
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'deactivate') {
      const u = users.find(x => x.id === Number(req.query.id));
      if (u) u.is_active = false;
      return ok(res, { id: Number(req.query.id) });
    }
    return ok(res, []);
  });

  router.all('/branches.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, branches.filter(b => b.is_active));
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'create') {
      const id = genId();
      branches.push({ id, code: String(req.body?.code || `BR-${id}`), name: String(req.body?.name || ''), city: String(req.body?.city || ''), address: String(req.body?.address || ''), phone: String(req.body?.phone || ''), is_active: 1 });
      return ok(res, { id }, 'Branch created.', 201);
    }
    if (action === 'update') {
      const b = branches.find(x => x.id === Number(req.query.id));
      if (b) Object.assign(b, req.body || {});
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'deactivate') {
      const b = branches.find(x => x.id === Number(req.query.id));
      if (b) b.is_active = 0;
      return ok(res, { id: Number(req.query.id) });
    }
    return ok(res, []);
  });

  router.all('/enrollments.php', (req, res) => {
    const auth = requireAuth(req, res);
    if (!auth) return;
    const action = String(req.query.action || 'list');
    if (action === 'my') return ok(res, enrollments.filter(e => e.user_id === auth.user.id));
    if (action === 'list') {
      const status = String(req.query.status || '');
      return ok(res, status ? enrollments.filter(e => e.status === status) : enrollments);
    }
    if (action === 'create') {
      const id = genId();
      const student = users.find(u => u.id === Number(req.body?.user_id));
      const course = courses.find(c => c.id === Number(req.body?.course_id));
      enrollments.unshift({
        id, user_id: Number(req.body?.user_id), student_name: student?.name || 'Student', student_email: student?.email || '',
        course_id: Number(req.body?.course_id), course_title: course?.title || 'Course', branch_id: Number(req.body?.branch_id || 1),
        status: String(req.body?.status || 'active'), starts_at: new Date().toISOString().slice(0, 10), ends_at: null,
        notes: String(req.body?.notes || ''), created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      return ok(res, { id }, 'Enrollment created.', 201);
    }
    if (action === 'update') {
      const en = enrollments.find(e => e.id === Number(req.query.id));
      if (en) Object.assign(en, req.body || {});
      return ok(res, { id: Number(req.query.id) });
    }
    return ok(res, []);
  });

  router.all('/payments.php', (req, res) => {
    const auth = requireAuth(req, res);
    if (!auth) return;
    const action = String(req.query.action || 'list');
    if (action === 'list') {
      const status = String(req.query.status || '');
      return ok(res, status ? payments.filter(p => p.status === status) : payments);
    }
    if (action === 'my') return ok(res, payments.filter(p => p.user_id === auth.user.id));
    if (action === 'submit') {
      const id = genId();
      const course = courses.find(c => c.id === Number(req.body?.course_id));
      payments.unshift({
        id, user_id: auth.user.id, student_name: auth.user.name, course_id: Number(req.body?.course_id),
        course_title: course?.title || 'Course', amount: Number(req.body?.amount || 0).toFixed(2),
        method: String(req.body?.method || 'bKash'), trx_id: String(req.body?.trx_id || ''),
        sender_phone: String(req.body?.sender_phone || ''), status: 'pending', refunded_amount: '0.00',
        invoice_number: `INV-2025-${id}`, created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      });
      return ok(res, { id, remaining_amount: '0.00' }, 'Payment submitted for verification.', 201);
    }
    if (action === 'verify') {
      const p = payments.find(x => x.id === Number(req.query.id));
      if (p) p.status = 'verified';
      return ok(res, { ledger_reference: `JRN-${p?.id || 1}` });
    }
    if (action === 'reject') {
      const p = payments.find(x => x.id === Number(req.query.id));
      if (p) p.status = 'rejected';
      return ok(res, null);
    }
    if (action === 'refund') {
      const p = payments.find(x => x.id === Number(req.query.id));
      const amt = Number(req.body?.amount || 0);
      if (p) {
        p.refunded_amount = amt.toFixed(2);
        p.status = 'refunded';
      }
      return ok(res, { refund_id: genId(), ledger_reference: `REF-${req.query.id}`, payment_status: 'refunded', refunded_total: amt.toFixed(2), remaining_refundable: '0.00', net_course_paid: '0.00', course_access_cancelled: true });
    }
    if (action === 'ledger') {
      return ok(res, payments.filter(p => p.status === 'verified').map(p => ({ id: p.id, reference: `JRN-${p.id}`, account: 'Course Tuition Revenue', credit: p.amount, debit: '0.00', date: p.created_at })));
    }
    if (action === 'reconcile') return ok(res, { balanced: true, discrepancies: [] });
    return ok(res, []);
  });

  router.all('/certificates.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'verify') {
      const certNo = String(req.query.cert_no || '').trim().toUpperCase();
      const cert = certificates.find(c => c.certificateNumber.toUpperCase() === certNo);
      if (!cert) return fail(res, 'No certificate record was found for that number.', 404);
      return ok(res, {
        certificateNumber: cert.certificateNumber,
        studentName: cert.studentName,
        courseTitle: cert.courseTitle,
        completionDate: cert.completionDate,
        grade: cert.grade,
        authorizedBy: cert.authorizedBy,
        issueDate: cert.issueDate,
        status: cert.status,
        valid: cert.status === 'issued',
        revokedAt: cert.revokedAt,
      });
    }
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'list') return ok(res, certificates);
    if (action === 'my') return ok(res, certificates.filter(c => c.studentUserId === auth.user.id));
    if (action === 'students') return ok(res, users.filter(u => u.role === 'student').map(u => ({ id: u.id, name: u.name, email: u.email })));
    if (action === 'issue') {
      const id = genId();
      const student = users.find(u => u.id === Number(req.body?.student_user_id));
      const course = courses.find(c => c.id === Number(req.body?.course_id));
      const certificateNumber = `AEC-CERT-${new Date().getFullYear()}-${id}`;
      certificates.unshift({
        id, certificateNumber, studentUserId: student?.id || 0, studentId: String(student?.id || 0),
        studentName: student?.name || 'Student', courseId: course?.id || 0, courseTitle: course?.title || 'Course',
        completionDate: new Date().toISOString().slice(0, 10), grade: String(req.body?.grade || 'A'),
        issueDate: new Date().toISOString().slice(0, 10), authorizedBy: auth.user.name, status: 'issued',
        revokedAt: null, revokeReason: null,
      });
      return ok(res, { id, certificate_number: certificateNumber }, 'Certificate issued.', 201);
    }
    if (action === 'revoke') {
      const cert = certificates.find(c => c.id === Number(req.query.id));
      if (cert) {
        cert.status = 'revoked';
        cert.revokedAt = new Date().toISOString();
        cert.revokeReason = String(req.body?.reason || '');
      }
      return ok(res, { id: Number(req.query.id), status: 'revoked' });
    }
    return ok(res, []);
  });

  router.all('/admissions.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'submit') {
      if (req.body?.website) return fail(res, 'Invalid submission.', 422);
      const id = genId();
      const reference_code = `ADM-${new Date().getFullYear()}-${id}`;
      admissions.unshift({ id, reference_code, ...(req.body || {}), status: 'pending', created_at: new Date().toISOString() });
      return ok(res, { reference_code, status: 'pending' }, 'Application received.', 201);
    }
    const auth = requireAuth(req, res);
    if (!auth) return;
    if (action === 'list') return ok(res, admissions);
    if (action === 'update') {
      const adm = admissions.find(a => a.id === Number(req.query.id));
      if (adm) Object.assign(adm, req.body || {});
      return ok(res, { id: Number(req.query.id), status: req.body?.status || 'contacted' });
    }
    return ok(res, []);
  });

  // --- 7. Attendance, Batches, Live Classes ---
  router.all('/attendance.php', (req, res) => {
    const auth = requireAuth(req, res);
    if (!auth) return;
    const action = String(req.query.action || 'list');
    if (action === 'roster') {
      const courseId = Number(req.query.course_id);
      const date = String(req.query.date || '');
      const students = users.filter(u => u.role === 'student').map(s => {
        const rec = attendanceRecords.find(r => r.course_id === courseId && r.attendance_date === date && r.user_id === s.id);
        return { id: s.id, name: s.name, email: s.email, branch_id: s.branch_id, status: rec?.status || null, remarks: rec?.remarks || null };
      });
      return ok(res, students);
    }
    if (action === 'save') {
      const courseId = Number(req.body?.course_id);
      const date = String(req.body?.attendance_date || '');
      const records = Array.isArray(req.body?.records) ? req.body.records : [];
      for (const r of records) {
        const existing = attendanceRecords.find(x => x.course_id === courseId && x.attendance_date === date && x.user_id === Number(r.user_id));
        if (existing) {
          existing.status = r.status;
          existing.remarks = r.remarks || '';
        } else {
          attendanceRecords.push({ id: genId(), course_id: courseId, course_title: courses.find(c => c.id === courseId)?.title || '', attendance_date: date, user_id: Number(r.user_id), status: r.status, remarks: r.remarks || '' });
        }
      }
      return ok(res, { course_id: courseId, attendance_date: date, saved_count: records.length });
    }
    if (action === 'my') return ok(res, attendanceRecords.filter(r => r.user_id === auth.user.id));
    return ok(res, attendanceRecords);
  });

  router.all('/batches.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, batches.filter(b => b.is_active));
    if (action === 'routines') return ok(res, routines);
    if (action === 'create') {
      const id = genId();
      batches.unshift({ id, ...(req.body || {}), enrolled_count: 0, is_active: 1 });
      return ok(res, { id }, 'Batch created.', 201);
    }
    if (action === 'deactivate') {
      const b = batches.find(x => x.id === Number(req.query.id));
      if (b) b.is_active = 0;
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'routine_create') {
      const id = genId();
      routines.push({ id, ...(req.body || {}) });
      return ok(res, { id }, 'Routine added.', 201);
    }
    if (action === 'routine_delete') {
      const idx = routines.findIndex(r => r.id === Number(req.query.id));
      if (idx !== -1) routines.splice(idx, 1);
      return ok(res, { id: Number(req.query.id) });
    }
    return ok(res, { updated: true });
  });

  router.all('/live_classes.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, liveClasses);
    if (action === 'create') {
      const id = genId();
      liveClasses.unshift({ id, ...(req.body || {}), status: 'upcoming', recording_url: '' });
      return ok(res, { id }, 'Live class scheduled.', 201);
    }
    if (action === 'update') {
      const lc = liveClasses.find(x => x.id === Number(req.query.id));
      if (lc) Object.assign(lc, req.body || {});
      return ok(res, { id: Number(req.query.id), status: lc?.status || 'upcoming' });
    }
    if (action === 'cancel') {
      const lc = liveClasses.find(x => x.id === Number(req.query.id));
      if (lc) lc.status = 'cancelled';
      return ok(res, { id: Number(req.query.id), status: 'cancelled' });
    }
    return ok(res, []);
  });

  // --- 8. Campus Facilities, Fees, Expenses, Commissions, Reports ---
  router.all('/library.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'branches' || action === 'fine_branches') return ok(res, branches.map(b => ({ id: b.id, name: b.name })));
    if (action === 'students') return ok(res, users.filter(u => u.role === 'student').map(u => ({ id: u.id, name: u.name, email: u.email, branch_id: u.branch_id })));
    if (action === 'list') return ok(res, libraryBooks.filter(b => b.is_active));
    if (action === 'loans' || action === 'my_loans') return ok(res, libraryLoans);
    if (action === 'fine_list') return ok(res, libraryLoans.filter(l => Number(l.fine_amount || 0) > 0));
    if (action === 'fine_payments') return ok(res, libraryFinePayments);
    if (action === 'fine_pay') {
      const id = genId();
      libraryFinePayments.unshift({ id, ...(req.body || {}), paid_at: new Date().toISOString() });
      return ok(res, { id, loan_id: Number(req.body?.loan_id || 0), amount: String(req.body?.amount || '0'), outstanding_amount: '0.00', journal_reference: `LIB-${id}` });
    }
    if (action === 'create') {
      const id = genId();
      libraryBooks.unshift({ id, ...(req.body || {}), available_copies: Number(req.body?.total_copies || 1), is_active: 1 });
      return ok(res, { id }, 'Book added.', 201);
    }
    if (action === 'issue') {
      const id = genId();
      libraryLoans.unshift({ id, ...(req.body || {}), status: 'issued', issued_at: new Date().toISOString().slice(0, 10) });
      return ok(res, { id }, 'Book issued.', 201);
    }
    if (action === 'return') {
      const l = libraryLoans.find(x => x.id === Number(req.body?.loan_id));
      if (l) l.status = 'returned';
      return ok(res, { id: Number(req.body?.loan_id), fine_amount: 0, late_days: 0 });
    }
    if (action === 'archive') {
      const b = libraryBooks.find(x => x.id === Number(req.body?.book_id));
      if (b) b.is_active = 0;
      return ok(res, { id: Number(req.body?.book_id) });
    }
    return ok(res, []);
  });

  router.all('/hostel.php', (req, res) => {
    const action = String(req.query.action || 'rooms');
    if (action === 'branches') return ok(res, branches.map(b => ({ id: b.id, name: b.name })));
    if (action === 'students') return ok(res, users.filter(u => u.role === 'student').map(u => ({ id: u.id, name: u.name, email: u.email, branch_id: u.branch_id })));
    if (action === 'rooms') return ok(res, hostelRooms.filter(r => r.is_active));
    if (action === 'allocations') return ok(res, hostelAllocations);
    if (action === 'create_room') {
      const id = genId();
      hostelRooms.unshift({ id, ...(req.body || {}), occupied_count: 0, is_active: 1 });
      return ok(res, { id }, 'Room created.', 201);
    }
    if (action === 'allocate') {
      const id = genId();
      hostelAllocations.unshift({ id, ...(req.body || {}), status: 'active' });
      return ok(res, { id }, 'Student allocated.', 201);
    }
    if (action === 'vacate') return ok(res, { id: Number(req.body?.allocation_id) });
    if (action === 'archive_room') {
      const r = hostelRooms.find(x => x.id === Number(req.body?.room_id));
      if (r) r.is_active = 0;
      return ok(res, { id: Number(req.body?.room_id) });
    }
    return ok(res, []);
  });

  router.all('/transport.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'branches') return ok(res, branches.map(b => ({ id: b.id, name: b.name })));
    if (action === 'students') return ok(res, users.filter(u => u.role === 'student').map(u => ({ id: u.id, name: u.name, email: u.email, branch_id: u.branch_id })));
    if (action === 'list') return ok(res, transportRoutes.filter(r => r.is_active));
    if (action === 'assignments') return ok(res, transportAssignments);
    if (action === 'create') {
      const id = genId();
      transportRoutes.unshift({ id, ...(req.body || {}), is_active: 1 });
      return ok(res, { id }, 'Route created.', 201);
    }
    if (action === 'assign') {
      const id = genId();
      transportAssignments.unshift({ id, ...(req.body || {}), status: 'active' });
      return ok(res, { id }, 'Student assigned.', 201);
    }
    if (action === 'end') return ok(res, { id: Number(req.body?.assignment_id) });
    if (action === 'archive') {
      const r = transportRoutes.find(x => x.id === Number(req.body?.route_id));
      if (r) r.is_active = 0;
      return ok(res, { id: Number(req.body?.route_id) });
    }
    return ok(res, []);
  });

  router.all('/facility-fees.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'branches') return ok(res, branches.map(b => ({ id: b.id, name: b.name })));
    if (action === 'my' || action === 'list') return ok(res, facilityInvoices);
    if (action === 'payments') return ok(res, facilityPayments);
    if (action === 'generate') return ok(res, { created_count: 0, service_type: req.body?.service_type || 'hostel', billing_month: req.body?.billing_month || '' }, 'Invoices generated.', 201);
    if (action === 'pay') {
      const id = genId();
      facilityPayments.unshift({ id, ...(req.body || {}), paid_at: new Date().toISOString() });
      return ok(res, { payment_id: id, invoice_id: Number(req.body?.invoice_id || 0), status: 'paid', outstanding_amount: '0.00', journal_reference: `FAC-${id}` }, 'Payment recorded.', 201);
    }
    return ok(res, []);
  });

  router.all('/expenses.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'branches') return ok(res, branches.map(b => ({ id: b.id, name: b.name })));
    if (action === 'list') return ok(res, expenses);
    if (action === 'summary') return ok(res, []);
    if (action === 'suppliers') return ok(res, expenseSuppliers);
    if (action === 'budgets') return ok(res, expenseBudgets);
    if (action === 'recurring_list') return ok(res, expenseRecurring);
    if (action === 'create') {
      const id = genId();
      expenses.unshift({ id, ...(req.body || {}), status: 'pending' });
      return ok(res, { id }, 'Expense recorded.', 201);
    }
    if (action === 'approve') {
      const e = expenses.find(x => x.id === Number(req.query.id));
      if (e) e.status = 'approved';
      return ok(res, { id: Number(req.query.id), status: 'approved' });
    }
    if (action === 'reject') {
      const e = expenses.find(x => x.id === Number(req.query.id));
      if (e) e.status = 'rejected';
      return ok(res, { id: Number(req.query.id), status: 'rejected' });
    }
    if (action === 'pay') {
      const e = expenses.find(x => x.id === Number(req.query.id));
      if (e) e.status = 'paid';
      return ok(res, { id: Number(req.query.id), status: 'paid' });
    }
    if (action === 'supplier_create') {
      const id = genId();
      expenseSuppliers.unshift({ id, ...(req.body || {}) });
      return ok(res, { id }, 'Supplier created.', 201);
    }
    if (action === 'budget_save') {
      const id = genId();
      expenseBudgets.unshift({ id, ...(req.body || {}) });
      return ok(res, { id });
    }
    if (action === 'recurring_create') {
      const id = genId();
      expenseRecurring.unshift({ id, ...(req.body || {}) });
      return ok(res, { id });
    }
    if (action === 'recurring_generate') return ok(res, { created_count: 0, skipped_count: 0, expense_month: req.body?.expense_month || '' });
    return ok(res, { id: Number(req.query.id || 0) });
  });

  router.all('/commissions.php', (req, res) => {
    const action = String(req.query.action || 'summary');
    if (action === 'summary') {
      return ok(res, {
        balance: { gross_commission: 45000, paid_commission: 30000, reserved_payout: 0, available_balance: 15000 },
        courses: courses.slice(0, 3).map(c => ({ course_id: c.id, course_title: c.title, commission_rate: 25, earned_amount: 15000 })),
      });
    }
    if (action === 'rules') return ok(res, commissionRules);
    if (action === 'set_rule') {
      const id = genId();
      commissionRules.unshift({ id, ...(req.body || {}) });
      return ok(res, { id });
    }
    if (action === 'payouts') return ok(res, payoutRequests);
    if (action === 'request_payout') {
      const id = genId();
      payoutRequests.unshift({ id, ...(req.body || {}), status: 'pending' });
      return ok(res, { id }, 'Payout requested.', 201);
    }
    return ok(res, { id: Number(req.query.id || 0), status: action });
  });

  router.all('/reports.php', (req, res) => {
    const type = String(req.query.type || 'revenue');
    const period = String(req.query.period || 'current_month');
    return ok(res, { type, period, rows: [] });
  });

  // --- 9. Storefront, Media, Notifications, Notices, Promotions, Marketing, SEO ---
  router.all('/storefront.php', (req, res) => {
    const action = String(req.query.action || '');
    if (action === 'ebook_files') return ok(res, ebookFiles);
    if (action === 'ebook_upload') {
      const ebook_id = String(req.body?.ebook_id || 'eb_1');
      ebookFiles.push({ ebook_id, original_filename: 'ebook.pdf', size_bytes: 102400, uploaded_at: new Date().toISOString() });
      return ok(res, { ebook_id, original_filename: 'ebook.pdf', size_bytes: 102400, sha256: 'demo' });
    }
    if (action === 'ebook_download') return fail(res, 'E-book PDF downloads require uploaded files on the production server.', 404);
    if (action === 'my_ebooks') return ok(res, { ebook_ids: [], available_file_ids: ebookFiles.map(f => f.ebook_id), orders: ebookOrders });
    if (action === 'ebook_submit') {
      const id = genId();
      ebookOrders.unshift({ id, ...(req.body || {}), status: 'pending', amount: '299.00' });
      return ok(res, { id, status: 'pending', amount: '299.00' }, 'E-book order submitted.', 201);
    }
    if (action === 'ebook_orders') return ok(res, ebookOrders);
    if (action === 'ebook_verify' || action === 'ebook_reject') {
      const o = ebookOrders.find(x => x.id === Number(req.query.id));
      if (o) o.status = action === 'ebook_verify' ? 'verified' : 'rejected';
      return ok(res, { id: Number(req.query.id), status: o?.status || 'updated' });
    }
    if (action === 'product_submit') {
      const id = genId();
      productOrders.unshift({ id, ...(req.body || {}), status: 'pending', total_amount: '950.00' });
      return ok(res, { id, status: 'pending', total_amount: '950.00' }, 'Product order submitted.', 201);
    }
    if (action === 'product_orders') return ok(res, productOrders);
    if (action === 'product_update') {
      const o = productOrders.find(x => x.id === Number(req.query.id));
      if (o) o.status = String(req.body?.status || 'confirmed');
      return ok(res, { id: Number(req.query.id), status: o?.status || 'confirmed' });
    }
    return ok(res, []);
  });

  router.all('/media.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, mediaFiles);
    if (action === 'trash') return ok(res, mediaTrash);
    if (action === 'upload') {
      const id = genId();
      mediaFiles.unshift({ id, content_item_id: Number(req.body?.content_item_id || 1), original_filename: 'resource.pdf', mime_type: 'application/pdf', size_bytes: 2048, sha256: 'demo', uploaded_at: new Date().toISOString() });
      return ok(res, { id, content_item_id: Number(req.body?.content_item_id || 1), filename: 'resource.pdf', mime_type: 'application/pdf', size_bytes: 2048, download_url: '#' });
    }
    return ok(res, { id: Number(req.body?.id || 0) });
  });

  router.all('/notifications.php', (req, res) => {
    const auth = requireAuth(req, res);
    if (!auth) return;
    const action = String(req.query.action || 'list');
    if (action === 'list') {
      const unread_count = notifications.filter(n => !n.read_at).length;
      return ok(res, { items: notifications, unread_count });
    }
    if (action === 'mark_read') {
      const n = notifications.find(x => x.id === Number(req.query.id));
      if (n) n.read_at = new Date().toISOString();
      return ok(res, { id: Number(req.query.id), is_read: true });
    }
    if (action === 'mark_all_read') {
      let updated = 0;
      for (const n of notifications) {
        if (!n.read_at) { n.read_at = new Date().toISOString(); updated++; }
      }
      return ok(res, { updated });
    }
    if (action === 'delete') {
      const idx = notifications.findIndex(x => x.id === Number(req.query.id));
      if (idx !== -1) notifications.splice(idx, 1);
      return ok(res, { id: Number(req.query.id) });
    }
    if (action === 'broadcast') {
      const id = genId();
      notifications.unshift({
        id,
        title: String(req.body?.title || ''),
        title_bn: String(req.body?.title_bn || ''),
        message: String(req.body?.message || ''),
        message_bn: String(req.body?.message_bn || ''),
        type: String(req.body?.type || 'info'),
        target_role: String(req.body?.target_role || 'all'),
        read_at: null,
        created_at: new Date().toISOString(),
      });
      return ok(res, { recipient_count: users.length, target_role: String(req.body?.target_role || 'all') }, 'Notification sent.');
    }
    return ok(res, null);
  });

  router.all('/notices.php', (req, res) => {
    const action = String(req.query.action || 'visible');
    if (action === 'visible') return ok(res, notices.filter(n => n.status === 'published'));
    if (action === 'list') return ok(res, notices);
    if (action === 'create') {
      const id = genId();
      notices.unshift({ id, ...(req.body || {}), status: 'published', is_read: false, created_at: new Date().toISOString() });
      return ok(res, { id, status: 'published' }, 'Notice published.', 201);
    }
    if (action === 'update') {
      const n = notices.find(x => x.id === Number(req.query.id));
      if (n) Object.assign(n, req.body || {});
      return ok(res, { id: Number(req.query.id), status: n?.status || 'published' });
    }
    if (action === 'status') {
      const n = notices.find(x => x.id === Number(req.query.id));
      if (n) n.status = String(req.body?.status || 'published');
      return ok(res, { id: Number(req.query.id), status: n?.status || 'published' });
    }
    if (action === 'mark_read') return ok(res, { notice_id: Number(req.body?.notice_id || 0), read: true });
    return ok(res, []);
  });

  router.all('/promotions.php', (req, res) => {
    const action = String(req.query.action || 'active');
    if (action === 'active') return ok(res, promotions.filter(p => p.status === 'active'));
    if (action === 'list') return ok(res, promotions);
    if (action === 'create') {
      const id = genId();
      promotions.unshift({ id, ...(req.body || {}), status: 'active', impressions: 0, clicks: 0, conversions: 0 });
      return ok(res, { id, status: 'active' }, 'Campaign created.', 201);
    }
    if (action === 'status') {
      const p = promotions.find(x => x.id === Number(req.query.id));
      if (p) p.status = String(req.body?.status || 'active');
      return ok(res, { id: Number(req.query.id), status: p?.status || 'active' });
    }
    if (action === 'track') return ok(res, { tracked: true });
    return ok(res, []);
  });

  router.all('/marketing-settings.php', (req, res) => {
    const action = String(req.query.action || 'public');
    if (action === 'public') return ok(res, marketingSettings);
    if (action === 'admin_get') return ok(res, { ...marketingSettings, emailDeliveryConfigured: false, smsGatewayConfigured: false });
    if (action === 'save') {
      marketingSettings = { ...marketingSettings, ...(req.body?.settings || {}) };
      return ok(res, { settings: marketingSettings }, 'Marketing and SEO settings saved.');
    }
    return ok(res, {});
  });

  router.all('/seo.php', (_req, res) => {
    return ok(res, {
      title: marketingSettings.siteTitleSeo || "Alom's English Center | Learn with Purpose",
      description: marketingSettings.siteMetaDescription || "Alom's English Center — structured English learning, expert guidance, practical assessments and measurable progress.",
      canonical: marketingSettings.canonicalUrl || '',
      googleVerification: marketingSettings.googleSearchConsoleToken || '',
      ga4Id: marketingSettings.googleAnalyticsId || '',
      gtmId: marketingSettings.googleTagManagerId || '',
      metaPixelId: marketingSettings.metaPixelId || '',
    });
  });

  // --- 10. Plugins, SaaS, Phone Verification, AI Copilot ---
  router.all('/plugin-updater.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, plugins);
    if (action === 'toggle') {
      const p = plugins.find(x => x.id === String(req.body?.id));
      if (p) p.enabled = Boolean(req.body?.enabled);
      return ok(res, p || {});
    }
    return ok(res, { id: String(req.body?.id || 'plugin') });
  });

  router.all('/saas-tenants.php', (req, res) => {
    const action = String(req.query.action || 'list');
    if (action === 'list') return ok(res, saasTenants);
    if (action === 'plans') return ok(res, saasPlans);
    if (action === 'create') {
      const id = genId();
      saasTenants.unshift({ id, tenant_key: String(req.body?.tenant_key || `tnt-${id}`), name: String(req.body?.name || ''), city: String(req.body?.city || ''), plan_key: String(req.body?.plan_key || 'starter'), status: 'active', domain: String(req.body?.domain || ''), created_at: new Date().toISOString().slice(0, 10) });
      return ok(res, { id, tenant_key: String(req.body?.tenant_key || ''), status: 'active', provisioned: true }, 'Tenant created.', 201);
    }
    if (action === 'status') {
      const t = saasTenants.find(x => x.id === Number(req.query.id));
      if (t) t.status = String(req.body?.status || 'active');
      return ok(res, { id: Number(req.query.id), status: t?.status || 'active' });
    }
    return ok(res, []);
  });

  router.all('/phone-verification.php', (req, res) => {
    const action = String(req.query.action || '');
    if (action === 'request') return ok(res, { phone: String(req.body?.phone || ''), expires_in_seconds: 300 });
    if (action === 'test') return ok(res, { sent: false }, 'SMS gateway is not configured in local preview.');
    if (action === 'verify') return ok(res, { verified: true });
    return ok(res, null);
  });

  router.all('/ai-assistant.php', (_req, res) => {
    return ok(res, {
      reply: 'AI Admin Copilot requires a configured server-side AI API key in production. In this local preview, no external AI requests were made.',
      proposal: null,
      mode: 'approval_required',
    });
  });

  router.all('/ai-actions.php', (_req, res) => {
    return fail(res, 'AI Admin action execution requires a configured server-side AI API key.', 503);
  });

  router.use((_req: Request, res: Response, _next: NextFunction) => {
    fail(res, 'API endpoint not found.', 404);
  });

  return router;
}
